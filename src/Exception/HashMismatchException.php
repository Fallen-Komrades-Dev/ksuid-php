<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Exception;

/**
 * Thrown when the Hash field decoded from a payload does not match the
 * hash computed from the Timestamp/Seq/Partition fields in that same payload.
 *
 * Mirrors Go's ErrHashMismatch sentinel error.
 */
final class HashMismatchException extends \RuntimeException
{
    public function __construct(string $message = "The calculated hash of the data doesn't match hash in the data")
    {
        parent::__construct($message);
    }
}
