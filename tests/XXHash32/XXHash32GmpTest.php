<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Tests\XXHash32;

use FallenKomradesDev\KSUID\XXHash32\XXHash32Gmp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FallenKomradesDev\KSUID\XXHash32\XXHash32Gmp
 */
#[RequiresPhpExtension('gmp')]
final class XXHash32GmpTest extends TestCase
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
            XXHash32Gmp::checksumZero($input),
            sprintf('checksumZero mismatch for length %d', $len)
        );
    }

    #[DataProvider('referenceVectorProvider')]
    public function testStreamingSevenByteChunksMatchesReferenceVector(int $len, int $expected): void
    {
        $input = self::inputBytes($len);

        $hasher = new XXHash32Gmp();
        $hasher->reset();

        for ($i = 0; $i < strlen($input); $i += 7) {
            $hasher->write(substr($input, $i, 7));
        }

        $this->assertSame($expected, $hasher->sum32());
    }

    public function testZeroLengthWritesAreNoOps(): void
    {
        $input = self::inputBytes(50);
        $expected = XXHash32Gmp::checksumZero($input);

        $hasher = new XXHash32Gmp();
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
        $hasher = new XXHash32Gmp();

        $hasher->reset();
        $hasher->write('first message');
        $firstSum = $hasher->sum32();

        $hasher->reset();
        $hasher->write('second message');
        $secondSum = $hasher->sum32();

        $this->assertSame(XXHash32Gmp::checksumZero('first message'), $firstSum);
        $this->assertSame(XXHash32Gmp::checksumZero('second message'), $secondSum);
        $this->assertNotSame($firstSum, $secondSum);
    }

    public function testSizeReturnsFour(): void
    {
        $this->assertSame(4, (new XXHash32Gmp())->size());
    }

    public function testBlockSizeReturnsOne(): void
    {
        $this->assertSame(1, (new XXHash32Gmp())->blockSize());
    }

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
                $expected = XXHash32Gmp::checksumZero($input);

                $hasher = new XXHash32Gmp();
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
}
