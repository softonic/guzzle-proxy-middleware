<?php

namespace Softonic\Proxy\Guzzle\Middleware\Repositories;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Cache\CacheItemPoolInterface;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;
use Softonic\Proxy\Guzzle\Middleware\Interfaces\ProxyInterface;
use Softonic\Proxy\Guzzle\Middleware\Traits\CacheProxiesList;

class SslPrivateProxy implements ProxyInterface
{
    use CacheProxiesList;

    const SSLPRIVATEPROXY_API = 'https://www.sslprivateproxy.com/api/v1/';

    const SSLPRIVATEPROXY_API_ENDPOINT = '/listproxies/';

    const CACHE_KEY = 'ssl_private_proxy_list';

    /**
     * 4 hours.
     */
    const CACHE_TTL = 14400;

    public function __construct(
        private readonly GuzzleClient           $client,
        private readonly CacheItemPoolInterface $cache,
        private readonly string                 $apiKey
    ) {
    }

    public function get()
    {
        return $this->getProxyUsingCache();
    }

    protected function getFreshProxyList(): array
    {
        $response = $this->client->get(
            self::SSLPRIVATEPROXY_API . $this->apiKey . self::SSLPRIVATEPROXY_API_ENDPOINT
        );

        $responseContent = $response->getBody()->getContents();

        if (empty($responseContent)) {
            throw new ProxiesNotAvailable('Proxy response was not successful');
        }

        $proxiesData = explode("\n", $responseContent);

        $proxiesList = [];
        foreach ($proxiesData as $proxyData) {
            if (!empty($proxyData)) {
                list($ip, $port, $username, $password) = explode(':', $proxyData);

                $proxiesList[] = "http://$username:$password@$ip:$port";
            }
        }

        return $proxiesList;
    }
}
