<?php
declare(strict_types=1);

namespace App\Tests\Service\ExchangeService;

use App\Service\ExchangeService\AbstractCcxtExchangeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;

use function React\Async\await;

/**
 * ccxt states its timeout in milliseconds and hands it to ReactPHP unconverted, where it
 * is read as seconds — so the "3 second" setting asked for a 3,000-second connect timeout
 * and the only real ceiling was PHP's default_socket_timeout. These tests pin the ceilings
 * that replaced it, and in particular pin that they are *not* uniform: abandoning a read
 * costs nothing, while abandoning an order that then fills leaves a position nobody knows
 * about.
 *
 * The venue double never answers, so every case here is the hang these bounds exist for.
 */
#[CoversClass(AbstractCcxtExchangeService::class)]
final class CcxtTimeoutTest extends TestCase
{
    /** Short enough to keep the suite fast, long enough not to race a busy machine. */
    private const float READ_TIMEOUT = 0.05;
    private const float ORDER_TIMEOUT = 0.15;

    public function testAnOrderBookReadThatNeverAnswersIsAbandoned(): void
    {
        $this->expectException(TimeoutException::class);

        await($this->venue()->getOrderBookAsync('ETH/USDT'));
    }

    public function testABalanceReadThatNeverAnswersIsAbandoned(): void
    {
        $this->expectException(TimeoutException::class);

        await($this->venue()->getBalanceAsync());
    }

    public function testAnOrderThatNeverAnswersIsEventuallyAbandonedToo(): void
    {
        $this->expectException(TimeoutException::class);

        await($this->venue()->executeMarketOrderAsync('ETH/USDT', 'buy', 1.0));
    }

    /**
     * The asymmetry is the point. A read given up on costs a scan cycle; an order given up
     * on can still fill at the venue, and the handler would unwind the other leg against a
     * position that is actually open. So the order budget has to be the looser of the two.
     */
    public function testAnOrderIsGivenLongerToAnswerThanARead(): void
    {
        $venue = $this->venue();

        $readElapsed = $this->secondsUntilTimeout(fn (): PromiseInterface => $venue->getOrderBookAsync('ETH/USDT'));
        $orderElapsed = $this->secondsUntilTimeout(
            fn (): PromiseInterface => $venue->executeMarketOrderAsync('ETH/USDT', 'buy', 1.0)
        );

        self::assertGreaterThan(
            $readElapsed,
            $orderElapsed,
            'an order must not be abandoned on the same hair trigger as a market data read'
        );
        self::assertGreaterThanOrEqual(self::ORDER_TIMEOUT * 0.8, $orderElapsed);
    }

    /**
     * A bad side is a programming error and is rejected before anything is dispatched, so
     * it must not be dressed up as a rejected promise the caller has to unwrap.
     */
    public function testAnInvalidSideStillThrowsBeforeAnythingIsDispatched(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->venue()->executeMarketOrderAsync('ETH/USDT', 'sideways', 1.0);
    }

    // -------------------------------------------------------------------- HELPERS

    private function secondsUntilTimeout(\Closure $call): float
    {
        $start = microtime(true);

        try {
            await($call());
        } catch (TimeoutException) {
            // expected
        }

        return microtime(true) - $start;
    }

    private function venue(): AbstractCcxtExchangeService
    {
        return new class ('key', 'secret', self::READ_TIMEOUT, self::ORDER_TIMEOUT) extends AbstractCcxtExchangeService {
            public function __construct(string $apiKey, string $apiSecret, float $read, float $order)
            {
                parent::__construct(
                    $apiKey,
                    $apiSecret,
                    connectTimeoutSeconds: 1.0,
                    readTimeoutSeconds: $read,
                    orderTimeoutSeconds: $order,
                );
            }

            protected static function ccxtClass(): string
            {
                return SilentCoinbase::class;
            }
        };
    }
}

/**
 * A ccxt client that accepts every call and answers none of them, standing in for a venue
 * that has gone quiet mid-request — the case a connect timeout cannot catch, because the
 * connection was established before anything went wrong.
 */
final class SilentCoinbase extends \ccxt\async\coinbase
{
    public function fetch_order_book(string $symbol, ?int $limit = null, $params = array()): PromiseInterface
    {
        return (new Deferred())->promise();
    }

    public function fetch_balance($params = array()): PromiseInterface
    {
        return (new Deferred())->promise();
    }

    public function create_order(
        string $symbol,
        string $type,
        string $side,
        float $amount,
        ?float $price = null,
        $params = array()
    ) {
        return (new Deferred())->promise();
    }
}
