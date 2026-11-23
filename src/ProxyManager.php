<?php

namespace Softonic\Proxy\Guzzle\Middleware;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;
use Psr\SimpleCache\CacheInterface;
use Softonic\Proxy\Guzzle\Middleware\Interfaces\ProxyInterface;

class ProxyManager
{
    private const QUARANTINE_AFTER_FAILURES = 3;

    private const PENALTY_SECONDS = [3 => 3 * 3600, 4 => 24 * 3600, 5 => 48 * 3600];

    private const BAN_SECONDS = 30 * 24 * 3600;

    private const FORGET_FAILURES_AFTER_SECONDS = 30 * 24 * 3600;

    private const PICKS = 5;

    private const CURLE_COULDNT_CONNECT = 7;

    private const CURLE_OPERATION_TIMEDOUT = 28;

    /**
     * @var ProxyInterface
     */
    private $proxy;

    /**
     * @var CacheInterface|null
     */
    private $cache;

    public function __construct(ProxyInterface $proxy, ?CacheInterface $cache = null)
    {
        $this->proxy = $proxy;
        $this->cache = $cache;
    }

    public function __invoke(callable $handler)
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            if ($this->cache === null) {
                $options['proxy'] = $this->proxy->get();

                return $handler($request, $options);
            }

            [$proxy, $failures] = $this->pick();
            $options['proxy'] = $proxy;

            return $handler($request, $options)->then(
                function ($response) use ($proxy, $failures) {
                    $this->forgive($proxy, $failures);

                    return $response;
                },
                function ($reason) use ($proxy, $failures) {
                    self::isProxyUnreachable($reason) ? $this->punish($proxy) : $this->forgive($proxy, $failures);

                    return Create::rejectionFor($reason);
                }
            );
        };
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function pick(): array
    {
        // shortcut: samples PICKS proxies instead of reading the whole list, so a pool mostly in quarantine still gets a quarantined one; read the list if that happens.
        for ($pick = 1; ; $pick++) {
            $proxy = $this->proxy->get();
            [$failures, $quarantinedUntil] = $this->cache->get(self::key($proxy), [0, 0]);

            if ($quarantinedUntil <= time() || $pick === self::PICKS) {
                return [$proxy, $failures];
            }
        }
    }

    private function punish(string $proxy): void
    {
        // shortcut: read then write, so two workers failing on one proxy at once may count once; a dead proxy keeps failing anyway.
        [$failures, $quarantinedUntil] = $this->cache->get(self::key($proxy), [0, 0]);

        // Requests that picked the proxy before its quarantine started must not escalate it.
        if ($quarantinedUntil > time()) {
            return;
        }

        $failures++;
        $penalty = $failures < self::QUARANTINE_AFTER_FAILURES ? 0 : self::PENALTY_SECONDS[$failures] ?? self::BAN_SECONDS;

        $this->cache->set(self::key($proxy), [$failures, time() + $penalty], $penalty + self::FORGET_FAILURES_AFTER_SECONDS);
    }

    private function forgive(string $proxy, int $failures): void
    {
        if ($failures > 0) {
            $this->cache->delete(self::key($proxy));
        }
    }

    /**
     * Hashed so that the proxy's credentials stay out of the cache and the key only uses PSR-16 safe characters.
     */
    private static function key(string $proxy): string
    {
        return 'proxy_quarantine.' . hash('xxh128', $proxy);
    }

    /**
     * Through a proxy curl only ever connects to the proxy, so a connection that never completed is the proxy's fault.
     * connect_time is also 0 on a reused connection: request_size 0 is what proves nothing reached the proxy.
     */
    private static function isProxyUnreachable(mixed $reason): bool
    {
        if (!$reason instanceof ConnectException) {
            return false;
        }

        $context = $reason->getHandlerContext();

        return in_array($context['errno'] ?? null, [self::CURLE_COULDNT_CONNECT, self::CURLE_OPERATION_TIMEDOUT], true)
            && isset($context['connect_time'], $context['request_size'])
            && (float)$context['connect_time'] === 0.0
            && $context['request_size'] === 0;
    }
}
