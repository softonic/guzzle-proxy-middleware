<?php

namespace Softonic\Proxy\Guzzle\Middleware;

use Psr\Http\Message\RequestInterface;
use Softonic\Proxy\Guzzle\Middleware\Repositories\ProxyBonanza;

class ProxyManager
{
    /**
     * @var Proxy
     */
    private $proxy;

    public function __construct(ProxyBonanza $proxy)
    {
        $this->proxy = $proxy;
    }

    public function __invoke(callable $handler)
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            $proxy            = $this->proxy->get();
            $options['proxy'] = $proxy;

            return $handler($request, $options);
        };
    }
}
