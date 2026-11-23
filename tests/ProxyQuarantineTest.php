<?php

namespace Softonic\Proxy\Guzzle\Middleware;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;
use Softonic\Proxy\Guzzle\Middleware\Interfaces\PenaltyStore;
use Softonic\Proxy\Guzzle\Middleware\Stores\LaravelPenaltyStore;

class ProxyQuarantineTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const DEAD = 'http://user:secret@154.0.0.1:8080';

    private const HEALTHY = 'http://user:secret@10.0.0.1:8080';

    private const OTHER = 'http://user:secret@10.0.0.2:8080';

    private const DAY = 24 * 3600;

    private Repository $cache;

    private LoggerInterface&MockInterface $logger;

    private ProxyQuarantine $quarantine;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::now()->startOfSecond());

        $this->cache      = new Repository(new ArrayStore());
        $this->logger     = Mockery::spy(LoggerInterface::class);
        $this->quarantine = new ProxyQuarantine(new LaravelPenaltyStore($this->cache), $this->logger);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @test
     */
    public function whenProxyFailsToConnectItShouldBeQuarantinedForThreeHours()
    {
        $this->assertSame(1, $this->quarantine->punish(self::DEAD));

        $this->travel(3 * 3600 - 1);
        $this->assertQuarantined(self::DEAD);

        $this->travel(1);
        $this->assertAvailable(self::DEAD);
    }

    /**
     * @test
     */
    public function whenProxyRelapsesItShouldBeQuarantinedOneDayThenTwoDaysThenBannedForThirtyDays()
    {
        $this->quarantine->punish(self::DEAD);
        $this->travel(3 * 3600);

        $this->assertSame(2, $this->quarantine->punish(self::DEAD));
        $this->travel(self::DAY - 1);
        $this->assertQuarantined(self::DEAD);
        $this->travel(1);
        $this->assertAvailable(self::DEAD);

        $this->assertSame(3, $this->quarantine->punish(self::DEAD));
        $this->travel(2 * self::DAY - 1);
        $this->assertQuarantined(self::DEAD);
        $this->travel(1);
        $this->assertAvailable(self::DEAD);

        $this->assertSame(4, $this->quarantine->punish(self::DEAD));
        $this->travel(30 * self::DAY - 1);
        $this->assertBanned(self::DEAD);
        $this->travel(1);
        $this->assertAvailable(self::DEAD);
    }

    /**
     * @test
     */
    public function whenProxyFailsAgainAfterItsBanItShouldBeBannedAgainAtOnce()
    {
        $this->ban(self::DEAD);
        $this->travel(60 * self::DAY - 1);

        $this->assertSame(5, $this->quarantine->punish(self::DEAD));
        $this->travel(30 * self::DAY - 1);
        $this->assertBanned(self::DEAD);
    }

    /**
     * @test
     */
    public function whenProxyStaysHealthyForThirtyDaysAfterItsBanItsStrikesShouldBeForgotten()
    {
        $this->ban(self::DEAD);
        $this->travel(60 * self::DAY);

        $this->assertSame(1, $this->quarantine->punish(self::DEAD));
    }

    /**
     * @test
     */
    public function whenProxyFailsAgainWhileQuarantinedItShouldNotEscalate()
    {
        $this->quarantine->punish(self::DEAD);

        $this->assertNull($this->quarantine->punish(self::DEAD));

        $this->travel(3 * 3600);
        $this->assertAvailable(self::DEAD);
        $this->assertSame(2, $this->quarantine->punish(self::DEAD));
    }

    /**
     * @test
     */
    public function whenProxyStaysHealthyForThirtyDaysAfterItsQuarantineItsStrikesShouldBeForgotten()
    {
        $this->quarantine->punish(self::DEAD);
        $this->quarantine->punish(self::OTHER);
        $this->travel(3 * 3600 + 30 * self::DAY - 1);

        $this->assertSame(2, $this->quarantine->punish(self::OTHER));
        $this->travel(1);
        $this->assertSame(1, $this->quarantine->punish(self::DEAD));
    }

    /**
     * @test
     */
    public function whenProxyIsQuarantinedOrBannedItShouldNeverBePicked()
    {
        $banned = 'http://user:secret@154.0.0.2:8080';
        $this->ban($banned);
        $this->quarantine->punish(self::DEAD);

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(self::HEALTHY, $this->quarantine->pick([self::DEAD, $banned, self::HEALTHY]));
        }
    }

    /**
     * @test
     */
    public function whenSeveralProxiesAreHealthyItShouldSpreadThePicks()
    {
        $picked = [];
        for ($i = 0; $i < 100; $i++) {
            $picked[$this->quarantine->pick([self::HEALTHY, self::OTHER])] = true;
        }

        $this->assertCount(2, $picked);
    }

    /**
     * @test
     */
    public function whenEveryProxyIsQuarantinedItShouldPickOneOfTheLeastPenalized()
    {
        $this->ban(self::HEALTHY);
        $this->quarantine->punish(self::DEAD);
        $this->travel(3 * 3600);
        $this->quarantine->punish(self::DEAD);
        $this->quarantine->punish(self::OTHER);

        $this->assertSame(self::OTHER, $this->quarantine->pick([self::DEAD, self::OTHER, self::HEALTHY]));

        $this->logger->shouldHaveReceived('warning')
            ->once()
            ->with('Every proxy is quarantined, using one of the least penalised', [
                'quarantined' => 2,
                'banned'      => 1,
                'strikes'     => 1,
            ]);
    }

    /**
     * @test
     */
    public function whenEveryProxyIsBannedItShouldFailWithAClearError()
    {
        $this->ban(self::DEAD);
        $this->ban(self::OTHER);

        $this->expectException(ProxiesNotAvailable::class);
        $this->expectExceptionMessage('All 2 proxies are banned');

        $this->quarantine->pick([self::DEAD, self::OTHER]);
    }

    /**
     * @test
     */
    public function whenProxyListIsEmptyItShouldFailWithAClearError()
    {
        $this->expectException(ProxiesNotAvailable::class);
        $this->expectExceptionMessage('The proxy list is empty');

        $this->quarantine->pick([]);
    }

    /**
     * @test
     */
    public function whenProxyIsPunishedItShouldKeepItsStateUnderItsAddressWithoutCredentials()
    {
        $this->quarantine->punish(self::DEAD);

        $this->assertSame(1, $this->cache->get('proxy-quarantine:quarantined:154.0.0.1:8080'));
        $this->assertSame(1, $this->cache->get('proxy-quarantine:strikes:154.0.0.1:8080'));
        $this->assertNull($this->cache->get('proxy-quarantine:banned:154.0.0.1:8080'));

        foreach ([3 * 3600, self::DAY, 2 * self::DAY] as $seconds) {
            $this->travel($seconds);
            $this->quarantine->punish(self::DEAD);
        }

        $this->assertSame(4, $this->cache->get('proxy-quarantine:banned:154.0.0.1:8080'));
        $this->assertSame(4, $this->cache->get('proxy-quarantine:quarantined:154.0.0.1:8080'));
        $this->assertSame(4, $this->cache->get('proxy-quarantine:strikes:154.0.0.1:8080'));
    }

    /**
     * @test
     */
    public function whenProxyIsPunishedItShouldStoreEachPenaltyWithItsLength()
    {
        $store = Mockery::mock(PenaltyStore::class);
        $store->shouldReceive('add')->once()->with('proxy-quarantine:quarantined:10.0.0.2:8080', 1, 3 * 3600)->andReturnTrue();
        $store->shouldReceive('get')->once()->with('proxy-quarantine:strikes:10.0.0.2:8080')->andReturn(3);
        $store->shouldReceive('put')->once()->with('proxy-quarantine:strikes:10.0.0.2:8080', 4, 60 * self::DAY);
        $store->shouldReceive('put')->once()->with('proxy-quarantine:quarantined:10.0.0.2:8080', 4, 30 * self::DAY);
        $store->shouldReceive('put')->once()->with('proxy-quarantine:banned:10.0.0.2:8080', 4, 30 * self::DAY);

        $this->assertSame(4, (new ProxyQuarantine($store))->punish(self::OTHER));
    }

    /**
     * @test
     */
    public function whenProxyIsIdentifiedItShouldLeaveTheCredentialsOut()
    {
        $this->assertSame('154.0.0.1:8080', ProxyQuarantine::identify(self::DEAD));
        $this->assertSame('154.0.0.1', ProxyQuarantine::identify('http://user:secret@154.0.0.1'));
        $this->assertSame(hash('sha256', 'not a url:'), ProxyQuarantine::identify('not a url:'));
    }

    /**
     * @test
     */
    public function whenStrikesAreGivenItShouldReturnThePenaltyAndWhetherItIsABan()
    {
        $this->assertSame(3 * 3600, ProxyQuarantine::penaltySeconds(1));
        $this->assertSame(self::DAY, ProxyQuarantine::penaltySeconds(2));
        $this->assertSame(2 * self::DAY, ProxyQuarantine::penaltySeconds(3));
        $this->assertSame(30 * self::DAY, ProxyQuarantine::penaltySeconds(4));
        $this->assertSame(30 * self::DAY, ProxyQuarantine::penaltySeconds(5));
        $this->assertFalse(ProxyQuarantine::isBan(3));
        $this->assertTrue(ProxyQuarantine::isBan(4));
        $this->assertTrue(ProxyQuarantine::isBan(5));
    }

    private function travel(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    private function ban(string $proxy): void
    {
        foreach ([3 * 3600, self::DAY, 2 * self::DAY] as $seconds) {
            $this->quarantine->punish($proxy);
            $this->travel($seconds);
        }

        $this->assertSame(4, $this->quarantine->punish($proxy));
    }

    private function assertQuarantined(string $proxy): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(self::HEALTHY, $this->quarantine->pick([$proxy, self::HEALTHY]));
        }
    }

    private function assertBanned(string $proxy): void
    {
        $this->assertQuarantined($proxy);

        try {
            $this->quarantine->pick([$proxy]);
            $this->fail("{$proxy} should be banned");
        } catch (ProxiesNotAvailable $e) {
            $this->assertSame('All 1 proxies are banned', $e->getMessage());
        }
    }

    private function assertAvailable(string $proxy): void
    {
        $logger = Mockery::spy(LoggerInterface::class);

        $this->assertSame($proxy, (new ProxyQuarantine(new LaravelPenaltyStore($this->cache), $logger))->pick([$proxy]));

        $logger->shouldNotHaveReceived('warning');
    }
}
