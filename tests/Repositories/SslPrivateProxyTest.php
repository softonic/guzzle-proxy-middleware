<?php

namespace Softonic\Proxy\Guzzle\Middleware\Repositories;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;

class SslPrivateProxyTest extends TestCase
{
    private GuzzleClient $client;

    private SslPrivateProxy $sslPrivateProxy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Mockery::mock(GuzzleClient::class);

        $cacheItem = Mockery::mock(CacheItemInterface::class);
        $cacheItem->shouldReceive('isHit')
            ->once()
            ->andReturnFalse();
        $cacheItem->shouldReceive('set');
        $cacheItem->shouldReceive('expiresAfter');

        $cache = Mockery::mock(CacheItemPoolInterface::class);
        $cache->shouldReceive('getItem')
            ->once()
            ->with('ssl_private_proxy_list')
            ->andReturn($cacheItem);
        $cache->shouldReceive('save');


        $this->sslPrivateProxy = new SslPrivateProxy($this->client, $cache, 'apikey');
    }

    /**
     * @test
     */
    public function whenProxyIsUnavailableDueToAnExceptionItShouldThrowAnException()
    {
        $this->client->shouldReceive('get')
            ->once()
            ->with('https://www.sslprivateproxy.com/api/v1/apikey/listproxies/')
            ->andThrow(new RequestException('Error communicating with the server', new Request('GET', 'test')));

        $this->expectException(ProxiesNotAvailable::class);
        $this->expectExceptionMessage('Error communicating with the server');

        $this->sslPrivateProxy->get();
    }

    /**
     * @test
     */
    public function whenProxyIsNotAvailableDueToAnEmptyResponseItShouldThrowAnException()
    {
        $this->client->shouldReceive('get')
            ->once()
            ->with('https://www.sslprivateproxy.com/api/v1/apikey/listproxies/')
            ->andReturn(new Response(200, [], ''));

        $this->expectException(ProxiesNotAvailable::class);
        $this->expectExceptionMessage('Proxy response was not successful');

        $this->sslPrivateProxy->get();
    }

    /**
     * @test
     */
    public function whenProxyIsAvailableItShouldReturnIt()
    {
        $this->client->shouldReceive('get')
            ->once()
            ->with('https://www.sslprivateproxy.com/api/v1/apikey/listproxies/')
            ->andReturn(new Response(200, [], 'ip:port:user:pass'));

        $result = $this->sslPrivateProxy->get();

        $this->assertEquals('http://user:pass@ip:port', $result);
    }

    /**
     * @test
     */
    public function whenProxyListHasSeveralLinesItShouldSkipBlankOnesAndIgnoreLineEndings()
    {
        $this->client->shouldReceive('get')
            ->once()
            ->with('https://www.sslprivateproxy.com/api/v1/apikey/listproxies/')
            ->andReturn(new Response(200, [], "1.2.3.4:8080:user:pass\r\n\r\n5.6.7.8:8080:user2:pass2\r\n"));

        $result = $this->sslPrivateProxy->get();

        $this->assertContains($result, ['http://user:pass@1.2.3.4:8080', 'http://user2:pass2@5.6.7.8:8080']);
    }

    /**
     * @test
     */
    public function whenProxyListHasALineWithTooManyFieldsItShouldThrowAnException()
    {
        $this->client->shouldReceive('get')
            ->once()
            ->with('https://www.sslprivateproxy.com/api/v1/apikey/listproxies/')
            ->andReturn(new Response(200, [], 'body{margin:0;padding:0;font-family:x;background:#fff}'));

        $this->expectException(ProxiesNotAvailable::class);
        $this->expectExceptionMessage(
            'Unexpected proxy list line "body{margin:0;padding:0;font-family:x;background:#fff}"'
        );

        $this->sslPrivateProxy->get();
    }

    /**
     * @test
     */
    public function whenProxyListHasAMalformedLineItShouldThrowAnException()
    {
        $this->client->shouldReceive('get')
            ->once()
            ->with('https://www.sslprivateproxy.com/api/v1/apikey/listproxies/')
            ->andReturn(new Response(200, [], "<!DOCTYPE html>\n<html>Too many requests</html>"));

        $this->expectException(ProxiesNotAvailable::class);
        $this->expectExceptionMessage('Unexpected proxy list line "<!DOCTYPE html>"');

        $this->sslPrivateProxy->get();
    }

    /**
     * @test
     */
    public function whenProxyListHasALineWithMissingFieldsItShouldThrowAnException()
    {
        $this->client->shouldReceive('get')
            ->once()
            ->with('https://www.sslprivateproxy.com/api/v1/apikey/listproxies/')
            ->andReturn(new Response(200, [], "ip:port:user:pass\nip:port:user"));

        $this->expectException(ProxiesNotAvailable::class);
        $this->expectExceptionMessage('Unexpected proxy list line "ip:port:user"');

        $this->sslPrivateProxy->get();
    }
}
