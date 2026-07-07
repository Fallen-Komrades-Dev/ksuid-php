<?php

declare(strict_types=1);

/**
 * examples/main.php — runnable usage examples for the KSUID PHP package.
 *
 * Run via: php examples/main.php
 *
 * Note: unlike some KSUID library READMEs that describe a package-level
 * singleton API (ksuid.Next(), ksuid.SetPartitioner(), ...), this package
 * exposes generators as ordinary objects you construct and hold onto —
 * StandardGenerator and AsyncGenerator — which is both simpler to reason
 * about and avoids hidden global mutable state. Every example below uses
 * the real, actual public API.
 */

require __DIR__ . '/../vendor/autoload.php';

use FallenKomradesDev\KSUID\Encoder;
use FallenKomradesDev\KSUID\Exception\HashMismatchException;
use FallenKomradesDev\KSUID\Exception\InvalidHexLengthException;
use FallenKomradesDev\KSUID\Exception\InvalidLengthException;
use FallenKomradesDev\KSUID\Exception\NoNetworkInterfaceException;
use FallenKomradesDev\KSUID\Generator\AsyncGenerator;
use FallenKomradesDev\KSUID\Generator\StandardGenerator;
use FallenKomradesDev\KSUID\Ksuid;
use FallenKomradesDev\KSUID\Partition\MacPartitioner;
use FallenKomradesDev\KSUID\Partition\NilPartitioner;
use FallenKomradesDev\KSUID\Partition\StringPartitioner;

echo "KSUID PHP Package - Examples\n";
echo "=============================\n\n";

basicUsage();
multipleGenerationsInALoop();
asyncGeneratorUsage();
customPartitioning();
encodingDecoding();
ksuidComparison();

// ---------------------------------------------------------------------

function basicUsage(): void
{
    echo "1. Basic Usage\n";
    echo "--------------\n";

    // Construct a generator (NilPartitioner is the default if omitted).
    $generator = new StandardGenerator();

    $k = $generator->next();
    printf("Generated KSUID: %s\n", $k->toString());
    printf("  Timestamp: %s\n", $k->time()->format(DATE_RFC3339));
    printf("  Sequence:  %d\n", $k->seq);
    printf("  Partition: %d\n\n", $k->partition);
}

function multipleGenerationsInALoop(): void
{
    echo "2. Multiple Generations\n";
    echo "------------------------\n";

    $generator = new StandardGenerator();
    $ksuids = [];

    for ($i = 0; $i < 10; $i++) {
        $ksuids[] = $generator->next()->toString();
    }

    echo "Generated 10 KSUIDs:\n";
    foreach ($ksuids as $i => $hex) {
        printf("  %d: %s\n", $i + 1, $hex);
    }
    echo "\n";
}

function asyncGeneratorUsage(): void
{
    echo "3. AsyncGenerator (PHP-adapted lifecycle)\n";
    echo "-------------------------------------------\n";
    echo "Note: PHP has no goroutines/background threads, so AsyncGenerator\n";
    echo "adapts Go's design: next() never touches the wall clock (matching\n";
    echo "Go's Next() exactly), while autoNext() is a PHP-specific convenience\n";
    echo "that checks the clock and generates in one call — see the class\n";
    echo "docblock on AsyncGenerator for the full rationale.\n\n";

    $generator = new AsyncGenerator();
    $generator->run(); // optional — flips an isRunning() flag for API parity

    echo "Generating 5 KSUIDs with AsyncGenerator::autoNext():\n";
    for ($i = 0; $i < 5; $i++) {
        $k = $generator->autoNext();
        printf("  %d: %s\n", $i + 1, $k->toString());
    }

    $generator->stop();
    echo "\n";
}

function customPartitioning(): void
{
    echo "4. Custom Partitioning\n";
    echo "----------------------\n";

    // String-based partitioning.
    $generator = new StandardGenerator(new StringPartitioner('node-01'));
    $k1 = $generator->next();
    printf("String partitioner 'node-01': %s (partition: 0x%08x)\n", $k1->toString(), $k1->partition);

    // MAC-based partitioning with fallback.
    try {
        $partitioner = new MacPartitioner();
    } catch (NoNetworkInterfaceException $e) {
        printf("MAC partitioner unavailable: %s\n", $e->getMessage());
        echo "Falling back to string partitioner...\n";
        $partitioner = new StringPartitioner('default');
    }
    $generator2 = new StandardGenerator($partitioner);
    $k2 = $generator2->next();
    printf("MAC/fallback partitioner: %s (partition: 0x%08x)\n", $k2->toString(), $k2->partition);

    // Custom partitioner: any callable returning int works, no interface
    // implementation required.
    $counter = 100;
    $customPartitioner = function () use (&$counter): int {
        $counter++;
        return $counter;
    };
    $generator3 = new StandardGenerator($customPartitioner);
    $k3 = $generator3->next();
    printf("Custom closure partitioner: %s (partition: %d)\n", $k3->toString(), $k3->partition);

    // NilPartitioner explicitly (this is also the default if omitted).
    $generator4 = new StandardGenerator(new NilPartitioner());
    $k4 = $generator4->next();
    printf("Explicit NilPartitioner: %s (partition: %d)\n\n", $k4->toString(), $k4->partition);
}

function encodingDecoding(): void
{
    echo "5. Encoding and Decoding\n";
    echo "------------------------\n";

    $generator = new StandardGenerator();
    $k = $generator->next();
    printf("Original KSUID: %s\n", $k->toString());

    // Binary encoding (16 bytes).
    $binary = $k->binary();
    printf("Binary (16 bytes, hex view): %s\n", bin2hex($binary));

    // Decode from binary.
    try {
        $decoded = Encoder::decodeBinary($binary);
        printf("Decoded from binary: %s\n", $decoded->toString());
    } catch (InvalidLengthException|HashMismatchException $e) {
        printf("Error decoding binary: %s\n", $e->getMessage());
    }

    // Hex encoding (32 characters).
    $hex = $k->toString();
    printf("Hex (32 chars): %s\n", $hex);

    // Decode from hex.
    try {
        $decodedHex = Encoder::decodeHex($hex);
        printf("Decoded from hex: %s\n", $decodedHex->toString());
    } catch (InvalidHexLengthException|HashMismatchException $e) {
        printf("Error decoding hex: %s\n", $e->getMessage());
    }

    // Error handling examples.
    try {
        Encoder::decodeBinary("\x01\x02\x03"); // too short
    } catch (InvalidLengthException) {
        echo "Caught InvalidLengthException for short binary\n";
    }

    try {
        Encoder::decodeHex('short'); // too short
    } catch (InvalidHexLengthException) {
        echo "Caught InvalidHexLengthException for short hex\n";
    }

    echo "\n";
}

function ksuidComparison(): void
{
    echo "6. KSUID Comparison\n";
    echo "-------------------\n";

    $generator = new StandardGenerator();
    $k1 = $generator->next();
    usleep(10_000); // 10ms, ensure a different timestamp is plausible
    $k2 = $generator->next();

    printf("KSUID 1: %s\n", $k1->toString());
    printf("KSUID 2: %s\n", $k2->toString());

    $cmp = $k1->compare($k2);
    if ($cmp < 0) {
        echo "Result: KSUID 1 is before KSUID 2\n";
    } elseif ($cmp > 0) {
        echo "Result: KSUID 1 is after KSUID 2\n";
    } else {
        echo "Result: KSUIDs are equal in ordering (same second, sequence comparison applies)\n";
    }

    // isZero check.
    $zero = new Ksuid();
    printf("\nZero value Ksuid::isZero(): %s\n", $zero->isZero() ? 'true' : 'false');
    printf("Generated Ksuid::isZero(): %s\n", $k1->isZero() ? 'true' : 'false');
}
