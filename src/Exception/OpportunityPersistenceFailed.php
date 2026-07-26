<?php

namespace App\Exception;

/**
 * An opportunity was detected but could not be written to the database.
 *
 * Distinct from the scanner's other failures because the recovery differs: a venue that
 * will not answer costs one comparison, whereas a database that will not accept writes
 * means nothing detected from here on can be recorded or executed. The caller needs to
 * tell those apart to decide between carrying on and stopping.
 */
final class OpportunityPersistenceFailed extends \RuntimeException
{
}
