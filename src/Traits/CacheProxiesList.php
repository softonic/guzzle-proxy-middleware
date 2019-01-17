<?php

namespace Softonic\Proxy\Guzzle\Middleware\Traits;

use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;

trait CacheProxiesList
{
    public function getProxyUsingCache()
    {
        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            if (!$item->isHit()) {
                $proxiesList = $this->getFreshProxyList();

                $item->set($proxiesList);
                $item->expiresAfter(self::CACHE_TTL);
                $this->cache->save($item);
            } else {
                $proxiesList = $item->get();
            }

            return $proxiesList[random_int(0, count($proxiesList) - 1)];
        } catch (\Exception $e) {
            throw new ProxiesNotAvailable($e->getMessage(), 0, $e);
        }
    }
}
