<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Exception;

/**
 * Thrown when a hex-encoded KSUID string is not exactly 32 characters long.
 *
 * Mirrors Go's ErrInvalidHexLength sentinel error.
 */
final class InvalidHexLengthException extends \InvalidArgumentException
{
    public function __construct(string $message = 'Encoded hex KSUID must be exactly 32 characters')
    {
        parent::__construct($message);
    }
}
