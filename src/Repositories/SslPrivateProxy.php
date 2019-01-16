<?php

namespace Softonic\Proxy\Guzzle\Middleware\Repositories;

use GuzzleHttp\Client as GuzzleClient;
use Softonic\Proxy\Guzzle\Middleware\Exceptions\ProxiesNotAvailable;
use Softonic\Proxy\Guzzle\Middleware\Interfaces\ProxyInterface;

class SslPrivateProxy implements ProxyInterface
{
    const SSLPRIVATEPROXY_API = 'https://www.sslprivateproxy.com/api/v1/';

    const SSLPRIVATEPROXY_API_ENDPOINT = '/getrandomproxy/';

    /**
     * @var GuzzleClient
     */
    private $client;

    /**
     * @var string
     */
    private $apiKey;

    public function __construct(GuzzleClient $client, string $apiKey)
    {
        $this->client = $client;
        $this->apiKey = $apiKey;
    }

    public function get()
    {
        try {
            $response = $this->client->get(
                self::SSLPRIVATEPROXY_API . $this->apiKey . self::SSLPRIVATEPROXY_API_ENDPOINT
            );

            $responseContent = $response->getBody()->getContents();

            if (empty($responseContent)) {
                throw new ProxiesNotAvailable('Proxy response was not successful');
            }

            list($ip, $port, $username, $password) = explode(':', $responseContent);

            return "http://$username:$password@$ip:$port";
        } catch (\Exception $e) {
            throw new ProxiesNotAvailable($e->getMessage(), 0, $e);
        }
    }
}
