<?php

namespace App\Service;

use App\Service\ExchangeService\ExchangeServiceInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

readonly class ExchangeFactory
{
    public function __construct(
        #[AutowireLocator(ExchangeServiceInterface::class)]
        private ServiceProviderInterface $exchanges
    ) {
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function create(string $exchangeName): ExchangeServiceInterface
    {
        $key = strtolower($exchangeName);

        if (!$this->exchanges->has($key)) {
            throw new \InvalidArgumentException("Unsupported exchange: {$exchangeName}");
        }

        return $this->exchanges->get($key);
    }

    /**
     * Every venue wired into the locator, by tag index.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->exchanges->getProvidedServices());
    }
}
