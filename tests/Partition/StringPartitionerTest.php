<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Tests\Partition;

use FallenKomradesDev\KSUID\Partition\StringPartitioner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Direct port of the StringPartitioner cases in ksuid-go/partition_test.go.
 * Expected values are the xxHash32(seed=0) of each input string, which
 * must match exactly between Go and PHP since both delegate to the same
 * algorithm.
 *
 * @covers \FallenKomradesDev\KSUID\Partition\StringPartitioner
 */
final class StringPartitionerTest extends TestCase
{
    public static function fixtureProvider(): array
    {
        return [
            "'test'" => ['test', 0x3E2023CF],
            "'part'" => ['part', 0xD31C60D5],
            "'ab'" => ['ab', 0x4999FC53],
            'empty string' => ['', 0x02CC5D05],
        ];
    }

    #[DataProvider('fixtureProvider')]
    public function testMatchesGoFixture(string $input, int $expected): void
    {
        $partitioner = new StringPartitioner($input);

        $this->assertSame($expected, $partitioner());
    }

    public function testIsDeterministic(): void
    {
        $a = new StringPartitioner('node-07');
        $b = new StringPartitioner('node-07');

        $this->assertSame($a(), $b());
    }

    public function testValueIsComputedOnceAtConstructionTime(): void
    {
        // Calling the partitioner multiple times must always return the
        // same precomputed value, matching Go's closure-over-precomputed-
        // value design.
        $partitioner = new StringPartitioner('stable-key');

        $first = $partitioner();
        $second = $partitioner();
        $third = $partitioner();

        $this->assertSame($first, $second);
        $this->assertSame($second, $third);
    }

    public function testSharedPrefixesDoNotCollide(): void
    {
        // Regression guard: a naive "truncate to 4 bytes" partitioning
        // scheme would make "user-123" and "user-456" collide on their
        // shared "user" prefix. Hashing the full string avoids this.
        $a = new StringPartitioner('user-123');
        $b = new StringPartitioner('user-456');

        $this->assertNotSame($a(), $b());
        $this->assertSame(0x0CC9EC8C, $a());
        $this->assertSame(0xF8BDBA46, $b());
    }

    public function testDifferentStringsTypicallyProduceDifferentPartitions(): void
    {
        $values = [];
        foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon'] as $word) {
            $values[] = (new StringPartitioner($word))();
        }

        $this->assertSame($values, array_unique($values));
    }

    public function testReturnValueIsWithinUint32Range(): void
    {
        $partitioner = new StringPartitioner('any-arbitrary-string-value-here');
        $value = $partitioner();

        $this->assertGreaterThanOrEqual(0, $value);
        $this->assertLessThanOrEqual(0xFFFFFFFF, $value);
    }
}
