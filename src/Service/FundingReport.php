<?php

namespace App\Service;

/**
 * Both legs' funding verdicts from a single pre-trade check.
 *
 * Kept together rather than returned one at a time because the two reads happen
 * concurrently, and because the caller has to act on both: either leg falling short
 * cancels the whole trade, but only the legs whose balance was *unreadable* are charged
 * to their venue's circuit breaker.
 */
readonly class FundingReport
{
    public function __construct(
        /** Can the buy venue pay for the coin? */
        public FundingVerdict $buy,
        /** Can the sell venue deliver it? */
        public FundingVerdict $sell,
    ) {
    }

    public function bothLegsCleared(): bool
    {
        return $this->buy === FundingVerdict::Sufficient
            && $this->sell === FundingVerdict::Sufficient;
    }
}
