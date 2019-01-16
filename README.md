Guzzle Proxy Middleware
=====

[![Latest Version](https://img.shields.io/github/release/softonic/guzzle-proxy-middleware.svg?style=flat-square)](https://github.com/softonic/guzzle-proxy-middleware/releases)
[![Software License](https://img.shields.io/badge/license-Apache%202.0-blue.svg?style=flat-square)](LICENSE.md)
[![Build Status](https://img.shields.io/travis/softonic/guzzle-proxy-middleware/master.svg?style=flat-square)](https://travis-ci.org/softonic/guzzle-proxy-middleware)
[![Total Downloads](https://img.shields.io/packagist/dt/softonic/guzzle-proxy-middleware.svg?style=flat-square)](https://packagist.org/packages/softonic/guzzle-proxy-middleware)

This package provides middleware for [guzzle](https://github.com/guzzle/guzzle/) for handling proxy connection using [proxy bonanza](https://proxybonanza.com).

Installation
-------

To install, use composer:

```
composer require softonic/guzzle-proxy-middleware
```

Usage
-------

To use this middleware, you need to initialize it like:

```php
$proxyManager = new ProxyManager(
    new Proxy(
        new GuzzleClient(),
        $cache, // A PSR-6 item pool cache.
        '<YOUR-USER-PACKAGE-ID>',
        '<YOUR-API-KEY>'
));
```

And inject it to Guzzle with somethine like:
```php
$stack = new HandlerStack();
$stack->setHandler(new CurlHandler());
$stack->push($proxyManager);
$guzzleClient = new GuzzleClient(['handler' => $stack]);
```

From now on every request sent with `$guzzleClient` will be done using a random proxy from your proxy list.


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
