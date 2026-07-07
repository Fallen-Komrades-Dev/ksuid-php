<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Tests;

use FallenKomradesDev\KSUID\Encoder;
use FallenKomradesDev\KSUID\Exception\HashMismatchException;
use FallenKomradesDev\KSUID\Exception\InvalidHexCharacterException;
use FallenKomradesDev\KSUID\Exception\InvalidHexLengthException;
use FallenKomradesDev\KSUID\Exception\InvalidLengthException;
use FallenKomradesDev\KSUID\Ksuid;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Direct port of ksuid-go/encoder_test.go, using the same `tst` fixture.
 *
 * @covers \FallenKomradesDev\KSUID\Encoder
 */
final class EncoderTest extends TestCase
{
    private const TST_TIMESTAMP = 584049083; // 0x22CFE1BB
    private const TST_SEQ       = 0xC0FFEE;
    private const TST_PARTITION = 0xDEADBEEF;
    private const TST_HASH      = 0x355016B7;
    private const TST_HEX       = '22cfe1bb00c0ffeedeadbeef355016b7';

    private function tst(): Ksuid
    {
        return new Ksuid(self::TST_TIMESTAMP, self::TST_SEQ, self::TST_PARTITION, self::TST_HASH);
    }

    public function testEncodeBinaryProducesSixteenBytes(): void
    {
        $binary = Encoder::encodeBinary($this->tst());

        $this->assertSame(16, strlen($binary));
        $this->assertSame(hex2bin(self::TST_HEX), $binary);
    }

    public function testEncodeHexProducesThirtyTwoCharacterLowercaseString(): void
    {
        $hex = Encoder::encodeHex($this->tst());

        $this->assertSame(32, strlen($hex));
        $this->assertSame(self::TST_HEX, $hex);
        $this->assertSame(strtolower($hex), $hex);
    }

    public function testDecodeBinaryRoundTrip(): void
    {
        $binary = Encoder::encodeBinary($this->tst());
        $decoded = Encoder::decodeBinary($binary);

        $this->assertTrue($decoded->equals($this->tst()));
    }

    public function testDecodeHexRoundTrip(): void
    {
        $decoded = Encoder::decodeHex(self::TST_HEX);

        $this->assertTrue($decoded->equals($this->tst()));
    }

    public static function invalidBinaryLengthProvider(): array
    {
        return [
            'empty' => [''],
            'one byte short' => [str_repeat("\x00", 15)],
            'one byte long' => [str_repeat("\x00", 17)],
            'far too short' => [str_repeat("\x00", 3)],
            'far too long' => [str_repeat("\x00", 64)],
        ];
    }

    #[DataProvider('invalidBinaryLengthProvider')]
    public function testDecodeBinaryRejectsWrongLength(string $data): void
    {
        $this->expectException(InvalidLengthException::class);

        Encoder::decodeBinary($data);
    }

    public static function invalidHexLengthProvider(): array
    {
        return [
            'empty' => [''],
            'too short' => ['22cfe1bb'],
            'one char short' => [substr('22cfe1bb00c0ffeedeadbeef355016b7', 0, 31)],
            'one char long' => ['22cfe1bb00c0ffeedeadbeef355016b7' . 'a'],
            'far too long' => [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('invalidHexLengthProvider')]
    public function testDecodeHexRejectsWrongLength(string $hex): void
    {
        $this->expectException(InvalidHexLengthException::class);

        Encoder::decodeHex($hex);
    }

    public static function invalidHexCharacterProvider(): array
    {
        return [
            'all invalid' => [str_repeat('z', 32)],
            'one invalid character' => ['22cfe1bb00c0ffeedeadbeef355016bg'],
            'uppercase is actually fine but special chars are not' => ['22cfe1bb00c0ffeedeadbeef355016b!'],
        ];
    }

    #[DataProvider('invalidHexCharacterProvider')]
    public function testDecodeHexRejectsInvalidCharacters(string $hex): void
    {
        $this->expectException(InvalidHexCharacterException::class);

        Encoder::decodeHex($hex);
    }

    public function testDecodeHexAcceptsUppercaseHex(): void
    {
        $decoded = Encoder::decodeHex(strtoupper(self::TST_HEX));

        $this->assertTrue($decoded->equals($this->tst()));
    }

    public function testDecodeHexAcceptsMixedCaseHex(): void
    {
        $mixed = '22CFe1Bb00C0ffEEdeADbeEF355016b7';
        $decoded = Encoder::decodeHex($mixed);

        $this->assertTrue($decoded->equals($this->tst()));
    }

    public function testDecodeBinaryRejectsTamperedHash(): void
    {
        $binary = Encoder::encodeBinary($this->tst());

        // Flip a bit in the hash field (last 4 bytes) without recomputing it.
        $tampered = substr($binary, 0, 15) . chr(ord($binary[15]) ^ 0x01);

        $this->expectException(HashMismatchException::class);

        Encoder::decodeBinary($tampered);
    }

    public function testDecodeHexRejectsTamperedHash(): void
    {
        // Last hex character flipped, breaking the embedded hash check.
        $tamperedHex = substr(self::TST_HEX, 0, 31) . '0';

        $this->expectException(HashMismatchException::class);

        Encoder::decodeHex($tamperedHex);
    }

    public function testDecodeBinaryRejectsTamperedTimestamp(): void
    {
        $binary = Encoder::encodeBinary($this->tst());

        // Flip a bit in the timestamp field (first 4 bytes); the embedded
        // hash was computed against the original timestamp, so this must
        // now fail hash verification even though length is still correct.
        $tampered = chr(ord($binary[0]) ^ 0x01) . substr($binary, 1);

        $this->expectException(HashMismatchException::class);

        Encoder::decodeBinary($tampered);
    }

    public function testZeroKsuidRoundTrips(): void
    {
        // A bare `new Ksuid()` has Hash=0, but the true xxHash32 of an
        // all-zero 12-byte buffer is NOT zero — so it does not validate by
        // default, exactly like a literal zero-value KSUID{} struct in Go
        // wouldn't either. To round-trip successfully through decodeHex()
        // (which enforces hash verification), we must compute and embed
        // the correct hash for all-zero Timestamp/Seq/Partition fields.
        $zeroData = new Ksuid();
        $zero = new Ksuid(hash: $zeroData->computeHash());

        $hex = Encoder::encodeHex($zero);
        $decoded = Encoder::decodeHex($hex);

        $this->assertTrue($decoded->equals($zero));
        $this->assertTrue($decoded->isZero());
        $this->assertTrue($decoded->validate());
    }

    public function testMaximumValueFieldsRoundTrip(): void
    {
        $max = new Ksuid(timestamp: Ksuid::MASK32, seq: Ksuid::MASK32, partition: Ksuid::MASK32);
        $maxWithHash = new Ksuid(Ksuid::MASK32, Ksuid::MASK32, Ksuid::MASK32, $max->computeHash());

        $hex = Encoder::encodeHex($maxWithHash);
        $decoded = Encoder::decodeHex($hex);

        $this->assertTrue($decoded->equals($maxWithHash));
        $this->assertTrue($decoded->validate());
    }
}
