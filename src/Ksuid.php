<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID;

use DateTimeImmutable;
use DateTimeZone;
use FallenKomradesDev\KSUID\XXHash32\XXHash32;

/**
 * Ksuid is the main representation of a K-Sortable Unique Identifier.
 *
 * It combines a timestamp (second precision, relative to EPOCH), a sequence
 * number, and a partition ID to create sortable, unique identifiers suitable
 * for distributed systems. This is a direct, behaviour-preserving port of
 * the Go `KSUID` struct from ksuid-go/ksuid.go.
 *
 * All four fields (Timestamp, Seq, Partition, Hash) are conceptually
 * unsigned 32-bit integers. PHP has no native uint32 type, so they are
 * stored as ordinary `int`s constrained to the range [0, 0xFFFFFFFF]; on
 * 64-bit PHP builds (the only realistic target today) this range fits
 * comfortably inside a native int with no precision loss.
 *
 * Instances are immutable: every "mutating" operation other than
 * construction is unavailable by design, matching the Go value-type
 * semantics where KSUID is passed and returned by value.
 */
final class Ksuid implements \JsonSerializable, \Stringable
{
    public const MASK32 = 0xFFFFFFFF;

    /**
     * Epoch is the base timestamp (Unix seconds) from which KSUID timestamps
     * are calculated. Defaults to 2000-01-01T00:00:00Z, mirroring ksuid-go's
     * package-level `Epoch` variable.
     *
     * Stored as a static property (rather than a class constant) so it can
     * be overridden, exactly like the mutable package-level var in Go.
     * Changing this value affects the interpretation of every KSUID's Time().
     */
    private static ?int $epoch = null;

    public readonly int $timestamp;
    public readonly int $seq;
    public readonly int $partition;
    public readonly int $hash;

    public function __construct(int $timestamp = 0, int $seq = 0, int $partition = 0, int $hash = 0)
    {
        $this->timestamp = $timestamp & self::MASK32;
        $this->seq       = $seq & self::MASK32;
        $this->partition = $partition & self::MASK32;
        $this->hash      = $hash & self::MASK32;
    }

    /**
     * getEpoch returns the current Epoch value (Unix seconds).
     */
    public static function getEpoch(): int
    {
        if (self::$epoch === null) {
            // 2000-01-01T00:00:00Z, computed without relying on the server's
            // default timezone.
            self::$epoch = (new DateTimeImmutable('2000-01-01T00:00:00+00:00'))->getTimestamp();
        }

        return self::$epoch;
    }

    /**
     * setEpoch overrides the Epoch value used by time(). Mirrors assigning
     * directly to the package-level `Epoch` var in Go.
     */
    public static function setEpoch(int $epochUnixSeconds): void
    {
        self::$epoch = $epochUnixSeconds;
    }

    /**
     * resetEpoch restores the default 2000-01-01T00:00:00Z epoch.
     * Not present in the Go library (Go has no equivalent "reset", since
     * tests just restore the var manually) but provided here for test
     * hygiene given PHP's static state persists across test methods.
     */
    public static function resetEpoch(): void
    {
        self::$epoch = null;
    }

    /**
     * time returns the UTC DateTimeImmutable this KSUID was generated at.
     */
    public function time(): DateTimeImmutable
    {
        $unixSeconds = $this->timestamp + self::getEpoch();

        return (new DateTimeImmutable('@' . $unixSeconds))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * binary returns the 16-byte big-endian binary encoding of this KSUID.
     * Alias for Encoder::encodeBinary($this).
     */
    public function binary(): string
    {
        return Encoder::encodeBinary($this);
    }

    /**
     * toString returns the KSUID as a 32-character lowercase hex string.
     * Alias for Encoder::encodeHex($this).
     */
    public function toString(): string
    {
        return Encoder::encodeHex($this);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * isZero returns true if this KSUID is the zero value (all data fields
     * are zero). Hash is intentionally excluded — use validate() to check
     * integrity instead.
     */
    public function isZero(): bool
    {
        return $this->timestamp === 0 && $this->seq === 0 && $this->partition === 0;
    }

    /**
     * validate returns true if the Hash field matches the computed hash of
     * the other three fields.
     */
    public function validate(): bool
    {
        return $this->computeHash() === $this->hash;
    }

    /**
     * compare compares two KSUIDs and returns:
     *   -1 if $this < $other
     *    0 if $this == $other
     *   +1 if $this > $other
     *
     * KSUIDs are ordered first by timestamp, then by partition, then by
     * sequence (matching Go's Compare()).
     */
    public function compare(Ksuid $other): int
    {
        if ($this->timestamp !== $other->timestamp) {
            return $this->timestamp < $other->timestamp ? -1 : 1;
        }

        if ($this->partition !== $other->partition) {
            return $this->partition < $other->partition ? -1 : 1;
        }

        if ($this->seq !== $other->seq) {
            return $this->seq < $other->seq ? -1 : 1;
        }

        return 0;
    }

    /**
     * equals returns true if every field (including Hash) is identical.
     * Equivalent to Go's `k == other` struct comparison.
     */
    public function equals(Ksuid $other): bool
    {
        return $this->timestamp === $other->timestamp
            && $this->seq === $other->seq
            && $this->partition === $other->partition
            && $this->hash === $other->hash;
    }

    /**
     * jsonSerialize implements JsonSerializable, encoding the KSUID as its
     * 32-character hex string. Mirrors Go's MarshalText/UnmarshalText being
     * picked up automatically by encoding/json.
     */
    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    /**
     * fromJson decodes a JSON string (e.g. `"22cfe1bb...016b7"` including
     * the surrounding quotes) back into a Ksuid. Mirrors Go's
     * UnmarshalText being invoked automatically by encoding/json.
     *
     * @throws Exception\InvalidHexLengthException
     * @throws Exception\InvalidHexCharacterException
     * @throws Exception\HashMismatchException
     */
    public static function fromJson(string $json): Ksuid
    {
        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_string($decoded)) {
            throw new \InvalidArgumentException('JSON value for Ksuid must be a string');
        }

        return Encoder::decodeHex($decoded);
    }

    /**
     * computeHash calculates the xxHash32 value of the first 12 bytes
     * (Timestamp, Seq, Partition; each big-endian uint32).
     */
    public function computeHash(): int
    {
        $buf = pack('N3', $this->timestamp, $this->seq, $this->partition);

        return XXHash32::checksumZero($buf);
    }
}
