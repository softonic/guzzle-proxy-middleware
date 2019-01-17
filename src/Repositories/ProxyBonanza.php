<?php

namespace Softonic\Proxy\Guzzle\Middleware\Repositories;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Cache\CacheItemPoolInterface;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;
use Softonic\Proxy\Guzzle\Middleware\Interfaces\ProxyInterface;
use Softonic\Proxy\Guzzle\Middleware\Traits\CacheProxiesList;

class ProxyBonanza implements ProxyInterface
{
    use CacheProxiesList;

    const PROXY_BONANZA_API = 'https://api.proxybonanza.com/v1';

    const CACHE_KEY = 'proxy_bonanza_list';

    /**
     * 4 hours.
     */
    const CACHE_TTL = 14400;

    /**
     * @var GuzzleClient
     */
    private $client;

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    /**
     * @var string
     */
    private $userPackage;

    /**
     * @var string
     */
    private $apiKey;

    public function __construct(
        GuzzleClient $client,
        CacheItemPoolInterface $cache,
        string $userPackage,
        string $apiKey
    ) {
        $this->client      = $client;
        $this->cache       = $cache;
        $this->userPackage = $userPackage;
        $this->apiKey      = $apiKey;
    }

    public function get()
    {
        return $this->getProxyUsingCache();
    }

    protected function getFreshProxyList(): array
    {
        $response = $this->client->request(
            'GET',
            self::PROXY_BONANZA_API . "/userpackages/{$this->userPackage}.json",
            ['headers' => ['Authorization' => $this->apiKey]]
        );

        $body = json_decode($response->getBody(), true);

        if (!$body['success']) {
            throw new ProxiesNotAvailable('Proxy response was not successful');
        }

        $login    = $body['data']['login'];
        $password = $body['data']['password'];

        $proxiesList = [];
        foreach ($body['data']['ippacks'] as $ippack) {
            $proxiesList[] = "http://{$login}:{$password}@{$ippack['ip']}:{$ippack['port_http']}";
        }

        return $proxiesList;
    }
}
