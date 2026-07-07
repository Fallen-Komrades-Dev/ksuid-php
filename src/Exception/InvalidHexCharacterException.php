<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Exception;

/**
 * Thrown when decoding a hex string that contains non-hexadecimal characters.
 *
 * Go's hex.Decode returns a generic CorruptInputError in this situation;
 * this is the PHP equivalent for invalid hex content (as opposed to invalid
 * hex *length*, which is InvalidHexLengthException).
 */
final class InvalidHexCharacterException extends \InvalidArgumentException
{
    public function __construct(string $message = 'Encoded KSUID hex string contains invalid hexadecimal characters')
    {
        parent::__construct($message);
    }
}
