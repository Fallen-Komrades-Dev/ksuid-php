<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID;

use FallenKomradesDev\KSUID\Exception\HashMismatchException;
use FallenKomradesDev\KSUID\Exception\InvalidHexCharacterException;
use FallenKomradesDev\KSUID\Exception\InvalidHexLengthException;
use FallenKomradesDev\KSUID\Exception\InvalidLengthException;

/**
 * Encoder provides the binary and hexadecimal codec for Ksuid, ported
 * directly from ksuid-go/encoder.go.
 *
 * The wire format is 16 bytes, big-endian:
 *   bytes  0..3  Timestamp (uint32)
 *   bytes  4..7  Seq       (uint32)
 *   bytes  8..11 Partition (uint32)
 *   bytes 12..15 Hash      (uint32)
 *
 * The hex format is simply the lowercase hex encoding of those 16 bytes,
 * giving a fixed 32-character string.
 */
final class Encoder
{
    private function __construct()
    {
        // Static-only class; matches the free functions in encoder.go.
    }

    /**
     * encodeBinary encodes the Ksuid as 16 bytes of big-endian binary data.
     * Always returns a string with a byte length of exactly 16.
     */
    public static function encodeBinary(Ksuid $k): string
    {
        return pack('N4', $k->timestamp, $k->seq, $k->partition, $k->hash);
    }

    /**
     * encodeHex encodes the Ksuid to a 32-character lowercase hex string.
     */
    public static function encodeHex(Ksuid $k): string
    {
        return bin2hex(self::encodeBinary($k));
    }

    /**
     * decodeBinary decodes a Ksuid from a 16-byte binary string.
     *
     * @throws InvalidLengthException if $data is not exactly 16 bytes
     * @throws HashMismatchException  if the decoded Hash field doesn't match
     *                                 the hash computed from the other fields
     */
    public static function decodeBinary(string $data): Ksuid
    {
        if (strlen($data) !== 16) {
            throw new InvalidLengthException();
        }

        /** @var array{1:int,2:int,3:int,4:int} $fields */
        $fields = unpack('N4', $data);

        $k = new Ksuid(
            timestamp: $fields[1],
            seq: $fields[2],
            partition: $fields[3],
            hash: $fields[4],
        );

        if ($k->computeHash() !== $k->hash) {
            throw new HashMismatchException();
        }

        return $k;
    }

    /**
     * decodeHex decodes a Ksuid from a 32-character hex string.
     *
     * @throws InvalidHexLengthException   if $hex is not exactly 32 characters
     * @throws InvalidHexCharacterException if $hex contains non-hex characters
     * @throws HashMismatchException        if the decoded Hash field doesn't
     *                                       match the hash computed from the
     *                                       other fields
     */
    public static function decodeHex(string $hex): Ksuid
    {
        if (strlen($hex) !== 32) {
            throw new InvalidHexLengthException();
        }

        if (!ctype_xdigit($hex)) {
            throw new InvalidHexCharacterException();
        }

        $binary = hex2bin($hex);

        if ($binary === false || strlen($binary) !== 16) {
            // Defensive: ctype_xdigit + even length already guarantees this
            // succeeds, but Go's analogous check (n != 16) is preserved here.
            throw new InvalidLengthException();
        }

        return self::decodeBinary($binary);
    }
}
