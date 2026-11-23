<?php

namespace Softonic\Proxy\Guzzle\Middleware\Exceptions;

use GuzzleHttp\Exception\ConnectException;

/**
 * Every proxy tried for a request refused or timed out the connection.
 */
final class ProxyUnreachable extends ConnectException
{
    public static function afterAttempts(int $attempts, ConnectException $lastFailure): self
    {
        return new self(
            "No proxy accepted the connection after {$attempts} attempts: {$lastFailure->getMessage()}",
            $lastFailure->getRequest(),
            $lastFailure,
            $lastFailure->getHandlerContext()
        );
    }
}
