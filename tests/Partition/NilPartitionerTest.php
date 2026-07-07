<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Tests\Partition;

use FallenKomradesDev\KSUID\Partition\NilPartitioner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \FallenKomradesDev\KSUID\Partition\NilPartitioner
 */
final class NilPartitionerTest extends TestCase
{
    public function testAlwaysReturnsZero(): void
    {
        $partitioner = new NilPartitioner();

        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(0, $partitioner());
        }
    }

    public function testIsDeterministicAcrossInstances(): void
    {
        $a = new NilPartitioner();
        $b = new NilPartitioner();

        $this->assertSame($a(), $b());
    }
}
