<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Exception;

/**
 * Thrown when a binary KSUID payload is not exactly 16 bytes long.
 *
 * Mirrors Go's ErrInvalidLength sentinel error.
 */
final class InvalidLengthException extends \InvalidArgumentException
{
    public function __construct(string $message = 'Encoded KSUID must be exactly 16 bytes')
    {
        parent::__construct($message);
    }
}
