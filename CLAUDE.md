# symfonyarbitrage.com

A cross-exchange cryptocurrency arbitrage system: it watches order books on several venues at
once, spots spreads that stay profitable after fees, and executes both legs simultaneously.

**This trades real money.** `.env.local` holds live Coinbase and Kraken API credentials and
`ExecuteArbitrageHandler` places real market orders. A bug here does not produce a stack
trace — it produces a position. Nothing in this repo should be verified by "running it and
seeing what happens" against live credentials; use the test suite and `--limit`.

The repo has two halves. The trading pipeline (`src/Command`, `src/Service`, `src/Message*`)
is the part that moves money. The rest is a conventional Symfony web app around it — dashboard,
admin, and an authentication stack with passkeys, TOTP/email 2FA and Turnstile.

## Stack

PHP 8.4+ (8.5 in dev) · Symfony 8.1 · Doctrine ORM 3 · PostgreSQL · Redis (Messenger transport)
· ccxt 4.5 async client over ReactPHP · PHPUnit 13 · Windows dev environment.

## Commands

```bash
php vendor/bin/phpunit                          # ~300 tests, ~5s
php vendor/bin/phpunit tests/Service/Foo.php    # one file

php bin/console app:arbitrage:scan --limit=1    # one scan cycle, no infinite loop
php bin/console app:arbitrage:scan -s 25 -m 0.5 # $25 positions, 0.5% minimum net margin
php bin/console messenger:consume async_trading # the worker that actually executes
php bin/console app:test:coinbase               # prints a live balance; proves credentials
php bin/console app:test:kraken

php bin/console debug:container "App\Service\Foo"   # verify wiring and resolved arguments
php bin/console debug:container --env-vars          # verify env vars resolve
```

Detection and execution are separate processes. The scanner only dispatches messages; without
a `messenger:consume async_trading` worker running, nothing trades.

## The trading pipeline

```
ArbitrageDetectionScannerCommand      loops every 250ms
  └─ ExchangeWarmer                   pre-loads ccxt market metadata once, off the hot path
  └─ OrderBookFetcher                 reads every venue concurrently (skew between books is
                                      itself a source of phantom spreads)
  └─ ArbitrageEvaluator               walks the book levels, subtracts both taker fees,
                                      returns an opportunity or null
  └─ ExecuteArbitrageMessage  ──────► async_trading (Redis)
                                        │
ExecuteArbitrageHandler ◄───────────────┘
  1. TradingCircuitBreaker    both venues must be closed          (local)
  2. MinimumOrderSizeGuard    both legs must clear the venue floor (local)
  3. TradeFundingGuard        both venues must be able to settle their leg (network)
  4. both legs dispatched concurrently, then settled together
  5. partial fill → emergency unwind
  6. TradeExecution row written, whatever happened
```

The gates run cheapest first: two local lookups before the balance round trip, so nothing
spends a network call on a trade that was already disqualified.

`ExchangeServiceInterface` is the only thing that talks to a venue. Every method has an async
twin (`getBalanceAsync`, `getOrderBookAsync`, `executeMarketOrderAsync`); the synchronous ones
are `await()` wrappers. Venues are shared Symfony services resolved through `ExchangeFactory`,
so the warmed ccxt client is the same object the scan later uses — a factory that minted a new
instance per call would make the warm-up silently pointless.

## Risk invariants

These are load-bearing. Each is enforced in code and pinned by a test; changing one is a
deliberate risk decision, not a refactor.

- **Nothing is committed before both gates pass.** Circuit breaker first (free, local), then
  the funding check (network). Either one failing cancels the *whole* trade — a buy with no
  exit is not an arbitrage, and neither is an exit with nothing to sell.
- **Neither leg may be abandoned while its order is live.** The handler settles both legs
  rather than handing them to `all()`, which would return on the first rejection and leave a
  position nobody is tracking.
- **A partial fill is unwound immediately, and the unwind is never gated** — not by the
  breaker, not by the funding check. It reduces risk rather than taking it, and the venue
  holding the position may be the one the incident just tripped.
- **The ledger row always gets written.** Breaker updates go through `guardBreaker()` and SMS
  through a swallowing wrapper precisely so that a cache blip or a texting outage cannot abort
  the handler before it records what happened. A degraded breaker is a problem; losing the
  trade record is worse.
- **Statuses distinguish a flattened position from an open one** (`PARTIAL_BUY_UNWOUND` vs
  `PARTIAL_BUY_UNWIND_FAILED`) and must fit `TradeExecution::status`, length 30.
- **P&L is never invented.** The quoted price is only a legitimate fallback for a trade that
  executed as quoted; on an unwound leg it would turn a realized loss into a fictional profit.
- **Free balance, never total.** Coin committed to open orders cannot be sold twice.
- **A venue that will not report its balance is not tradeable.** Fail closed; skipping costs
  one opportunity, guessing costs a partial fill.
- **An order below a venue's published size floor is never sent.** `MinimumOrderSizeGuard`
  reads `limits.amount.min` and `limits.cost.min` from warmed market metadata. The one
  deliberate exception to failing closed: *missing* metadata lets the leg through, because
  warming is best effort, ccxt reloads lazily at order time, and blocking everything over a
  boot hiccup is the worse failure.
- **Misconfigured risk controls refuse to start.** A negative safety margin, a non-positive
  position size or an unreadable `--min-margin` stops the process while a human is still
  looking at the terminal, rather than throwing four times a second from inside a loop.

## Conventions

**Comments explain why something is safe, not what the code does.** Every non-obvious branch
says what goes wrong without it and why the alternative was rejected. `ExecuteArbitrageHandler`
and `TradeFundingGuard` are the reference examples. Comments that narrate the code are not the
house style and should not be added.

**Tests are named after behaviour, not methods** — `testCoinLockedInOpenOrdersDoesNotCountTowardsTheSell`,
`testAnOrphanedBuyIsUnwoundWithAMarketSellOnTheSameVenue` — grouped under banner comments by
scenario, with a docblock on the subtle ones stating what a wrong answer would cost.

**Real collaborators where they are pure logic; doubles only at the edges.** `ExchangeFactory`,
`ArbitrageEvaluator`, `AdminAlerter` and an `ArrayAdapter` cache run for real in tests; venues,
the circuit breaker, the message bus and the entity manager are stubbed. Venue doubles settle
on React loop timers so concurrency is genuinely exercised rather than assumed.

**Alerting is tiered.** The log records; `AdminAlerter` (SMS + email) is reserved for things a
human must act on, and is always throttled or one-shot. A pager that fires on routine events is
a pager nobody reads.

## Gotchas

- **`lint:container` always fails** on a webauthn alias for the deprecated
  `PublicKeyCredentialSourceRepositoryInterface`. Pre-existing and unrelated to whatever you
  just changed — the real container compiles. Use `debug:container` instead.
- **`ArbitrageEvaluator::evaluate()`'s `$minNetMarginPct` takes a fraction**, not a percentage,
  despite the name — `0.0035` is 0.35%. The `--min-margin` CLI option takes a percentage and
  divides at the call site. Adding a second caller? Rename the parameter rather than duplicate
  the trap.
- **The funding check has a known check-to-order race.** Balances are read, not reserved, so
  concurrent workers on the same pair can clear against the same funds. Accepted deliberately;
  the fix is a reserved-balance ledger, which would also make balance caching safe. Caching
  balances behind a flat TTL was considered and rejected — our own trades are what invalidate
  them.
- **`cache.app` is shared** between the circuit breaker's state machine and the funding guard's
  alert throttle. Clearing it resets both.
- **ccxt's `timeout` option is a trap.** It is documented in milliseconds and passed straight
  into `React\Socket\Connector`, which reads it as seconds; ccxt never sets a request timeout
  on the `Browser` at all. `AbstractCcxtExchangeService` therefore builds its own connector and
  wraps each call in `React\Promise\Timer\timeout()`. Do not "simplify" that back to ccxt's own
  setting. The budgets are asymmetric on purpose — see the constructor.
- **ReactPHP does its own DNS**, bypassing `getaddrinfo` and so RFC 6724 source-address
  selection. On a host with no IPv6, Happy Eyeballs still resolves and dials the AAAA records
  Cloudflare publishes for `api.coinbase.com`, failing with `EADDRNOTAVAIL` on every request —
  suppressed by React, logged at debug by Symfony, and drowning `var/log/dev.log`. The
  connector is built with `happy_eyeballs => false` for that reason.
- **`.env` declares every variable; `.env.local` holds the real values** and is gitignored.
  Adding an `#[Autowire(env: ...)]` means adding the key to `.env` or the container will not
  compile.
