<?php

namespace Softonic\Proxy\Guzzle\Middleware;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;
use Softonic\Proxy\Guzzle\Middleware\Interfaces\ProxyInterface;

class ProxyManagerTest extends TestCase
{
    private const A = 'http://user:pass@10.0.0.1:8080';

    private const B = 'http://user:pass@10.0.0.2:8080';

    private Repository $cache;

    private array $nextProxies = [];

    private ProxyManager $proxyManager;

    protected function setUp(): void
    {
        $this->cache        = new Repository(new ArrayStore());
        $proxies            = Mockery::mock(ProxyInterface::class);
        $proxies->shouldReceive('get')->andReturnUsing(
            fn () => count($this->nextProxies) > 1 ? array_shift($this->nextProxies) : $this->nextProxies[0]
        );
        $this->proxyManager = new ProxyManager($proxies, $this->cache);
    }

    /**
     * @test
     */
    public function whenProxyBonanzaDoesNotRetrieveAProxyListItShouldThrowAnException()
    {
        $proxyBonanza = Mockery::mock(ProxyInterface::class);
        $handler      = function ($request, $options) {
            return 'response';
        };
        $request      = Mockery::mock(RequestInterface::class);
        $options      = [];

        $proxyManager = new ProxyManager($proxyBonanza);

        $proxyBonanza->shouldReceive('get')
            ->once()
            ->andThrow(ProxiesNotAvailable::class);

        $this->expectException(ProxiesNotAvailable::class);

        $handler = $proxyManager($handler);
        $handler($request, $options);
    }

    /**
     * @test
     */
    public function whenProxyIsAvailableItShouldSetTheProxyInTheRequest()
    {
        $proxyBonanza = Mockery::mock(ProxyInterface::class);
        $handler      = function ($request, $options) {
            return $options;
        };
        $request      = Mockery::mock(RequestInterface::class);
        $options      = [];

        $proxyManager = new ProxyManager($proxyBonanza);

        $proxyBonanza->shouldReceive('get')
            ->once()
            ->andReturn('http://user:pass@server:8125');

        $handler = $proxyManager($handler);
        $options = $handler($request, $options);

        $this->assertEquals(
            'http://user:pass@server:8125',
            $options['proxy']
        );
    }

    /**
     * @test
     */
    public function whenAProxyFailsThreeTimesInARowItShouldBeSkipped()
    {
        $this->assertSame(self::A, $this->send([self::A, self::B], $this->unreachable()));
        $this->assertSame(self::A, $this->send([self::A, self::B], $this->unreachable()));
        $this->assertSame(self::A, $this->send([self::A, self::B], $this->unreachable()));

        $this->assertSame(self::B, $this->send([self::A, self::B], $this->success()));
    }

    /**
     * @test
     */
    public function whenAProxyWorksItsFailuresShouldBeForgotten()
    {
        $this->send([self::A], $this->unreachable());
        $this->send([self::A], $this->unreachable());
        $this->send([self::A], $this->success());
        $this->send([self::A], $this->unreachable());
        $this->send([self::A], $this->unreachable());

        $this->assertSame(self::A, $this->send([self::A, self::B], $this->success()));
    }

    /**
     * @test
     */
    public function whenAReusedConnectionTimesOutTheProxyShouldNotBeBlamed()
    {
        $this->assertNotBlamedFor(['errno' => 28, 'connect_time' => 0.0, 'request_size' => 120]);
    }

    /**
     * @test
     */
    public function whenTheConnectionFailsForAnotherReasonTheProxyShouldNotBeBlamed()
    {
        $this->assertNotBlamedFor(['errno' => 35, 'connect_time' => 0.0, 'request_size' => 0]);
    }

    /**
     * @test
     */
    public function whenTheOriginAnswersWithAnErrorTheProxyShouldNotBeBlamed()
    {
        $originError = new RequestException('502', new Request('GET', 'http://origin'), new Response(502));

        for ($i = 0; $i < 3; $i++) {
            $this->send([self::A], Create::rejectionFor($originError));
        }

        $this->assertSame(self::A, $this->send([self::A, self::B], $this->success()));
    }

    /**
     * @test
     */
    public function whenAProxyFailsAfterItsQuarantineItShouldBeQuarantinedForLonger()
    {
        foreach ([3 => 24 * 3600, 4 => 48 * 3600, 5 => 30 * 24 * 3600, 9 => 30 * 24 * 3600] as $failures => $penalty) {
            $this->cache->set($this->key(self::A), [$failures, time() - 1]);

            $this->send([self::A], $this->unreachable());

            [$storedFailures, $quarantinedUntil] = $this->cache->get($this->key(self::A));
            $this->assertSame($failures + 1, $storedFailures);
            $this->assertEqualsWithDelta(time() + $penalty, $quarantinedUntil, 1);
        }
    }

    /**
     * @test
     */
    public function whenAQuarantinedProxyFailsItShouldNotEscalate()
    {
        $this->cache->set($this->key(self::A), [3, time() + 60]);

        $this->send([self::A], $this->unreachable());

        $this->assertSame([3, time() + 60], $this->cache->get($this->key(self::A)));
    }

    /**
     * @test
     */
    public function whenEveryPickedProxyIsQuarantinedItShouldUseTheLastOne()
    {
        $this->cache->set($this->key(self::A), [3, time() + 60]);
        $this->cache->set($this->key(self::B), [3, time() + 60]);

        $this->assertSame(self::B, $this->send([self::A, self::A, self::A, self::A, self::B, self::A], $this->success()));
    }

    private function assertNotBlamedFor(array $handlerContext): void
    {
        $failure = new ConnectException('failed', new Request('GET', 'http://origin'), null, $handlerContext);

        for ($i = 0; $i < 3; $i++) {
            $this->send([self::A], Create::rejectionFor($failure));
        }

        $this->assertSame(self::A, $this->send([self::A, self::B], $this->success()));
    }

    private function send(array $nextProxies, $outcome): string
    {
        $this->nextProxies = $nextProxies;
        $usedProxy         = null;
        $handler           = ($this->proxyManager)(function ($request, $options) use (&$usedProxy, $outcome) {
            $usedProxy = $options['proxy'];

            return $outcome;
        });

        $handler(new Request('GET', 'http://origin'), [])->wait(false);

        return $usedProxy;
    }

    private function unreachable()
    {
        return Create::rejectionFor(new ConnectException(
            'Connection timed out',
            new Request('GET', 'http://origin'),
            null,
            ['errno' => 28, 'connect_time' => 0.0, 'request_size' => 0]
        ));
    }

    private function success()
    {
        return Create::promiseFor(new Response(200));
    }

    private function key(string $proxy): string
    {
        return 'proxy_quarantine.' . hash('xxh128', $proxy);
    }
}
