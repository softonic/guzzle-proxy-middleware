<?php

namespace Softonic\Proxy\Guzzle\Middleware\Repositories;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;

class ProxyBonanzaTest extends TestCase
{
    /**
     * @test
     */
    public function whenProxyListIsUnavailableItShouldThrowAnException()
    {
        $mockCache     = $this->createMock(\Psr\Cache\CacheItemPoolInterface::class);
        $mockCacheItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $mockCache->expects($this->once())
            ->method('getItem')
            ->with('proxy_bonanza_list')
            ->willReturn($mockCacheItem);
        $mockCacheItem->expects($this->once())
            ->method('isHit')
            ->willReturn(false);

        $handler = new MockHandler(
            [
                new RequestException('Error communicating with the server', new Request('GET', 'test')),
            ]
        );
        $client  = new GuzzleClient(['handler' => $handler]);

        $this->expectException(ProxiesNotAvailable::class);
        $this->expectExceptionMessage('Error communicating with the server');

        $proxy = new ProxyBonanza($client, $mockCache, '1234', 'ABCD');
        $proxy->get();
    }

    /**
     * @test
     */
    public function whenProxyListIsAvailableItShouldCacheTheResponseAndReturnARandomProxy()
    {
        $apiJsonResponse = <<<'JSON'
{
  "success": true,
  "data": {
    "id": 212121,
    "login": "login11",
    "password": "pass11",
    "expires": "2015-02-06T00:00:00+0000",
    "bandwidth": 34192444911,
    "last_ip_change": "2014-11-10T00:00:00+0000",
    "ippacks": [
      {
        "ip": "99.22.11.22",
        "port_http": 45623,
        "port_socks": 46860,
        "proxyserver": {
          "georegion_id": 18,
          "georegion": {
            "name": "New York",
            "country": {
              "isocode": "US",
              "name": "USA"
            }
          }
        }
      }
    ],
    "authips": [
      {
        "id": 3112222,
        "ip": "33.33.44.22",
        "userpackage_id": 212121
      }
    ],
    "package": {
      "name": "Special 3",
      "bandwidth": 10737418240,
      "price": 5,
      "howmany_ips": 1,
      "price_per_gig": 1,
      "package_type": "exclusive"
    }
  }
}
JSON;

        $mockCache     = $this->createMock(\Psr\Cache\CacheItemPoolInterface::class);
        $mockCacheItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $mockCache->expects($this->once())
            ->method('getItem')
            ->with('proxy_bonanza_list')
            ->willReturn($mockCacheItem);
        $mockCache->expects($this->once())
            ->method('save')
            ->with($mockCacheItem);
        $mockCacheItem->expects($this->once())
            ->method('isHit')
            ->willReturn(false);
        $mockCacheItem->expects($this->once())
            ->method('set')
            ->with(['http://login11:pass11@99.22.11.22:45623']);
        $mockCacheItem->expects($this->once())
            ->method('expiresAfter')
            ->with(ProxyBonanza::CACHE_TTL);

        $handler = new MockHandler(
            [
                new Response(200, [], $apiJsonResponse),
            ]
        );
        $client  = new GuzzleClient(['handler' => $handler]);

        $proxy    = new ProxyBonanza($client, $mockCache, '1234', 'ABCD');
        $proxyUrl = $proxy->get();
        $this->assertEquals(
            'http://login11:pass11@99.22.11.22:45623',
            $proxyUrl
        );
    }

    /**
     * @test
     */
    public function whenProxyListIsNotAvailableDueToTheResponseItShouldThrowAnException()
    {
        $apiJsonResponse = <<<'JSON'
{
  "success": false
}
JSON;

        $mockCache     = $this->createMock(\Psr\Cache\CacheItemPoolInterface::class);
        $mockCacheItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $mockCache->expects($this->once())
            ->method('getItem')
            ->with('proxy_bonanza_list')
            ->willReturn($mockCacheItem);
        $mockCacheItem->expects($this->once())
            ->method('isHit')
            ->willReturn(false);

        $handler = new MockHandler(
            [
                new Response(200, [], $apiJsonResponse),
            ]
        );
        $client  = new GuzzleClient(['handler' => $handler]);

        $this->expectException(ProxiesNotAvailable::class);
        $this->expectExceptionMessage('Proxy response was not successful');

        $proxy = new ProxyBonanza($client, $mockCache, '1234', 'ABCD');
        $proxy->get();
    }

    /**
     * @test
     */
    public function whenProxyListIsInCacheItShouldNotRefreshTheProxyList()
    {
        $mockCache     = $this->createMock(\Psr\Cache\CacheItemPoolInterface::class);
        $mockCacheItem = $this->createMock(\Psr\Cache\CacheItemInterface::class);
        $mockCache->expects($this->once())
            ->method('getItem')
            ->with('proxy_bonanza_list')
            ->willReturn($mockCacheItem);
        $mockCache->expects($this->never())
            ->method('save');
        $mockCacheItem->expects($this->once())
            ->method('isHit')
            ->willReturn(true);
        $mockCacheItem->expects($this->once())
            ->method('get')
            ->willReturn(['http://login11:pass11@99.22.11.22:45623']);

        $handler = new MockHandler(
            [
                new RequestException('Guzzle MUST not be called in this test', new Request('GET', 'test')),
            ]
        );
        $client  = new GuzzleClient(['handler' => $handler]);

        $proxy    = new ProxyBonanza($client, $mockCache, '1234', 'ABCD');
        $proxyUrl = $proxy->get();
        $this->assertEquals(
            'http://login11:pass11@99.22.11.22:45623',
            $proxyUrl
        );
    }
}
