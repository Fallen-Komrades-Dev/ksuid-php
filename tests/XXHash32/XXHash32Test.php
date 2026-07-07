<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Tests\XXHash32;

use FallenKomradesDev\KSUID\XXHash32\XXHash32;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FallenKomradesDev\KSUID\XXHash32\XXHash32
 */
final class XXHash32Test extends TestCase
{
    use XXHash32ReferenceVectors;

    public static function referenceVectorProvider(): array
    {
        return self::referenceVectors();
    }

    #[DataProvider('referenceVectorProvider')]
    public function testOneShotChecksumZeroMatchesReferenceVector(int $len, int $expected): void
    {
        $input = self::inputBytes($len);

        $this->assertSame(
            $expected,
            XXHash32::checksumZero($input),
            sprintf('checksumZero mismatch for length %d', $len)
        );
    }

    #[DataProvider('referenceVectorProvider')]
    public function testStreamingSevenByteChunksMatchesReferenceVector(int $len, int $expected): void
    {
        $input = self::inputBytes($len);

        $hasher = new XXHash32();
        $hasher->reset();

        for ($i = 0; $i < strlen($input); $i += 7) {
            $hasher->write(substr($input, $i, 7));
        }

        $this->assertSame(
            $expected,
            $hasher->sum32(),
            sprintf('streaming (7-byte chunks) mismatch for length %d', $len)
        );
    }

    #[DataProvider('referenceVectorProvider')]
    public function testStreamingThreeByteChunksMatchesReferenceVector(int $len, int $expected): void
    {
        $input = self::inputBytes($len);

        $hasher = new XXHash32();
        $hasher->reset();

        for ($i = 0; $i < strlen($input); $i += 3) {
            $hasher->write(substr($input, $i, 3));
        }

        $this->assertSame($expected, $hasher->sum32());
    }

    public function testSingleByteAtATimeStreaming(): void
    {
        $input = self::inputBytes(100);
        $expected = XXHash32::checksumZero($input);

        $hasher = new XXHash32();
        $hasher->reset();

        foreach (str_split($input) as $byte) {
            $hasher->write($byte);
        }

        $this->assertSame($expected, $hasher->sum32());
    }

    public function testEntireInputInOneWrite(): void
    {
        $input = self::inputBytes(200);
        $expected = XXHash32::checksumZero($input);

        $hasher = new XXHash32();
        $hasher->reset();
        $hasher->write($input);

        $this->assertSame($expected, $hasher->sum32());
    }

    public function testZeroLengthWritesAreNoOps(): void
    {
        $input = self::inputBytes(50);
        $expected = XXHash32::checksumZero($input);

        $hasher = new XXHash32();
        $hasher->reset();
        $hasher->write('');
        $hasher->write(substr($input, 0, 20));
        $hasher->write('');
        $hasher->write(substr($input, 20));
        $hasher->write('');

        $this->assertSame($expected, $hasher->sum32());
    }

    public function testResetAllowsHasherReuse(): void
    {
        $hasher = new XXHash32();

        $hasher->reset();
        $hasher->write('first message');
        $firstSum = $hasher->sum32();

        $hasher->reset();
        $hasher->write('second message');
        $secondSum = $hasher->sum32();

        $this->assertSame(XXHash32::checksumZero('first message'), $firstSum);
        $this->assertSame(XXHash32::checksumZero('second message'), $secondSum);
        $this->assertNotSame($firstSum, $secondSum);
    }

    public function testSum32IsIdempotent(): void
    {
        $hasher = new XXHash32();
        $hasher->reset();
        $hasher->write('idempotency check');

        $this->assertSame($hasher->sum32(), $hasher->sum32());
    }

    public function testSizeReturnsFour(): void
    {
        $this->assertSame(4, (new XXHash32())->size());
    }

    public function testBlockSizeReturnsOne(): void
    {
        $this->assertSame(1, (new XXHash32())->blockSize());
    }

    public function testWriteWithoutExplicitResetAutoInitialises(): void
    {
        $hasher = new XXHash32();
        $hasher->write('no explicit reset call');

        $this->assertSame(
            XXHash32::checksumZero('no explicit reset call'),
            $hasher->sum32()
        );
    }

    /**
     * Stress test with irregular, non-uniform chunk sizes including
     * zero-length writes, going well beyond the Go test's fixed 7-byte
     * chunking pattern.
     */
    public function testIrregularChunkSizesAcrossManyPatterns(): void
    {
        $data = self::inputBytes(256);

        $patterns = [
            [1], [2], [5], [13], [16], [17], [33], [255], [256],
            [1, 2, 3, 4, 5], [16, 16, 16, 16], [15, 1, 15, 1], [0, 5, 0, 3],
        ];

        foreach ($patterns as $pattern) {
            foreach ([16, 64, 100, 255, 256] as $totalLen) {
                $input = substr($data, 0, $totalLen);
                $expected = XXHash32::checksumZero($input);

                $hasher = new XXHash32();
                $hasher->reset();

                $pos = 0;
                $patternIndex = 0;
                while ($pos < strlen($input)) {
                    $chunkSize = $pattern[$patternIndex % count($pattern)];
                    $patternIndex++;

                    if ($chunkSize === 0) {
                        $hasher->write('');
                        continue;
                    }

                    $hasher->write(substr($input, $pos, $chunkSize));
                    $pos += $chunkSize;
                }

                $this->assertSame(
                    $expected,
                    $hasher->sum32(),
                    sprintf('mismatch for len=%d pattern=%s', $totalLen, json_encode($pattern))
                );
            }
        }
    }

    public static function knownStringProvider(): array
    {
        return [
            'empty string' => [''],
            'single char' => ['a'],
            'short word' => ['abc'],
            'message digest phrase' => ['message digest'],
            'numeric string' => ['1234567890'],
            'pangram' => ['The quick brown fox jumps over the lazy dog'],
            'pangram with period' => ['The quick brown fox jumps over the lazy dog.'],
        ];
    }

    #[DataProvider('knownStringProvider')]
    public function testStreamingAndOneShotAgreeForKnownStrings(string $input): void
    {
        $hasher = new XXHash32();
        $hasher->reset();

        for ($i = 0; $i < strlen($input); $i += 3) {
            $hasher->write(substr($input, $i, 3));
        }

        $this->assertSame(XXHash32::checksumZero($input), $hasher->sum32());
    }
}
