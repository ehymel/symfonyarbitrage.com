<?php

namespace App\Service;

/**
 * The answer to "can this venue actually settle the leg we are about to send it?" — coin
 * to deliver on a sell, cash to pay with on a buy.
 *
 * Three cases rather than a boolean, because the two ways of saying no mean opposite
 * things about the venue. Short is our own funding problem and says nothing about the
 * exchange; Unknown means the exchange would not tell us, which is a venue fault. Only
 * the second belongs in the circuit breaker's failure count.
 */
enum FundingVerdict
{
    /** The free balance covers the leg, with any configured margin on top. */
    case Sufficient;

    /** The venue answered, and it does not hold enough to settle its side. */
    case Short;

    /** The venue could not be asked, or answered with something unreadable. */
    case Unknown;
}
