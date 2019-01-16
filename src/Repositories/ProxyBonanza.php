<?php

namespace Softonic\Proxy\Guzzle\Middleware\Repositories;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Cache\CacheItemPoolInterface;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;
use Softonic\Proxy\Guzzle\Middleware\Interfaces\ProxyInterface;

class ProxyBonanza implements ProxyInterface
{
    const PROXY_BONANZA_API = 'https://api.proxybonanza.com/v1';

    const CACHE_KEY = 'proxy_list';

    /**
     * @var GuzzleClient
     */
    private $client;

    /**
     * @var string
     */
    private $userPackage;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var CacheItemPoolInterface
     */
    private $cache;

    const FOUR_HOURS = 14400;

    public function __construct(
        GuzzleClient $client,
        CacheItemPoolInterface $cache,
        string $userPackage,
        string $apiKey
    ) {
        $this->client      = $client;
        $this->userPackage = $userPackage;
        $this->apiKey      = $apiKey;
        $this->cache       = $cache;
    }

    public function get()
    {
        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            if (!$item->isHit()) {
                $proxyList = $this->getFreshProxyList();
                $item->set($proxyList);
                $item->expiresAfter(self::FOUR_HOURS);
                $this->cache->save($item);
            } else {
                $proxyList = $item->get();
            }

            return $proxyList[random_int(0, count($proxyList) - 1)];
        } catch (\Exception $e) {
            throw new ProxiesNotAvailable($e->getMessage(), 0, $e);
        }
    }

    private function getFreshProxyList(): array
    {
        $response = $this->client->request(
            'GET',
            self::PROXY_BONANZA_API . "/userpackages/{$this->userPackage}.json",
            ['headers' => ['Authorization' => $this->apiKey]]
        );
        $body     = json_decode($response->getBody(), true);

        if (!$body['success']) {
            throw new ProxiesNotAvailable('Proxy response was not successful');
        }

        $login     = $body['data']['login'];
        $password  = $body['data']['password'];
        $proxyList = [];
        foreach ($body['data']['ippacks'] as $ippack) {
            $proxyList[] = "http://{$login}:{$password}@{$ippack['ip']}:{$ippack['port_http']}";
        }

        return $proxyList;
    }
}
