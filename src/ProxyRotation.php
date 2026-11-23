<?php

namespace Softonic\Proxy\Guzzle\Middleware;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxyUnreachable;
use Softonic\Proxy\Guzzle\Middleware\Interfaces\ProxyListInterface;

/**
 * Guzzle middleware that sends each request through a healthy proxy.
 *
 * When the proxy itself cannot be reached, it is quarantined and the request is sent again at once
 * through another one, so a dead proxy costs a connect timeout instead of a failed request. Push it
 * after any request-logging middleware, so that only the request's final outcome is logged and a
 * recovered proxy failure stays the warning logged here.
 */
final class ProxyRotation
{
    public const DEFAULT_MAX_ATTEMPTS = 3;

    /**
     * curl error codes, spelled out so that the class loads without the curl extension.
     */
    private const CURLE_COULDNT_CONNECT = 7;

    private const CURLE_OPERATION_TIMEDOUT = 28;

    public function __construct(
        private readonly ProxyListInterface $proxies,
        private readonly ProxyQuarantine $quarantine,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
    ) {
    }

    public function __invoke(callable $handler): Closure
    {
        return fn (RequestInterface $request, array $options): PromiseInterface => $this->send(
            $handler,
            $request,
            $options,
            1
        );
    }

    /**
     * Whether the connection to the proxy itself failed, as opposed to the origin behind it.
     *
     * Through a proxy, curl only ever connects to the proxy: an origin that is down or slow comes back
     * as an HTTP answer or a failed tunnel from a proxy that did connect. So a refused or timed-out
     * connection that never completed can only be the proxy's.
     *
     * connect_time 0 alone does not prove it: curl also reports 0 when it reuses a pooled connection,
     * and Guzzle wraps any timeout in a ConnectException, so a slow origin on a reused connection would
     * look the same. Not a byte of the request having been sent (request_size 0) is what rules out an
     * established connection, and also guarantees that a retry starts from an empty sink.
     */
    public static function isProxyUnreachable(mixed $reason): bool
    {
        if (!$reason instanceof ConnectException) {
            return false;
        }

        $context = $reason->getHandlerContext();

        return in_array($context['errno'] ?? null, [self::CURLE_COULDNT_CONNECT, self::CURLE_OPERATION_TIMEDOUT], true)
            && isset($context['connect_time'], $context['request_size'])
            && (float)$context['connect_time'] === 0.0
            && $context['request_size'] === 0;
    }

    private function send(callable $handler, RequestInterface $request, array $options, int $attempt): PromiseInterface
    {
        $proxy = $this->quarantine->pick($this->proxies->all());

        return $handler($request, ['proxy' => $proxy] + $options)->then(
            null,
            fn (mixed $reason): PromiseInterface => self::isProxyUnreachable($reason)
                ? $this->retryElsewhere($handler, $request, $options, $attempt, $proxy, $reason)
                : Create::rejectionFor($reason)
        );
    }

    private function retryElsewhere(
        callable $handler,
        RequestInterface $request,
        array $options,
        int $attempt,
        string $proxy,
        ConnectException $failure,
    ): PromiseInterface {
        $strikes = $this->quarantine->punish($proxy);

        $this->logger->warning('Proxy unreachable', [
            'proxy'           => ProxyQuarantine::identify($proxy),
            'error'           => $failure->getMessage(),
            'attempt'         => $attempt,
            'strikes'         => $strikes,
            'penalty_seconds' => $strikes === null ? null : ProxyQuarantine::penaltySeconds($strikes),
            'banned'          => $strikes !== null && ProxyQuarantine::isBan($strikes),
        ]);

        if ($attempt >= $this->maxAttempts) {
            return Create::rejectionFor(ProxyUnreachable::afterAttempts($attempt, $failure));
        }

        return $this->send($handler, $request, $options, $attempt + 1);
    }
}
