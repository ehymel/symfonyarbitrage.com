<?php

namespace App\Service\ExchangeService;

use ccxt\async\gemini;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTaggedItem(index: 'gemini')]
class GeminiExchangeService extends AbstractCcxtExchangeService
{
    public function __construct(
        #[Autowire(env: 'GEMINI_API_KEY')] string $apiKey,
        #[Autowire(env: 'GEMINI_API_SECRET')] string $apiSecret
    )
    {
        parent::__construct($apiKey, $apiSecret);
    }

    protected static function ccxtClass(): string
    {
        return gemini::class;
    }
}
