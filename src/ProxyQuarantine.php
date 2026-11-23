<?php

namespace Softonic\Proxy\Guzzle\Middleware;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;
use Softonic\Proxy\Guzzle\Middleware\Interfaces\PenaltyStore;

/**
 * Keeps unreachable proxies out of rotation.
 *
 * A proxy that cannot be reached is quarantined. If it fails again once its quarantine is over, the
 * next quarantine is longer, and after the last step it is banned for BAN_SECONDS. The ban expires
 * rather than lasting for ever because providers repair proxies and hand addresses out again, and
 * because a permanent key would never leave a store without eviction. The strike count lives in its
 * own key, which outlives the penalty so a relapse is recognised (a proxy that fails again after its
 * ban is banned again at once), and is forgotten STRIKES_TTL after the penalty ends without failures.
 *
 * A failure while the proxy is already quarantined is not a relapse: concurrent workers that picked
 * the same dead proxy before it was quarantined must not escalate it straight to a ban.
 */
final class ProxyQuarantine
{
    /**
     * Quarantine length in seconds, by strike number. Any strike past the last step is a ban.
     */
    private const QUARANTINE_SECONDS = [
        1 => 3 * 3600,
        2 => 24 * 3600,
        3 => 48 * 3600,
    ];

    private const BAN_SECONDS = 30 * 24 * 3600;

    private const STRIKES_TTL = 30 * 24 * 3600;

    private const KEY_PREFIX = 'proxy-quarantine:';

    public function __construct(
        private readonly PenaltyStore $store,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Picks a random proxy that is neither quarantined nor banned.
     *
     * When every proxy is quarantined, one of the least penalised (fewest strikes) is used instead of
     * failing. That many proxies dying together points at our own network or a provider-wide outage
     * rather than at the proxies; failing closed would block every proxied request until the first
     * quarantine expires, hours after the outage is over. A failure of the chosen proxy does not
     * escalate it, since it is still quarantined.
     *
     * @param list<string> $proxies
     *
     * @throws ProxiesNotAvailable When the list is empty or every proxy is banned.
     */
    public function pick(array $proxies): string
    {
        if ($proxies === []) {
            throw new ProxiesNotAvailable('The proxy list is empty');
        }

        $quarantined = [];
        $available   = [];
        $penalties   = $this->penalties($proxies);

        foreach ($proxies as $proxy) {
            $penalty = $penalties[self::identify($proxy)];

            if ($penalty['banned']) {
                continue;
            }

            if ($penalty['strikes'] === null) {
                $available[] = $proxy;
            } else {
                $quarantined[$proxy] = $penalty['strikes'];
            }
        }

        if ($available !== []) {
            return $available[random_int(0, count($available) - 1)];
        }

        if ($quarantined === []) {
            throw new ProxiesNotAvailable(sprintf('All %d proxies are banned', count($proxies)));
        }

        $leastPenalized = array_keys($quarantined, min($quarantined), true);

        $this->logger->warning('Every proxy is quarantined, using one of the least penalised', [
            'quarantined' => count($quarantined),
            'banned'      => count($proxies) - count($quarantined),
            'strikes'     => min($quarantined),
        ]);

        return $leastPenalized[random_int(0, count($leastPenalized) - 1)];
    }

    /**
     * Records a failure to connect to the proxy.
     *
     * @return int|null The proxy's strike count, or null when it was already quarantined and the
     *                  failure does not count.
     */
    public function punish(string $proxy): ?int
    {
        $id = self::identify($proxy);

        // The atomic add is the lock: only the worker that sets it counts the strike. Its value and
        // length are provisional until the strike count is known.
        if (!$this->store->add($this->quarantineKey($id), 1, self::QUARANTINE_SECONDS[1])) {
            return null;
        }

        $strikes = ($this->store->get($this->strikesKey($id)) ?? 0) + 1;
        $seconds = self::penaltySeconds($strikes);

        $this->store->put($this->strikesKey($id), $strikes, $seconds + self::STRIKES_TTL);
        $this->store->put($this->quarantineKey($id), $strikes, $seconds);

        if (self::isBan($strikes)) {
            $this->store->put($this->banKey($id), $strikes, $seconds);
        }

        return $strikes;
    }

    /**
     * How long the proxy is kept out of rotation for that strike, quarantine or ban.
     */
    public static function penaltySeconds(int $strikes): int
    {
        return self::QUARANTINE_SECONDS[$strikes] ?? self::BAN_SECONDS;
    }

    public static function isBan(int $strikes): bool
    {
        return $strikes > array_key_last(self::QUARANTINE_SECONDS);
    }

    /**
     * The proxy's address without its credentials, which must not reach logs or store keys.
     */
    public static function identify(string $proxy): string
    {
        $parts = parse_url($proxy);

        if (!is_array($parts) || !isset($parts['host'])) {
            return hash('sha256', $proxy);
        }

        return $parts['host'] . (isset($parts['port']) ? ":{$parts['port']}" : '');
    }

    /**
     * Reads the whole list in a single round trip.
     *
     * @param list<string> $proxies
     *
     * @return array<string, array{strikes: int|null, banned: bool}> Keyed by proxy id.
     */
    private function penalties(array $proxies): array
    {
        $ids  = array_map(self::identify(...), $proxies);
        $keys = [];

        foreach ($ids as $id) {
            $keys[] = $this->quarantineKey($id);
            $keys[] = $this->banKey($id);
        }

        $values    = $this->store->many($keys);
        $penalties = [];

        foreach ($ids as $id) {
            $penalties[$id] = [
                'strikes' => $values[$this->quarantineKey($id)] ?? null,
                'banned'  => ($values[$this->banKey($id)] ?? null) !== null,
            ];
        }

        return $penalties;
    }

    private function quarantineKey(string $id): string
    {
        return self::KEY_PREFIX . "quarantined:{$id}";
    }

    private function strikesKey(string $id): string
    {
        return self::KEY_PREFIX . "strikes:{$id}";
    }

    private function banKey(string $id): string
    {
        return self::KEY_PREFIX . "banned:{$id}";
    }
}
