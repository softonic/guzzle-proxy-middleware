<?php

namespace Softonic\Proxy\Guzzle\Middleware\Traits;

use Exception;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;

trait CacheProxiesList
{
    public function getProxyUsingCache()
    {
        $proxiesList = $this->getProxyListUsingCache();

        return $proxiesList[random_int(0, count($proxiesList) - 1)];
    }

    public function getProxyListUsingCache(): array
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

            return $proxiesList;
        } catch (Exception $e) {
            throw new ProxiesNotAvailable($e->getMessage(), 0, $e);
        }
    }
}
