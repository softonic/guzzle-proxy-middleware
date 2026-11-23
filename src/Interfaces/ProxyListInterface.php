<?php

namespace Softonic\Proxy\Guzzle\Middleware\Interfaces;

use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;

interface ProxyListInterface
{
    /**
     * Every proxy of the pool, as http://user:password@host:port.
     *
     * @throws ProxiesNotAvailable
     *
     * @return list<string>
     */
    public function all(): array;
}
