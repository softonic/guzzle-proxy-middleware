<?php

namespace Softonic\Proxy\Guzzle\Middleware\Repositories;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;

class SslPrivateProxyTest extends TestCase
{
    /**
     * @var GuzzleClient
     */
    private $client;

    /**
     * @var SslPrivateProxy
     */
    private $sslPrivateProxy;

    public function setUp()
    {
        parent::setUp();

        $this->client = \Mockery::mock(GuzzleClient::class);

        $this->sslPrivateProxy = new SslPrivateProxy($this->client, 'apikey');
    }

    /**
     * @test
     */
    public function whenProxyIsUnavailableDueToAnExceptionItShouldThrowAnException()
    {
        $this->client->shouldReceive('get')
            ->once()
            ->with('https://www.sslprivateproxy.com/api/v1/apikey/getrandomproxy/')
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
            ->with('https://www.sslprivateproxy.com/api/v1/apikey/getrandomproxy/')
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
            ->with('https://www.sslprivateproxy.com/api/v1/apikey/getrandomproxy/')
            ->andReturn(new Response(200, [], 'ip:port:user:pass'));

        $result = $this->sslPrivateProxy->get();

        $this->assertEquals('http://user:pass@ip:port', $result);
    }
}
