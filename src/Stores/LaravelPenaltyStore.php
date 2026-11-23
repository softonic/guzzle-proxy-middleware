<?php

namespace Softonic\Proxy\Guzzle\Middleware\Stores;

use Illuminate\Contracts\Cache\Repository;
use Softonic\Proxy\Guzzle\Middleware\Interfaces\PenaltyStore;

/**
 * Keeps the penalties in a Laravel cache store. Use a store shared by every worker, such as redis;
 * its add() is atomic.
 */
final class LaravelPenaltyStore implements PenaltyStore
{
    public function __construct(private readonly Repository $cache)
    {
    }

    public function add(string $key, int $value, int $seconds): bool
    {
        return $this->cache->add($key, $value, $seconds);
    }

    public function get(string $key): ?int
    {
        return self::toInt($this->cache->get($key));
    }

    public function put(string $key, int $value, int $seconds): void
    {
        $this->cache->put($key, $value, $seconds);
    }

    public function many(array $keys): array
    {
        return array_map(self::toInt(...), $this->cache->many($keys));
    }

    /**
     * Redis hands numbers back as strings.
     */
    private static function toInt(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }
}
