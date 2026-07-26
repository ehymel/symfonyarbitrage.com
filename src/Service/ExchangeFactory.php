<?php

namespace App\Service;

use App\Service\ExchangeService\ExchangeServiceInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

class ExchangeFactory
{
    public function __construct(
        #[AutowireLocator(ExchangeServiceInterface::class)]
        private readonly ContainerInterface $exchanges
    ) {
    }

    public function create(string $exchangeName): ExchangeServiceInterface
    {
        $key = strtolower($exchangeName);

        if (!$this->exchanges->has($key)) {
            throw new \InvalidArgumentException("Unsupported exchange: {$exchangeName}");
        }

        return $this->exchanges->get($key);
    }
}
