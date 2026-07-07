<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Tests\XXHash32;

use FallenKomradesDev\KSUID\XXHash32\XXHash32;
use FallenKomradesDev\KSUID\XXHash32\XXHash32Gmp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * Cross-implementation parity tests: the pure-PHP and GMP-backed XXHash32
 * implementations must always agree, for every input, regardless of
 * which one a consumer happens to pick.
 *
 * @covers \FallenKomradesDev\KSUID\XXHash32\XXHash32
 * @covers \FallenKomradesDev\KSUID\XXHash32\XXHash32Gmp
 */
#[RequiresPhpExtension('gmp')]
final class XXHash32CrossImplementationTest extends TestCase
{
    use XXHash32ReferenceVectors;

    public static function referenceVectorProvider(): array
    {
        return self::referenceVectors();
    }

    #[DataProvider('referenceVectorProvider')]
    public function testBothImplementationsAgreeOneShot(int $len): void
    {
        $input = self::inputBytes($len);

        $this->assertSame(
            XXHash32::checksumZero($input),
            XXHash32Gmp::checksumZero($input)
        );
    }

    public function testBothImplementationsAgreeOnRandomData(): void
    {
        mt_srand(0xDEADBEEF); // deterministic across runs

        for ($trial = 0; $trial < 50; $trial++) {
            $len = mt_rand(0, 500);
            $bytes = '';
            for ($i = 0; $i < $len; $i++) {
                $bytes .= chr(mt_rand(0, 255));
            }

            $this->assertSame(
                XXHash32::checksumZero($bytes),
                XXHash32Gmp::checksumZero($bytes),
                sprintf('mismatch on random trial %d (len=%d)', $trial, $len)
            );
        }
    }

    public function testBothImplementationsAgreeWhenStreamed(): void
    {
        $input = self::inputBytes(300);

        $pure = new XXHash32();
        $pure->reset();

        $gmp = new XXHash32Gmp();
        $gmp->reset();

        $offsets = [5, 11, 16, 7, 0, 3, 258];
        $pos = 0;
        foreach ($offsets as $size) {
            $chunk = substr($input, $pos, $size);
            $pure->write($chunk);
            $gmp->write($chunk);
            $pos += $size;
        }

        $this->assertSame($pure->sum32(), $gmp->sum32());
    }
}
