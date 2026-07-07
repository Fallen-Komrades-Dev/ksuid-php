<?php

declare(strict_types=1);

/**
 * benchmark.php — a lightweight micro-benchmark harness mirroring the
 * output style of `go test -bench=. -benchmem ./...` from ksuid-go.
 *
 * PHP has no built-in benchmarking framework equivalent to Go's testing.B,
 * so this script hand-rolls the same essential measurement: iterate an
 * operation enough times to get a stable wall-clock reading, then report
 * nanoseconds/operation and operations/second — analogous to Go's `ns/op`
 * column. (Go's `-benchmem` also reports B/op and allocs/op; PHP has no
 * reliable lightweight equivalent — see the note on benchmark() below.)
 *
 * Run via: php benchmark.php
 * Or via composer: composer bench
 */

require __DIR__ . '/vendor/autoload.php';

use FallenKomradesDev\KSUID\Encoder;
use FallenKomradesDev\KSUID\Generator\AsyncGenerator;
use FallenKomradesDev\KSUID\Generator\StandardGenerator;
use FallenKomradesDev\KSUID\Ksuid;
use FallenKomradesDev\KSUID\Partition\NilPartitioner;
use FallenKomradesDev\KSUID\Partition\StringPartitioner;
use FallenKomradesDev\KSUID\XXHash32\XXHash32;
use FallenKomradesDev\KSUID\XXHash32\XXHash32Gmp;

/**
 * Run $fn repeatedly for at least $minDurationSeconds, then report
 * ns/op and ops/sec in a style similar to Go's benchmark output.
 *
 * Note: unlike Go's testing.B (which tracks per-op allocations precisely
 * via runtime instrumentation), PHP's memory_get_usage() is too coarse
 * and GC/allocator-dependent to produce a meaningful B/op figure across
 * many fast iterations in a tight loop, so this harness deliberately
 * reports only timing — a reliable B/op equivalent would require Xdebug's
 * profiler or a dedicated extension, out of scope for a lightweight
 * benchmark script.
 */
function benchmark(string $name, callable $fn, float $minDurationSeconds = 0.5): void
{
    // Warm up (JIT-free in PHP, but this also primes OPcache file reads
    // and avoids counting one-time setup cost in the timed loop).
    for ($i = 0; $i < 100; $i++) {
        $fn();
    }

    $iterations = 0;
    $start = hrtime(true);
    $deadline = $start + (int) ($minDurationSeconds * 1_000_000_000);

    do {
        $fn();
        $iterations++;
    } while (hrtime(true) < $deadline);

    $elapsedNs = hrtime(true) - $start;

    $nsPerOp = $elapsedNs / $iterations;
    $opsPerSec = 1_000_000_000 / $nsPerOp;

    printf(
        "%-55s %12s iterations %15.1f ns/op %15s ops/sec\n",
        $name,
        number_format($iterations),
        $nsPerOp,
        number_format($opsPerSec, 0)
    );
}

echo "================================================\n";
echo "KSUID PHP - Benchmark Suite\n";
echo "PHP version: " . PHP_VERSION . " | GMP: " . (extension_loaded('gmp') ? 'available' : 'NOT available') . "\n";
echo "================================================\n\n";

// ---------------- XXHash32 ----------------
echo "-- XXHash32 (one-shot, pure-PHP) --\n";
$shortInput = 'the quick brown fox';
$longInput = str_repeat('the quick brown fox jumps over the lazy dog', 50); // ~2.2KB

benchmark('XXHash32::checksumZero (short, ~19 bytes)', function () use ($shortInput): void {
    XXHash32::checksumZero($shortInput);
});

benchmark('XXHash32::checksumZero (long, ~2.2KB)', function () use ($longInput): void {
    XXHash32::checksumZero($longInput);
});

if (extension_loaded('gmp')) {
    echo "\n-- XXHash32Gmp (one-shot, GMP-backed) --\n";

    benchmark('XXHash32Gmp::checksumZero (short, ~19 bytes)', function () use ($shortInput): void {
        XXHash32Gmp::checksumZero($shortInput);
    });

    benchmark('XXHash32Gmp::checksumZero (long, ~2.2KB)', function () use ($longInput): void {
        XXHash32Gmp::checksumZero($longInput);
    });
}

// ---------------- Encoder ----------------
echo "\n-- Encoder --\n";
$tst = new Ksuid(584049083, 0xC0FFEE, 0xDEADBEEF, 0x355016B7);
$tstHex = $tst->toString();
$tstBinary = $tst->binary();

benchmark('Encoder::encodeBinary', function () use ($tst): void {
    Encoder::encodeBinary($tst);
});

benchmark('Encoder::encodeHex', function () use ($tst): void {
    Encoder::encodeHex($tst);
});

benchmark('Encoder::decodeBinary', function () use ($tstBinary): void {
    Encoder::decodeBinary($tstBinary);
});

benchmark('Encoder::decodeHex', function () use ($tstHex): void {
    Encoder::decodeHex($tstHex);
});

// ---------------- Ksuid ----------------
echo "\n-- Ksuid --\n";

benchmark('Ksuid::computeHash', function () use ($tst): void {
    $tst->computeHash();
});

benchmark('Ksuid::validate', function () use ($tst): void {
    $tst->validate();
});

$other = new Ksuid(584049084, 0, 0, 0);
benchmark('Ksuid::compare', function () use ($tst, $other): void {
    $tst->compare($other);
});

// ---------------- Partitioners ----------------
echo "\n-- Partitioners --\n";

benchmark('NilPartitioner::__invoke', function (): void {
    (new NilPartitioner())();
});

benchmark('StringPartitioner construction + invoke', function (): void {
    (new StringPartitioner('benchmark-partition-key'))();
});

// ---------------- Generators ----------------
echo "\n-- Generators --\n";

$standardGen = new StandardGenerator(new NilPartitioner());
benchmark('StandardGenerator::next', function () use ($standardGen): void {
    $standardGen->next();
});

$asyncGen = new AsyncGenerator(new NilPartitioner());
benchmark('AsyncGenerator::next (no clock check)', function () use ($asyncGen): void {
    $asyncGen->next();
});

benchmark('AsyncGenerator::autoNext (with clock check)', function () use ($asyncGen): void {
    $asyncGen->autoNext();
});

$partitionedGen = new StandardGenerator(new StringPartitioner('benchmark-shard'));
benchmark('StandardGenerator::next (with StringPartitioner)', function () use ($partitionedGen): void {
    $partitionedGen->next();
});

echo "\n================================================\n";
echo "Benchmark complete.\n";
echo "================================================\n";
