<?php

namespace Softonic\Proxy\Guzzle\Middleware;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxyUnreachable;
use Softonic\Proxy\Guzzle\Middleware\Interfaces\ProxyListInterface;
use Softonic\Proxy\Guzzle\Middleware\Stores\LaravelPenaltyStore;
use Throwable;

class ProxyRotationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const URL = 'https://download.example.com/app.apk';

    private const PROXIES = [
        'http://user:secret@154.0.0.1:8080',
        'http://user:secret@154.0.0.2:8080',
        'http://user:secret@154.0.0.3:8080',
    ];

    private ProxyQuarantine $quarantine;

    private LoggerInterface&MockInterface $logger;

    private MockHandler $origin;

    /**
     * @var list<array{request: Request, options: array<string, mixed>}>
     */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now()->startOfSecond());

        $this->logger     = Mockery::spy(LoggerInterface::class);
        $this->quarantine = new ProxyQuarantine(new LaravelPenaltyStore(new Repository(new ArrayStore())));
        $this->origin     = new MockHandler();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @test
     */
    public function whenProxyCannotBeReachedItShouldQuarantineItAndRetryAtOnceThroughAnother()
    {
        $this->origin->append(self::proxyUnreachable(), new Response(200));

        $response = $this->client()->get(self::URL);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->sent);
        [$dead, $healthy] = $this->usedProxies();
        $this->assertNotSame($dead, $healthy);
        $this->assertQuarantined($dead);
        $this->assertNotQuarantined($healthy);
        $this->logger->shouldHaveReceived('warning')
            ->once()
            ->with('Proxy unreachable', Mockery::on(
                static fn (array $context): bool => $context['proxy'] === ProxyQuarantine::identify($dead)
                    && $context['attempt'] === 1
                    && $context['error'] === 'cURL error 28: Connection timed out after 2001 milliseconds'
                    && $context['strikes'] === 1
                    && $context['penalty_seconds'] === 3 * 3600
                    && $context['banned'] === false
                    && !str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'secret')
            ));
    }

    /**
     * @test
     */
    public function whenNoProxyCanBeReachedItShouldGiveUpAfterTheMaximumAttempts()
    {
        $this->origin->append(self::proxyUnreachable(), self::proxyUnreachable(), self::proxyUnreachable());

        try {
            $this->client()->get(self::URL);
            $this->fail('The request should have failed');
        } catch (ProxyUnreachable $e) {
            $this->assertStringStartsWith('No proxy accepted the connection after 3 attempts', $e->getMessage());
            $this->assertInstanceOf(ConnectException::class, $e->getPrevious());
            $this->assertSame(0, $e->getHandlerContext()['request_size']);
        }

        $this->assertCount(ProxyRotation::DEFAULT_MAX_ATTEMPTS, $this->sent);
        $this->assertCount(3, array_unique($this->usedProxies()));
        foreach ($this->usedProxies() as $proxy) {
            $this->assertQuarantined($proxy);
        }
    }

    /**
     * @test
     */
    public function whenMaximumAttemptsAreGivenItShouldStopThere()
    {
        $this->origin->append(self::proxyUnreachable(), self::proxyUnreachable());

        try {
            $this->client(2)->get(self::URL);
            $this->fail('The request should have failed');
        } catch (ProxyUnreachable $e) {
            $this->assertStringStartsWith('No proxy accepted the connection after 2 attempts', $e->getMessage());
        }

        $this->assertCount(2, $this->sent);
    }

    /**
     * @test
     */
    public function whenTheLastHealthyProxyRelapsesForTheLastTimeItShouldBanItAndReportNoProxyLeft()
    {
        foreach (self::PROXIES as $proxy) {
            foreach ([3, 24, 48] as $hours) {
                $this->quarantine->punish($proxy);
                Carbon::setTestNow(Carbon::now()->addHours($hours));
            }
        }

        $this->quarantine->punish(self::PROXIES[1]);
        $this->quarantine->punish(self::PROXIES[2]);

        $this->origin->append(self::proxyUnreachable());

        try {
            $this->client()->get(self::URL);
            $this->fail('The request should have failed');
        } catch (ProxiesNotAvailable $e) {
            $this->assertSame('All 3 proxies are banned', $e->getMessage());
        }

        $this->assertSame([self::PROXIES[0]], $this->usedProxies());
        $this->logger->shouldHaveReceived('warning')
            ->once()
            ->with('Proxy unreachable', Mockery::on(
                static fn (array $context): bool => $context['strikes'] === 4
                    && $context['penalty_seconds'] === 30 * 24 * 3600
                    && $context['banned'] === true
            ));
    }

    /**
     * @test
     */
    public function whenTheOnlyProxyLeftIsAlreadyQuarantinedItShouldNotEscalateItsPenalty()
    {
        foreach (self::PROXIES as $proxy) {
            $this->quarantine->punish($proxy);
        }

        $this->origin->append(self::proxyUnreachable(), new Response(200));

        $this->assertSame(200, $this->client()->get(self::URL)->getStatusCode());
        $this->logger->shouldHaveReceived('warning')
            ->once()
            ->with('Proxy unreachable', Mockery::on(
                static fn (array $context): bool => $context['strikes'] === null
                    && $context['penalty_seconds'] === null
                    && $context['banned'] === false
            ));
        Carbon::setTestNow(Carbon::now()->addHours(3));
        $this->assertNotQuarantined($this->usedProxies()[0], 2);
    }

    /**
     * @test
     */
    public function whenOriginTimesOutOnAReusedProxyConnectionItShouldNotQuarantineTheProxy()
    {
        $this->origin->append(new Response(200), self::reusedConnectionTimeout());
        $client = $this->client();

        $this->assertSame(200, $client->get(self::URL)->getStatusCode());

        try {
            $client->get(self::URL);
            $this->fail('The request should have failed');
        } catch (ConnectException $e) {
            $this->assertNotInstanceOf(ProxyUnreachable::class, $e);
        }

        $this->assertCount(2, $this->sent);
        $this->assertNotQuarantined($this->usedProxies()[1]);
        $this->logger->shouldNotHaveReceived('warning');
    }

    /**
     * @test
     *
     * @dataProvider originFailures
     */
    public function whenOriginFailsBehindAReachableProxyItShouldNotQuarantineTheProxy(
        callable $failure,
        string $expectedException
    ) {
        $this->origin->append($failure);

        try {
            $this->client()->get(self::URL);
            $this->fail('The request should have failed');
        } catch (Throwable $e) {
            $this->assertInstanceOf($expectedException, $e);
            $this->assertNotInstanceOf(ProxyUnreachable::class, $e);
        }

        $this->assertCount(1, $this->sent);
        $this->assertNotQuarantined($this->usedProxies()[0]);
        $this->logger->shouldNotHaveReceived('warning');
    }

    public function originFailures(): array
    {
        return [
            'origin answers with a server error' => [
                static fn (Request $request): Response => new Response(502),
                ServerException::class,
            ],
            'origin times out after the proxy connected' => [
                self::failure('cURL error 28: Operation timed out', self::context(28, 0.12, 115)),
                ConnectException::class,
            ],
            'origin times out on a reused proxy connection' => [
                self::reusedConnectionTimeout(),
                ConnectException::class,
            ],
            'origin stalls mid-body on a reused proxy connection' => [
                self::failure(
                    'cURL error 28: Operation timed out with 5000 out of 100000 bytes received',
                    ['size_download' => 5000.0] + self::context(28, 0.0, 118)
                ),
                ConnectException::class,
            ],
            'proxy refuses the tunnel to the origin' => [
                static fn (Request $request): RequestException => new RequestException(
                    'cURL error 56: CONNECT tunnel failed, response 502',
                    $request,
                    null,
                    null,
                    self::context(56, 0.05, 0)
                ),
                RequestException::class,
            ],
            'empty reply once connected to the proxy' => [
                self::failure('cURL error 52: Empty reply from server', self::context(52, 0.05, 115)),
                ConnectException::class,
            ],
        ];
    }

    /**
     * @test
     *
     * @dataProvider failureReasons
     */
    public function whenFailureIsClassifiedItShouldOnlyBlameTheProxyForAConnectionThatNeverCarriedAByte(
        mixed $reason,
        bool $expected
    ) {
        $this->assertSame($expected, ProxyRotation::isProxyUnreachable($reason));
    }

    public function failureReasons(): array
    {
        $request = new Request('GET', self::URL);

        return [
            'connect timeout to the proxy' => [
                new ConnectException('', $request, null, self::context(28, 0.0, 0)),
                true,
            ],
            'proxy refuses the connection' => [
                new ConnectException('', $request, null, self::context(7, 0, 0)),
                true,
            ],
            'timeout once connected to the proxy' => [
                new ConnectException('', $request, null, self::context(28, 0.2, 115)),
                false,
            ],
            'timeout once connected, before sending anything' => [
                new ConnectException('', $request, null, self::context(28, 0.2, 0)),
                false,
            ],
            'timeout on a reused connection, which reports no connect time' => [
                new ConnectException('', $request, null, self::context(28, 0.0, 115)),
                false,
            ],
            'request size counted as a string' => [
                new ConnectException('', $request, null, self::context(28, 0.0, '0')),
                false,
            ],
            'no request size' => [
                new ConnectException('', $request, null, ['errno' => 28, 'connect_time' => 0.0]),
                false,
            ],
            'no connect time' => [
                new ConnectException('', $request, null, ['errno' => 28, 'request_size' => 0]),
                false,
            ],
            'TLS failure with the origin through the tunnel' => [
                new ConnectException('', $request, null, self::context(35, 0.0, 0)),
                false,
            ],
            'no curl context' => [new ConnectException('', $request), false],
            'not a connection failure' => [new RequestException('', $request), false],
            'not an exception' => ['rejected', false],
        ];
    }

    private function client(int $maxAttempts = ProxyRotation::DEFAULT_MAX_ATTEMPTS): Client
    {
        $proxies = Mockery::mock(ProxyListInterface::class);
        $proxies->shouldReceive('all')->andReturn(self::PROXIES);

        $stack = HandlerStack::create($this->origin);
        $stack->push(new ProxyRotation($proxies, $this->quarantine, $this->logger, $maxAttempts));
        $stack->push(Middleware::history($this->sent));

        return new Client(['handler' => $stack]);
    }

    private static function proxyUnreachable(): callable
    {
        return self::failure('cURL error 28: Connection timed out after 2001 milliseconds', self::context(28, 0.0, 0));
    }

    /**
     * What curl reports when the origin is slow on a connection reused from an earlier request: no
     * connect time, since nothing had to be connected, but the request was sent.
     */
    private static function reusedConnectionTimeout(): callable
    {
        return self::failure('cURL error 28: Operation timed out with 0 bytes received', self::context(28, 0.0, 115));
    }

    private static function failure(string $message, array $context): callable
    {
        return static fn (Request $request): ConnectException => new ConnectException(
            $message,
            $request,
            null,
            $context
        );
    }

    private static function context(int $errno, float|int $connectTime, int|string $requestSize): array
    {
        return ['errno' => $errno, 'connect_time' => $connectTime, 'request_size' => $requestSize];
    }

    /**
     * @return list<string>
     */
    private function usedProxies(): array
    {
        return array_map(static fn (array $transaction): string => $transaction['options']['proxy'], $this->sent);
    }

    private function assertQuarantined(string $proxy): void
    {
        $this->assertNull($this->quarantine->punish($proxy), "{$proxy} should be quarantined");
    }

    private function assertNotQuarantined(string $proxy, int $expectedStrikes = 1): void
    {
        $this->assertSame($expectedStrikes, $this->quarantine->punish($proxy), "{$proxy} should not be quarantined");
    }
}
