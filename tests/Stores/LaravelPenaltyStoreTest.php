<?php

namespace Softonic\Proxy\Guzzle\Middleware\Stores;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as RepositoryContract;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class LaravelPenaltyStoreTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /**
     * @test
     */
    public function whenKeyIsAddedItShouldOnlySucceedTheFirstTime()
    {
        $store = new LaravelPenaltyStore(new Repository(new ArrayStore()));

        $this->assertTrue($store->add('key', 1, 60));
        $this->assertFalse($store->add('key', 2, 60));
        $this->assertSame(1, $store->get('key'));
    }

    /**
     * @test
     */
    public function whenValuesArePutItShouldReadThemBackAsIntegers()
    {
        $store = new LaravelPenaltyStore(new Repository(new ArrayStore()));

        $store->put('a', 3, 60);

        $this->assertSame(3, $store->get('a'));
        $this->assertNull($store->get('missing'));
        $this->assertSame(['a' => 3, 'missing' => null], $store->many(['a', 'missing']));
    }

    /**
     * @test
     */
    public function whenRedisReturnsNumbersAsStringsItShouldCastThem()
    {
        $cache = Mockery::mock(RepositoryContract::class);
        $cache->shouldReceive('get')->with('a')->andReturn('2');
        $cache->shouldReceive('many')->with(['a', 'b'])->andReturn(['a' => '2', 'b' => null]);
        $cache->shouldReceive('put')->once()->with('a', 2, 60);
        $store = new LaravelPenaltyStore($cache);

        $store->put('a', 2, 60);

        $this->assertSame(2, $store->get('a'));
        $this->assertSame(['a' => 2, 'b' => null], $store->many(['a', 'b']));
    }
}
