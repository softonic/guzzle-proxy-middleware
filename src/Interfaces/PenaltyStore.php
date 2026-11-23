<?php

namespace Softonic\Proxy\Guzzle\Middleware\Interfaces;

/**
 * Where proxy penalties are kept. It must be shared by every worker that sends requests through the
 * same pool, or each worker would have to find out by itself which proxies are dead.
 */
interface PenaltyStore
{
    /**
     * Stores the value only if the key does not exist yet. It must be atomic across workers: it is
     * what keeps concurrent failures of the same proxy from counting as several relapses.
     */
    public function add(string $key, int $value, int $seconds): bool;

    public function get(string $key): ?int;

    public function put(string $key, int $value, int $seconds): void;

    /**
     * @param list<string> $keys
     *
     * @return array<string, int|null>
     */
    public function many(array $keys): array;
}
