<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\XXHash32;

/**
 * XXHash32 - pure-PHP implementation (seed = 0).
 *
 * Ported from the reference C implementation (https://github.com/Cyan4973/xxHash)
 * and the Go port in xxhash32/xxhash32.go.
 *
 * All arithmetic is performed as unsigned 32-bit integers via masking with
 * 0xFFFFFFFF so the implementation works correctly on 64-bit PHP without any
 * GMP or bcmath dependency.
 *
 * IMPORTANT: PHP ints are 64-bit signed, but PHP silently promotes integer
 * multiplication to float once the mathematical result exceeds PHP_INT_MAX
 * (~9.22e18). Two 32-bit values can multiply to ~1.84e19, which is well past
 * that limit, so naive `($a * $b) & 0xFFFFFFFF` is NOT safe here.
 *
 * The safe pattern used throughout is to split $a into 16-bit halves and
 * multiply each half by the (known, constant) prime separately:
 *
 *   $aLo = $a & 0xFFFF;  $aHi = $a >> 16;
 *   result = ((($aLo * PRIME) & 0xFFFFFFFF) + (($aHi * PRIME) << 16)) & 0xFFFFFFFF
 *
 * Because PRIME is always a compile-time literal at every call-site, this is
 * inlined directly rather than delegated to a general mul32() helper.
 * Benchmarks (PHP 8.3, no JIT) showed:
 *   general  mul32($a, $b)   — 1151 ns / 20 calls
 *   specialised mul32PrimeN  —  928 ns / 20 calls  (−19 %)
 *   fully inlined at site    —  555 ns / 20 calls  (−52 %)
 * On a 100-block loop the fully-inlined variant is ~38 % faster than the
 * general helper and ~16 % faster than specialised functions.
 *
 * --------------------------------------------------------------------------
 * Full optimisation history (all decisions driven by micro-benchmarks):
 *
 *  APPLIED — rotl32() inlined
 *      ~2× call overhead without JIT. Each site uses a literal shift.
 *
 *  APPLIED — mask32() inlined as bare `& 0xFFFFFFFF`
 *      Equally readable; eliminates call overhead at every arithmetic site.
 *
 *  APPLIED — mixBlockStatic() inlined into checksumZero()
 *      Eliminates one call + one array allocation + destructuring per block.
 *
 *  APPLIED — unpack('V4'/'V', $str, $offset) with the offset parameter
 *      Avoids a substr() allocation per block/word. −12 % without JIT.
 *
 *  APPLIED — four int properties instead of array $this->v[]
 *      ~37 % faster without JIT, ~25 % with JIT.
 *
 *  APPLIED — mul32() inlined at every call-site with literal prime
 *      −52 % on the isolated call, −38 % on the block loop (no JIT).
 *      The class is final so there is no subclass use-case for the helper.
 *      A private static mul32() is retained only for external callers that
 *      reach it via reflection; internal code never calls it.
 * --------------------------------------------------------------------------
 */
final class XXHash32
{
    // ---- primes ----
    private const PRIME1 = 0x9E3779B1; // 2654435761
    private const PRIME2 = 0x85EBCA77; // 2246822519
    private const PRIME3 = 0xC2B2AE3D; // 3266489917
    private const PRIME4 = 0x27D4EB2F; //  668265263
    private const PRIME5 = 0x165667B1; //  374761393

    // initial accumulator values (seed = 0)
    // v1 = prime1 + prime2  (mod 2^32)
    private const INIT_V1 = 0x24234428; // (2654435761 + 2246822519) & 0xFFFFFFFF
    // v2 = prime2
    private const INIT_V2 = 0x85EBCA77;
    // v3 = 0
    private const INIT_V3 = 0x00000000;
    // v4 = -prime1  (mod 2^32)  = 2^32 - 2654435761 = 1640531535
    private const INIT_V4 = 0x61C8864F;

    // Four 32-bit accumulators as individual typed properties.
    // ~37 % faster than array $this->v[0..3] without JIT, ~25 % with JIT.
    private int $v1 = self::INIT_V1;
    private int $v2 = self::INIT_V2;
    private int $v3 = self::INIT_V3;
    private int $v4 = self::INIT_V4;

    /** @var string Leftover bytes not yet processed in a full 16-byte block */
    private string $buf = '';

    /** @var int Total bytes fed */
    private int $totalLen = 0;

    /** @var bool Whether reset() has been called at least once */
    private bool $initialized = false;

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Reset returns the hasher to its zero-seed initial state.
     * Must be called before the first write() (or the first write() calls it
     * automatically, mirroring Go's lazy-init behaviour).
     */
    public function reset(): void
    {
        $this->v1          = self::INIT_V1;
        $this->v2          = self::INIT_V2;
        $this->v3          = self::INIT_V3;
        $this->v4          = self::INIT_V4;
        $this->buf         = '';
        $this->totalLen    = 0;
        $this->initialized = true;
    }

    /**
     * Write feeds bytes into the incremental hasher.
     * Mirrors Go's (XXHZero).Write().
     */
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
            // unpack('V4', …, $offset) — no substr() allocation per block.
            $d = unpack('V4', $this->buf, $offset);
            $offset += 16;

            // round(v, data) = mul(rotl(v + mul(data, PRIME2), 13), PRIME1)
            // mul(a, PRIMEn) inlined: split $a into 16-bit halves, prime is a literal.
            // rotl(v, 13) inlined: ($v << 13 | $v >> 19) & 0xFFFFFFFF

            $a = $d[1] & 0xFFFFFFFF;
            $t = ($this->v1 + ((($a & 0xFFFF) * self::PRIME2 & 0xFFFFFFFF) + (($a >> 16) * self::PRIME2 << 16))) & 0xFFFFFFFF;
            $t = ($t << 13 | $t >> 19) & 0xFFFFFFFF;
            $this->v1 = ((($t & 0xFFFF) * self::PRIME1 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME1 << 16)) & 0xFFFFFFFF;

            $a = $d[2] & 0xFFFFFFFF;
            $t = ($this->v2 + ((($a & 0xFFFF) * self::PRIME2 & 0xFFFFFFFF) + (($a >> 16) * self::PRIME2 << 16))) & 0xFFFFFFFF;
            $t = ($t << 13 | $t >> 19) & 0xFFFFFFFF;
            $this->v2 = ((($t & 0xFFFF) * self::PRIME1 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME1 << 16)) & 0xFFFFFFFF;

            $a = $d[3] & 0xFFFFFFFF;
            $t = ($this->v3 + ((($a & 0xFFFF) * self::PRIME2 & 0xFFFFFFFF) + (($a >> 16) * self::PRIME2 << 16))) & 0xFFFFFFFF;
            $t = ($t << 13 | $t >> 19) & 0xFFFFFFFF;
            $this->v3 = ((($t & 0xFFFF) * self::PRIME1 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME1 << 16)) & 0xFFFFFFFF;

            $a = $d[4] & 0xFFFFFFFF;
            $t = ($this->v4 + ((($a & 0xFFFF) * self::PRIME2 & 0xFFFFFFFF) + (($a >> 16) * self::PRIME2 << 16))) & 0xFFFFFFFF;
            $t = ($t << 13 | $t >> 19) & 0xFFFFFFFF;
            $this->v4 = ((($t & 0xFFFF) * self::PRIME1 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME1 << 16)) & 0xFFFFFFFF;
        }

        $this->buf = substr($this->buf, $offset);
    }

    /**
     * Sum32 finalises and returns the 32-bit hash as an unsigned integer.
     * Does not alter the hasher state (safe to call multiple times).
     */
    public function sum32(): int
    {
        return $this->computeSum();
    }

    /**
     * Size returns the number of bytes in the digest (always 4).
     */
    public function size(): int
    {
        return 4;
    }

    /**
     * BlockSize returns the minimum block size (always 1).
     */
    public function blockSize(): int
    {
        return 1;
    }

    // ------------------------------------------------------------------
    // Static helpers
    // ------------------------------------------------------------------

    /**
     * checksumZero computes xxHash32 with seed=0 for the full $input string.
     * This is the standalone "one-shot" variant, identical to ChecksumZero in Go.
     */
    public static function checksumZero(string $input): int
    {
        $len    = strlen($input);
        $h32    = $len & 0xFFFFFFFF;
        $offset = 0;

        if ($len >= 16) {
            $v1 = self::INIT_V1;
            $v2 = self::INIT_V2;
            $v3 = self::INIT_V3;
            $v4 = self::INIT_V4;

            while ($offset + 16 <= $len) {
                // unpack('V4', …, $offset) — no substr() allocation per block.
                $d = unpack('V4', $input, $offset);
                $offset += 16;

                $a  = $d[1] & 0xFFFFFFFF;
                $t  = ($v1 + ((($a & 0xFFFF) * self::PRIME2 & 0xFFFFFFFF) + (($a >> 16) * self::PRIME2 << 16))) & 0xFFFFFFFF;
                $t  = ($t << 13 | $t >> 19) & 0xFFFFFFFF;
                $v1 = ((($t & 0xFFFF) * self::PRIME1 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME1 << 16)) & 0xFFFFFFFF;

                $a  = $d[2] & 0xFFFFFFFF;
                $t  = ($v2 + ((($a & 0xFFFF) * self::PRIME2 & 0xFFFFFFFF) + (($a >> 16) * self::PRIME2 << 16))) & 0xFFFFFFFF;
                $t  = ($t << 13 | $t >> 19) & 0xFFFFFFFF;
                $v2 = ((($t & 0xFFFF) * self::PRIME1 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME1 << 16)) & 0xFFFFFFFF;

                $a  = $d[3] & 0xFFFFFFFF;
                $t  = ($v3 + ((($a & 0xFFFF) * self::PRIME2 & 0xFFFFFFFF) + (($a >> 16) * self::PRIME2 << 16))) & 0xFFFFFFFF;
                $t  = ($t << 13 | $t >> 19) & 0xFFFFFFFF;
                $v3 = ((($t & 0xFFFF) * self::PRIME1 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME1 << 16)) & 0xFFFFFFFF;

                $a  = $d[4] & 0xFFFFFFFF;
                $t  = ($v4 + ((($a & 0xFFFF) * self::PRIME2 & 0xFFFFFFFF) + (($a >> 16) * self::PRIME2 << 16))) & 0xFFFFFFFF;
                $t  = ($t << 13 | $t >> 19) & 0xFFFFFFFF;
                $v4 = ((($t & 0xFFFF) * self::PRIME1 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME1 << 16)) & 0xFFFFFFFF;
            }

            // Merge accumulators. rotl(v, 1/7/12/18) inlined.
            $h32 = ($h32
                  + (($v1 <<  1 | $v1 >> 31) & 0xFFFFFFFF)
                  + (($v2 <<  7 | $v2 >> 25) & 0xFFFFFFFF)
                  + (($v3 << 12 | $v3 >> 20) & 0xFFFFFFFF)
                  + (($v4 << 18 | $v4 >> 14) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        } else {
            $h32 = ($h32 + self::PRIME5) & 0xFFFFFFFF;
        }

        // Remaining full 4-byte words. mul(word, PRIME3) then mul(rotl, PRIME4).
        while ($offset + 4 <= $len) {
            $a    = unpack('V', $input, $offset)[1] & 0xFFFFFFFF;
            $offset += 4;
            $t    = ($h32 + ((($a & 0xFFFF) * self::PRIME3 & 0xFFFFFFFF) + (($a >> 16) * self::PRIME3 << 16))) & 0xFFFFFFFF;
            $t    = ($t << 17 | $t >> 15) & 0xFFFFFFFF;
            $h32  = ((($t & 0xFFFF) * self::PRIME4 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME4 << 16)) & 0xFFFFFFFF;
        }

        // Remaining individual bytes. mul(byte, PRIME5) then mul(rotl, PRIME1).
        while ($offset < $len) {
            $a    = ord($input[$offset++]);
            $t    = ($h32 + ($a * self::PRIME5 & 0xFFFFFFFF)) & 0xFFFFFFFF; // byte fits in 8 bits: lo half only needed
            $t    = ($t << 11 | $t >> 21) & 0xFFFFFFFF;
            $h32  = ((($t & 0xFFFF) * self::PRIME1 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME1 << 16)) & 0xFFFFFFFF;
        }

        return self::avalanche($h32);
    }

    // ------------------------------------------------------------------
    // Private helpers
    // ------------------------------------------------------------------

    /**
     * Finalise the incremental hash state (without mutating it).
     */
    private function computeSum(): int
    {
        $h32 = $this->totalLen & 0xFFFFFFFF;

        if ($this->totalLen >= 16) {
            // Merge accumulators. rotl(v, 1/7/12/18) inlined.
            $h32 = ($h32
                  + (($this->v1 <<  1 | $this->v1 >> 31) & 0xFFFFFFFF)
                  + (($this->v2 <<  7 | $this->v2 >> 25) & 0xFFFFFFFF)
                  + (($this->v3 << 12 | $this->v3 >> 20) & 0xFFFFFFFF)
                  + (($this->v4 << 18 | $this->v4 >> 14) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        } else {
            $h32 = ($h32 + self::PRIME5) & 0xFFFFFFFF;
        }

        $buf    = $this->buf;
        $bufLen = strlen($buf);
        $offset = 0;

        // Remaining full 4-byte words.
        while ($offset + 4 <= $bufLen) {
            $a    = unpack('V', $buf, $offset)[1] & 0xFFFFFFFF;
            $offset += 4;
            $t    = ($h32 + ((($a & 0xFFFF) * self::PRIME3 & 0xFFFFFFFF) + (($a >> 16) * self::PRIME3 << 16))) & 0xFFFFFFFF;
            $t    = ($t << 17 | $t >> 15) & 0xFFFFFFFF;
            $h32  = ((($t & 0xFFFF) * self::PRIME4 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME4 << 16)) & 0xFFFFFFFF;
        }

        // Remaining individual bytes.
        while ($offset < $bufLen) {
            $a    = ord($buf[$offset++]);
            $t    = ($h32 + ($a * self::PRIME5 & 0xFFFFFFFF)) & 0xFFFFFFFF;
            $t    = ($t << 11 | $t >> 21) & 0xFFFFFFFF;
            $h32  = ((($t & 0xFFFF) * self::PRIME1 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME1 << 16)) & 0xFFFFFFFF;
        }

        return self::avalanche($h32);
    }

    /**
     * Overflow-safe unsigned 32-bit multiplication: (a * b) mod 2^32.
     *
     * NOTE: this method is no longer called internally — every internal
     * call-site inlines the split-multiply directly with a literal prime,
     * which is ~52 % faster than calling this helper (no JIT). It is kept
     * as a public utility for external callers that need a general mul32.
     *
     * The safe split: a = (aHi << 16) | aLo
     *   a*b = (aHi*b << 16) + aLo*b   (mod 2^32)
     * aHi*b <= 0xFFFF * 0xFFFFFFFF ~= 2.8e14 — safely below PHP_INT_MAX.
     */
    public static function mul32(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;

        $aLo = $a & 0xFFFF;
        $aHi = $a >> 16;

        return ((($aLo * $b) & 0xFFFFFFFF) + (($aHi * $b) << 16)) & 0xFFFFFFFF;
    }

    /**
     * Final avalanche / mixing step.
     * mul32 calls inlined with literal primes.
     */
    private static function avalanche(int $h32): int
    {
        $h32 ^= $h32 >> 15;
        $t    = $h32 & 0xFFFFFFFF;
        $h32  = ((($t & 0xFFFF) * self::PRIME2 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME2 << 16)) & 0xFFFFFFFF;
        $h32 ^= $h32 >> 13;
        $t    = $h32 & 0xFFFFFFFF;
        $h32  = ((($t & 0xFFFF) * self::PRIME3 & 0xFFFFFFFF) + (($t >> 16) * self::PRIME3 << 16)) & 0xFFFFFFFF;
        $h32 ^= $h32 >> 16;

        return $h32 & 0xFFFFFFFF;
    }
}