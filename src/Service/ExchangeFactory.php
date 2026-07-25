<?php

namespace App\Service;

use CCXT\Exchange;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ExchangeFactory
{
    private readonly array $exchangeCredentials;
    public function __construct(
        #[Autowire(env: 'COINBASE_API_KEY')] private readonly string $coinbase_apiKey,
        #[Autowire(env: 'COINBASE_API_SECRET')] private readonly string $coinbase_apiSecret,
        #[Autowire(env: 'KRAKEN_API_KEY')] private readonly string $kraken_apiKey,
        #[Autowire(env: 'KRAKEN_PRIVATE_KEY')] private readonly string $kraken_apiSecret
    ) {
        $this->exchangeCredentials = [
            'Coinbase' => [
                'apiKey' => $this->coinbase_apiKey,
                'secret' => $this->coinbase_apiSecret,
            ],
            'Kraken' => [
                'apiKey' => $this->kraken_apiKey,
                'secret' => $this->kraken_apiSecret,
            ],
        ];
    }

    public function create(string $exchangeName): Exchange
    {

        $className = "\\CCXT\\" . strtolower($exchangeName);
        if (!class_exists($className)) {
            throw new \InvalidArgumentException("Unsupported exchange: {$exchangeName}");
        }

        $creds = $this->exchangeCredentials[$exchangeName] ?? [];

        return new $className([
            'apiKey' => $creds['api_key'] ?? '',
            'secret' => $creds['api_secret'] ?? '',
            'enableRateLimit' => true,
            'timeout' => 3000, // Strict 3-second network timeout
            'options' => [
                'adjustForTimeDifference' => true,
                'recvWindow' => 5000, // Tolerance window for request latency
            ],
        ]);
    }
}
