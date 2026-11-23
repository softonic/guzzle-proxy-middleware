Guzzle Proxy Middleware
=====

[![Latest Version](https://img.shields.io/github/release/softonic/guzzle-proxy-middleware.svg?style=flat-square)](https://github.com/softonic/guzzle-proxy-middleware/releases)
[![Software License](https://img.shields.io/badge/license-Apache%202.0-blue.svg?style=flat-square)](LICENSE.md)
[![Build Status](https://img.shields.io/travis/softonic/guzzle-proxy-middleware/master.svg?style=flat-square)](https://travis-ci.org/softonic/guzzle-proxy-middleware)
[![Coverage Status](https://img.shields.io/scrutinizer/coverage/g/softonic/guzzle-proxy-middleware.svg?style=flat-square)](https://scrutinizer-ci.com/g/softonic/guzzle-proxy-middleware/code-structure)
[![Quality Score](https://img.shields.io/scrutinizer/g/softonic/guzzle-proxy-middleware.svg?style=flat-square)](https://scrutinizer-ci.com/g/softonic/guzzle-proxy-middleware)
[![Total Downloads](https://img.shields.io/packagist/dt/softonic/guzzle-proxy-middleware.svg?style=flat-square)](https://packagist.org/packages/softonic/guzzle-proxy-middleware)
[![Average time to resolve an issue](http://isitmaintained.com/badge/resolution/softonic/guzzle-proxy-middleware.svg?style=flat-square)](http://isitmaintained.com/project/softonic/guzzle-proxy-middleware "Average time to resolve an issue")
[![Percentage of issues still open](http://isitmaintained.com/badge/open/softonic/guzzle-proxy-middleware.svg?style=flat-square)](http://isitmaintained.com/project/softonic/guzzle-proxy-middleware "Percentage of issues still open")


This package provides middleware for [guzzle](https://github.com/guzzle/guzzle/) for handling proxy connection using one of the following proxy services:
- [Proxy Bonanza](https://proxybonanza.com)
- [SSL Private Proxy](https://www.sslprivateproxy.com)

Installation
-------

To install, use composer:

```
composer require softonic/guzzle-proxy-middleware
```

Usage
-------

To use this middleware, you need to initialize it like:

For Proxy Bonanza:
```php
$proxyManager = new ProxyManager(
    new ProxyBonanza(
        new GuzzleClient(),
        $cache, // A PSR-6 item pool cache.
        '<YOUR-USER-PACKAGE-ID>',
        '<YOUR-API-KEY>'
    )
);
```


For SSL Private Proxy:
```php
$proxyManager = new ProxyManager(
    new SslPrivateProxy(
        new GuzzleClient(),
        $cache, // A PSR-6 item pool cache.
        '<YOUR-API-KEY>'
    )
);
```

And inject it to Guzzle with something like:
```php
$stack = new HandlerStack();
$stack->setHandler(new CurlHandler());
$stack->push($proxyManager);
$guzzleClient = new GuzzleClient(['handler' => $stack]);
```

From now on every request sent with `$guzzleClient` will be done using a random proxy from your proxy list.

### Skipping dead proxies

`ProxyManager` picks any proxy of the list, dead or alive, so a dead proxy fails every request that
draws it. `ProxyRotation` replaces it and keeps the dead ones out of rotation:

- A proxy whose connection never completes (refused, or not established within `connect_timeout`)
  is **quarantined**: 3 hours, then 1 day and 2 days on each relapse after its quarantine ended, then
  **banned** for 30 days. A proxy that fails again after its ban is banned again at once. Its failure
  count is forgotten 30 days after its last penalty ended.
- The request is **sent again at once through another proxy**, up to 3 proxies (configurable), so a
  dead proxy costs a connect timeout instead of a failed request. When they all fail, the request
  fails with `ProxyUnreachable` (a `ConnectException`).
- Only the proxy is blamed for a connection that never carried a byte. An origin that is down, slow
  or answers with an error behind a proxy that did connect costs the proxy nothing, including a
  timeout on a connection kept open from an earlier request.
- When every proxy is quarantined, one of the least penalised is used and a warning is logged. When
  every proxy is banned, the request fails with `ProxiesNotAvailable('All N proxies are banned')`.

The penalties live in a `PenaltyStore` shared by every worker. Its `add()` must be atomic, so that
several workers failing on the same proxy at once count as one failure. With Laravel, use a shared
cache store such as redis:

```php
use Softonic\Proxy\Guzzle\Middleware\ProxyQuarantine;
use Softonic\Proxy\Guzzle\Middleware\ProxyRotation;
use Softonic\Proxy\Guzzle\Middleware\Stores\LaravelPenaltyStore;

$rotation = new ProxyRotation(
    new SslPrivateProxy(new GuzzleClient(), $cache, '<YOUR-API-KEY>'), // or ProxyBonanza
    new ProxyQuarantine(new LaravelPenaltyStore(Cache::store('redis')), $logger),
    $logger, // Any PSR-3 logger; optional.
);

$stack = HandlerStack::create();
$stack->push($rotation); // After any request-logging middleware, so recovered failures are not logged as errors.
$guzzleClient = new GuzzleClient(['handler' => $stack]);
```

Keys are `proxy-quarantine:{quarantined,strikes,banned}:<host:port>`, without the proxy's
credentials. To lift a ban early, delete its `banned:` and `quarantined:` keys.


Testing
-------

`softonic/guzzle-proxy-middleware` has a [PHPUnit](https://phpunit.de) test suite and a coding style compliance test suite using [PHP CS Fixer](http://cs.sensiolabs.org/).

To run the tests, run the following command from the project folder.

``` bash
$ docker-compose run test
```

To run interactively using [PsySH](http://psysh.org/):
``` bash
$ docker-compose run psysh
```

License
-------

The Apache 2.0 license. Please see [LICENSE](LICENSE) for more information.

[PSR-2]: http://www.php-fig.org/psr/psr-2/
[PSR-4]: http://www.php-fig.org/psr/psr-4/
