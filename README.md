# KSUID (PHP)

A PHP port of `ksuid-go` — a K-Sortable Unique Identifier library with customizable partitioning and pluggable encoding. Built on the encoder/decoder design from [tuupola/ksuid](https://github.com/tuupola/ksuid), refactored and extended with the full feature set of the Go original: generators, partitioners, and a from-scratch xxHash32 implementation (pure-PHP and GMP-backed).

## Features

- **K-Sortable Unique Identifiers** — time-ordered IDs with second precision
- **Customizable partitioning** — distribute IDs across nodes or shards
- **Two generator strategies** — `StandardGenerator` and `AsyncGenerator`, both with the exact overflow-guard semantics of the Go original
- **Pure-PHP and GMP-backed xxHash32** — pick whichever fits your environment; both are verified byte-for-byte against the canonical 256-vector xxHash32 reference table
- **Strict, typed value object** — `Ksuid` is an immutable, typed, `JsonSerializable`, `Stringable` PHP 8.1+ class
- **Comprehensive test suite** — 1,600+ PHPUnit tests, including exact fixture parity with the Go test suite

## Installation

```bash
composer require fallen-komrades-dev/ksuid
```

Requires PHP 8.1 or later. The `ext-gmp` extension is optional but recommended if you want the GMP-backed xxHash32 implementation (`XXHash32Gmp`) — the pure-PHP implementation (`XXHash32`) has no extension dependencies and is used internally by the rest of the library by default.

## Quick Start

```php
use FallenKomradesDev\KSUID\Generator\StandardGenerator;

$generator = new StandardGenerator();

$k = $generator->next();
echo $k->toString();      // e.g. "31c8d0a9000000000000000060902cf5"
echo $k->time()->format(DATE_ATOM); // when it was generated
echo $k->seq;              // sequence number within that second
echo $k->partition;        // partition (0 by default)
```

Unlike some KSUID libraries that expose a package-level singleton (`ksuid.Next()`, `ksuid.SetPartitioner()`), this package has you construct and hold a generator instance directly. This avoids hidden global mutable state and makes dependency injection straightforward.

## Usage

### Standard Generator

```php
use FallenKomradesDev\KSUID\Generator\StandardGenerator;

$generator = new StandardGenerator();
$k = $generator->next();
```

`StandardGenerator` checks the wall clock on every call to `next()`, advancing its internal timestamp and resetting the sequence counter whenever a new second begins — exactly mirroring Go's `StandardGenerator.Next()`, including its sequence-overflow guard (if you generate more than ~4.29 billion IDs within a single second, the stored timestamp is advanced by one to guarantee uniqueness rather than blocking).

> **Concurrency note:** Go's `StandardGenerator` uses a `sync.Mutex` because goroutines can call `Next()` from multiple OS threads simultaneously. Standard PHP request handling (`php-fpm`, `mod_php`, CLI scripts) has exactly one thread of execution per request, so there's no analogous data race to guard against. If you're running this in a genuinely multi-threaded PHP runtime (pthreads, parallel, Swoole/RoadRunner workers sharing memory), you're responsible for serializing calls to `next()` yourself.

### Async Generator

```php
use FallenKomradesDev\KSUID\Generator\AsyncGenerator;

$generator = new AsyncGenerator();

// Convenience method: checks the clock and generates in one call.
$k = $generator->autoNext();
```

Go's `AsyncGenerator` runs a background goroutine that refreshes a cached timestamp once per second via a `time.Ticker`, while `Next()` does a lock-free atomic increment — a design specifically built to avoid mutex contention across many goroutines on many cores. PHP has no goroutines or background threads in the typical request-per-process model, so this class preserves the same *external* lifecycle shape (`run()` / `stop()` / `tick()`) and, critically, the same *sequencing semantics* — including the fix for a historical bug where the sequence counter must only reset when the timestamp has genuinely advanced, never merely because a timer fired.

Two ways to use it:

```php
// Style 1 — explicit ticking, for parity with environments that do have
// a real event loop (Swoole, ReactPHP, RoadRunner): drive tick() yourself
// from a periodic timer, then call next() (which never touches the clock).
$generator = new AsyncGenerator();
$generator->tick();              // call this roughly once a second
$k = $generator->next();         // pure increment, no clock access

// Style 2 — polling convenience (PHP-specific, no Go equivalent): checks
// the clock and generates in a single call, for plain scripts with no
// separate event loop.
$generator = new AsyncGenerator();
$k = $generator->autoNext();
```

`run()` and `stop()` are provided for API-shape parity with Go (they simply flip an `isRunning()` flag, since there's no background thread to literally start/stop).

### Custom Partitioning

```php
use FallenKomradesDev\KSUID\Generator\StandardGenerator;
use FallenKomradesDev\KSUID\Partition\StringPartitioner;

$generator = new StandardGenerator(new StringPartitioner('service-a'));
$k = $generator->next();
```

Both generators accept any `PartitionerInterface` implementation **or** a plain `callable`/`Closure` returning an `int` — no interface implementation is required for simple cases:

```php
$counter = 0;
$generator = new StandardGenerator(function () use (&$counter): int {
    return $counter++;
});
```

## Partitioners

Partitioners split KSUIDs into logical partitions, useful in distributed systems where each node or shard should write to a distinguishable partition.

| Class | Description |
|---|---|
| `NilPartitioner` | Always returns `0` (the default if no partitioner is supplied) |
| `StringPartitioner` | Hashes the full input string with xxHash32, avoiding prefix collisions that a naive truncation scheme would suffer (e.g. `"user-123"` and `"user-456"` hash to different partitions despite sharing a prefix) |
| `MacPartitioner` | Uses the last 4 bytes of the host's primary network interface MAC address; throws `NoNetworkInterfaceException` if none can be found |

```php
use FallenKomradesDev\KSUID\Partition\MacPartitioner;
use FallenKomradesDev\KSUID\Partition\StringPartitioner;
use FallenKomradesDev\KSUID\Exception\NoNetworkInterfaceException;

try {
    $partitioner = new MacPartitioner();
} catch (NoNetworkInterfaceException $e) {
    $partitioner = new StringPartitioner('fallback-shard');
}
```

`MacPartitioner` has no PHP standard-library equivalent to Go's `net.Interfaces()`, so it discovers the MAC address by platform-appropriate means: reading `/sys/class/net/*/address` on Linux, shelling out to `getmac` on Windows, and `ifconfig` on macOS. This is inherently environment-dependent — minimal containers or sandboxes may have no non-loopback interface at all, which is why the test suite tolerates either a valid result or the documented exception rather than asserting a hardcoded value.

## Encoding & Decoding

```php
use FallenKomradesDev\KSUID\Encoder;

$k = $generator->next();

// Hex encoding (32 characters)
$hex = $k->toString(); // or (string) $k
$decoded = Encoder::decodeHex($hex);

// Binary encoding (16 bytes)
$binary = $k->binary();
$decoded = Encoder::decodeBinary($binary);
```

`decodeHex()`/`decodeBinary()` throw on malformed input:

```php
use FallenKomradesDev\KSUID\Exception\InvalidLengthException;
use FallenKomradesDev\KSUID\Exception\InvalidHexLengthException;
use FallenKomradesDev\KSUID\Exception\InvalidHexCharacterException;
use FallenKomradesDev\KSUID\Exception\HashMismatchException;

try {
    Encoder::decodeHex($untrustedInput);
} catch (InvalidHexLengthException $e) {
    // not exactly 32 characters
} catch (InvalidHexCharacterException $e) {
    // contains non-hex characters
} catch (HashMismatchException $e) {
    // the embedded hash doesn't match the decoded fields — corrupted or tampered
}
```

### JSON

`Ksuid` implements `JsonSerializable`, encoding as its 32-character hex string:

```php
$k = $generator->next();
echo json_encode(['id' => $k]); // {"id":"31c8d0a9..."}

$decoded = \FallenKomradesDev\KSUID\Ksuid::fromJson('"31c8d0a9..."');
```

## Comparing KSUIDs

```php
$cmp = $k1->compare($k2);
// -1 if $k1 < $k2, 0 if equal, +1 if $k1 > $k2
// Ordered by: timestamp -> partition -> sequence

$k1->equals($k2); // strict equality across every field, including Hash
```

## KSUID Structure

```
[Timestamp:4 bytes][Seq:4 bytes][Partition:4 bytes][Hash:4 bytes]
```

16 bytes (128 bits) total, big-endian. The `Hash` field is the xxHash32 checksum of the preceding 12 bytes, computed automatically and verified on every decode.

- **Timestamp** (uint32) — seconds since `Ksuid::getEpoch()` (defaults to `2000-01-01T00:00:00Z`, overridable via `Ksuid::setEpoch()`)
- **Seq** (uint32) — monotonic counter within the same second
- **Partition** (uint32) — identifies the generating node/partition
- **Hash** (uint32) — xxHash32 integrity checksum

## xxHash32

This package includes a from-scratch xxHash32 implementation (seed = 0), since KSUID's integrity hash depends on it and PHP has no built-in xxHash support.

```php
use FallenKomradesDev\KSUID\XXHash32\XXHash32;
use FallenKomradesDev\KSUID\XXHash32\XXHash32Gmp;

// One-shot
$hash = XXHash32::checksumZero('hello world');

// Streaming
$hasher = new XXHash32();
$hasher->reset();
$hasher->write('hello ');
$hasher->write('world');
$hash = $hasher->sum32();

// GMP-backed equivalent, identical API
$hash = XXHash32Gmp::checksumZero('hello world');
```

Both implementations are validated against the canonical 256-entry xxHash32 reference vector table (hashing byte sequences `[0]`, `[0,1]`, `[0,1,2]`, ... up to length 255) and cross-checked against each other for agreement on randomized and irregularly-chunked input. The pure-PHP version handles PHP's lack of native 32-bit unsigned integer overflow by splitting multiplications into 16-bit halves so every intermediate value stays within 64-bit signed integer range without ever silently promoting to float — a real, easy-to-miss pitfall when porting C/Go-style unsigned 32-bit hash algorithms to PHP.

## Testing

```bash
composer test              # run the full PHPUnit suite
composer test-coverage     # run with HTML + text coverage report
composer bench             # run the benchmark suite
```

Or run the verification pipeline directly (lint, tests, benchmarks, coverage — see [`verify.sh`](verify.sh) / [`verify.bat`](verify.bat)):

```bash
./verify.sh     # Linux/macOS/WSL
verify.bat      # Windows
```

The test suite includes:

- All 256 canonical xxHash32 reference vectors, against both the pure-PHP and GMP implementations, via one-shot and multiple streaming chunk patterns (including stress tests with irregular, non-uniform write sizes far beyond what the Go test suite covers)
- Exact fixture parity with `ksuid-go`'s `ksuid_test.go`/`encoder_test.go`/`partition_test.go` — the same `Timestamp`/`Seq`/`Partition`/`Hash` values, the same expected hex string, the same expected `Time()` output
- Sequence-overflow edge cases for both generators, including deterministic tests that bypass the live wall-clock check to genuinely (not just incidentally) exercise the overflow guard
- A dedicated regression test for the historical "ticker reset" bug in `AsyncGenerator`, verifying `next()` never touches the wall clock on its own

## Project Layout

```
src/
  Ksuid.php                       Immutable value object
  Encoder.php                     Binary/hex codec
  Exception/                      Typed exceptions (one per Go sentinel error)
  Partition/
    PartitionerInterface.php
    NilPartitioner.php
    StringPartitioner.php
    MacPartitioner.php
  Generator/
    GeneratorInterface.php
    StandardGenerator.php
    AsyncGenerator.php
  XXHash32/
    XXHash32.php                  Pure-PHP implementation
    XXHash32Gmp.php                GMP-backed implementation
tests/                            PHPUnit test suite, mirroring src/ structure
examples/main.php                 Runnable usage examples
benchmark.php                     Micro-benchmark harness
verify.sh / verify.bat            Full verification pipeline
```

## Differences from the Go original

- No package-level singleton generator/partitioner (`ksuid.Next()`, `ksuid.SetPartitioner()`) — construct and hold a generator instance instead.
- `AsyncGenerator` adapts Go's goroutine-and-ticker design to PHP's execution model: `next()` never touches the wall clock (exactly like Go's `Next()`), `tick()` is the explicit timer-driven refresh, and `autoNext()` is a PHP-specific convenience that combines both for simple polling use.
- PHP has no native unsigned 32-bit integer type; all four `Ksuid` fields are ordinary PHP `int`s masked to `[0, 0xFFFFFFFF]`, which fits losslessly inside PHP's 64-bit signed integers.
- `MacPartitioner` discovers the MAC address via platform-specific file reads/shell commands rather than a standard-library network API, since PHP has none.

## License

MIT
