<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\XXHash32;

/**
 * XXHash32Gmp - GMP-backed implementation of xxHash32 (seed = 0).
 *
 * Semantically identical to XXHash32 but uses PHP's GMP extension for all
 * 32-bit arithmetic so it is safe on both 32-bit and 64-bit PHP builds and
 * avoids any signed-integer edge cases entirely.
 *
 * Requires: ext-gmp
 */
final class XXHash32Gmp
{
    // Primes as GMP objects, initialised lazily via self::prime().
    private static array $primes = [];

    // Mask for unsigned 32-bit values: 2^32 - 1
    private static ?\GMP $mask32 = null;

    // ------------------------------------------------------------------
    // Instance state (incremental hashing)
    // ------------------------------------------------------------------

    /** @var \GMP[] Four 32-bit GMP accumulators */
    private array $v = [];

    private string $buf      = '';
    private int    $totalLen = 0;
    private bool   $initialized = false;

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    public function reset(): void
    {
        $this->v           = self::initAccumulators();
        $this->buf         = '';
        $this->totalLen    = 0;
        $this->initialized = true;
    }

    public function write(string $input): void
    {
        if (!$this->initialized) {
            $this->reset();
        }

        $this->totalLen += strlen($input);
        $this->buf      .= $input;

        if (strlen($this->buf) < 16) {
            return;
        }

        $offset = 0;
        $bufLen = strlen($this->buf);

        while ($offset + 16 <= $bufLen) {
            $this->mixBlock(substr($this->buf, $offset, 16));
            $offset += 16;
        }

        $this->buf = substr($this->buf, $offset);
    }

    public function sum32(): int
    {
        return gmp_intval($this->computeSum());
    }

    public function size(): int      { return 4; }
    public function blockSize(): int { return 1; }

    // ------------------------------------------------------------------
    // Static one-shot
    // ------------------------------------------------------------------

    public static function checksumZero(string $input): int
    {
        $len    = strlen($input);
        $mask   = self::mask32();
        $h32    = gmp_and(gmp_init($len), $mask); // h32 = len & 0xFFFFFFFF
        $offset = 0;

        if ($len >= 16) {
            [$v1, $v2, $v3, $v4] = self::initAccumulators();

            while ($offset + 16 <= $len) {
                [$v1, $v2, $v3, $v4] = self::mixBlockStatic($v1, $v2, $v3, $v4, $input, $offset);
                $offset += 16;
            }

            $h32 = gmp_and(
                gmp_add(
                    gmp_add(
                        gmp_add(
                            gmp_add($h32, self::rotl($v1, 1)),
                            self::rotl($v2, 7)
                        ),
                        self::rotl($v3, 12)
                    ),
                    self::rotl($v4, 18)
                ),
                $mask
            );
        } else {
            $h32 = gmp_and(gmp_add($h32, self::prime(5)), $mask);
        }

        // Remaining 4-byte words
        while ($offset + 4 <= $len) {
            $word = unpack('V', substr($input, $offset, 4))[1];
            $tmp  = gmp_and(gmp_add($h32, self::mask32val(gmp_mul(gmp_init($word), self::prime(3)))), $mask);
            $h32  = self::mask32val(gmp_mul(self::rotl($tmp, 17), self::prime(4)));
            $offset += 4;
        }

        // Remaining bytes
        while ($offset < $len) {
            $byte = ord($input[$offset]);
            $tmp  = gmp_and(gmp_add($h32, self::mask32val(gmp_mul(gmp_init($byte), self::prime(5)))), $mask);
            $h32  = self::mask32val(gmp_mul(self::rotl($tmp, 11), self::prime(1)));
            $offset++;
        }

        return gmp_intval(self::avalanche($h32));
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    private function mixBlock(string $block): void
    {
        $data = unpack('V4', $block);

        $this->v[0] = self::mask32val(gmp_mul(
            self::rotl(self::mask32val(gmp_add($this->v[0], self::mask32val(gmp_mul(gmp_init($data[1]), self::prime(2))))), 13),
            self::prime(1)
        ));
        $this->v[1] = self::mask32val(gmp_mul(
            self::rotl(self::mask32val(gmp_add($this->v[1], self::mask32val(gmp_mul(gmp_init($data[2]), self::prime(2))))), 13),
            self::prime(1)
        ));
        $this->v[2] = self::mask32val(gmp_mul(
            self::rotl(self::mask32val(gmp_add($this->v[2], self::mask32val(gmp_mul(gmp_init($data[3]), self::prime(2))))), 13),
            self::prime(1)
        ));
        $this->v[3] = self::mask32val(gmp_mul(
            self::rotl(self::mask32val(gmp_add($this->v[3], self::mask32val(gmp_mul(gmp_init($data[4]), self::prime(2))))), 13),
            self::prime(1)
        ));
    }

    /** @return \GMP[] [v1,v2,v3,v4] */
    private static function mixBlockStatic(\GMP $v1, \GMP $v2, \GMP $v3, \GMP $v4, string $input, int $offset): array
    {
        $data = unpack('V4', substr($input, $offset, 16));

        $v1 = self::mask32val(gmp_mul(
            self::rotl(self::mask32val(gmp_add($v1, self::mask32val(gmp_mul(gmp_init($data[1]), self::prime(2))))), 13),
            self::prime(1)
        ));
        $v2 = self::mask32val(gmp_mul(
            self::rotl(self::mask32val(gmp_add($v2, self::mask32val(gmp_mul(gmp_init($data[2]), self::prime(2))))), 13),
            self::prime(1)
        ));
        $v3 = self::mask32val(gmp_mul(
            self::rotl(self::mask32val(gmp_add($v3, self::mask32val(gmp_mul(gmp_init($data[3]), self::prime(2))))), 13),
            self::prime(1)
        ));
        $v4 = self::mask32val(gmp_mul(
            self::rotl(self::mask32val(gmp_add($v4, self::mask32val(gmp_mul(gmp_init($data[4]), self::prime(2))))), 13),
            self::prime(1)
        ));

        return [$v1, $v2, $v3, $v4];
    }

    private function computeSum(): \GMP
    {
        $mask = self::mask32();
        $h32  = gmp_and(gmp_init($this->totalLen), $mask);

        if ($this->totalLen >= 16) {
            $h32 = gmp_and(
                gmp_add(
                    gmp_add(
                        gmp_add(
                            gmp_add($h32, self::rotl($this->v[0], 1)),
                            self::rotl($this->v[1], 7)
                        ),
                        self::rotl($this->v[2], 12)
                    ),
                    self::rotl($this->v[3], 18)
                ),
                $mask
            );
        } else {
            $h32 = gmp_and(gmp_add($h32, self::prime(5)), $mask);
        }

        $buf    = $this->buf;
        $bufLen = strlen($buf);
        $offset = 0;

        while ($offset + 4 <= $bufLen) {
            $word  = unpack('V', substr($buf, $offset, 4))[1];
            $tmp   = gmp_and(gmp_add($h32, self::mask32val(gmp_mul(gmp_init($word), self::prime(3)))), $mask);
            $h32   = self::mask32val(gmp_mul(self::rotl($tmp, 17), self::prime(4)));
            $offset += 4;
        }

        while ($offset < $bufLen) {
            $byte  = ord($buf[$offset]);
            $tmp   = gmp_and(gmp_add($h32, self::mask32val(gmp_mul(gmp_init($byte), self::prime(5)))), $mask);
            $h32   = self::mask32val(gmp_mul(self::rotl($tmp, 11), self::prime(1)));
            $offset++;
        }

        return self::avalanche($h32);
    }

    // ------------------------------------------------------------------
    // GMP utility helpers
    // ------------------------------------------------------------------

    /** Lazy-init cache for the five XXHash primes as GMP objects. */
    private static function prime(int $n): \GMP
    {
        if (!isset(self::$primes[$n])) {
            $values = [
                1 => 0x9E3779B1,
                2 => 0x85EBCA77,
                3 => 0xC2B2AE3D,
                4 => 0x27D4EB2F,
                5 => 0x165667B1,
            ];
            self::$primes[$n] = gmp_init($values[$n]);
        }
        return self::$primes[$n];
    }

    private static function mask32(): \GMP
    {
        if (self::$mask32 === null) {
            self::$mask32 = gmp_init('4294967295'); // 2^32 - 1
        }
        return self::$mask32;
    }

    /** Apply 32-bit unsigned mask to a GMP value. */
    private static function mask32val(\GMP $v): \GMP
    {
        return gmp_and($v, self::mask32());
    }

    /** Left-rotate a GMP value within 32 bits: (val << shift) | (val >> (32 - shift)). */
    private static function rotl(\GMP $val, int $shift): \GMP
    {
        $val   = gmp_and($val, self::mask32());
        $left  = gmp_and(gmp_mul($val, gmp_pow(gmp_init(2), $shift)), self::mask32());
        $right = gmp_div_q($val, gmp_pow(gmp_init(2), 32 - $shift));
        return gmp_and(gmp_add($left, $right), self::mask32());
    }

    /** Final mixing / avalanche. */
    private static function avalanche(\GMP $h32): \GMP
    {
        $mask = self::mask32();
        $h32  = gmp_xor($h32, gmp_div_q($h32, gmp_pow(gmp_init(2), 15)));
        $h32  = gmp_and(gmp_mul($h32, self::prime(2)), $mask);
        $h32  = gmp_xor($h32, gmp_div_q($h32, gmp_pow(gmp_init(2), 13)));
        $h32  = gmp_and(gmp_mul($h32, self::prime(3)), $mask);
        $h32  = gmp_xor($h32, gmp_div_q($h32, gmp_pow(gmp_init(2), 16)));
        return gmp_and($h32, $mask);
    }

    /** @return \GMP[] [v1,v2,v3,v4] */
    private static function initAccumulators(): array
    {
        // prime1 + prime2 mod 2^32
        $v1 = self::mask32val(gmp_add(self::prime(1), self::prime(2)));
        $v2 = self::prime(2);
        $v3 = gmp_init(0);
        // -prime1 mod 2^32 = 2^32 - prime1
        $v4 = gmp_sub(gmp_pow(gmp_init(2), 32), self::prime(1));
        return [$v1, $v2, $v3, $v4];
    }
}
