<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Tests\Generator;

use FallenKomradesDev\KSUID\Generator\StandardGenerator;
use FallenKomradesDev\KSUID\Partition\NilPartitioner;
use FallenKomradesDev\KSUID\Partition\StringPartitioner;
use PHPUnit\Framework\TestCase;

/**
 * Direct port of the StandardGenerator tests in ksuid-go/generator_test.go.
 *
 * @covers \FallenKomradesDev\KSUID\Generator\StandardGenerator
 */
final class StandardGeneratorTest extends TestCase
{
    public function testGeneratesUniqueIdsAcrossManyCalls(): void
    {
        $generator = new StandardGenerator(new NilPartitioner());
        $seen = [];

        for ($i = 0; $i < 10_000; $i++) {
            $hex = $generator->next()->toString();

            $this->assertArrayNotHasKey($hex, $seen, 'duplicate KSUID generated: ' . $hex);
            $seen[$hex] = true;
        }

        $this->assertCount(10_000, $seen);
    }

    public function testEveryGeneratedKsuidValidates(): void
    {
        $generator = new StandardGenerator(new NilPartitioner());

        for ($i = 0; $i < 1_000; $i++) {
            $k = $generator->next();

            $this->assertTrue($k->validate(), 'generated KSUID failed validate(): ' . $k->toString());
        }
    }

    public function testGeneratedIdsAreMonotonicWithinTheSameSecond(): void
    {
        $generator = new StandardGenerator(new NilPartitioner());

        $previous = $generator->next();
        for ($i = 0; $i < 100; $i++) {
            $current = $generator->next();

            // Either the timestamp advanced, or (far more likely within a
            // microseconds-fast loop) the sequence strictly increased.
            $this->assertGreaterThanOrEqual(0, $current->compare($previous));
            $previous = $current;
        }
    }

    public function testDefaultPartitionerIsNilWhenNoneProvided(): void
    {
        $generator = new StandardGenerator();
        $k = $generator->next();

        $this->assertSame(0, $k->partition);
    }

    public function testCustomPartitionerIsUsedForEveryGeneratedId(): void
    {
        $generator = new StandardGenerator(new StringPartitioner('shard-A'));
        $expectedPartition = (new StringPartitioner('shard-A'))();

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame($expectedPartition, $generator->next()->partition);
        }
    }

    public function testAcceptsPlainClosureAsPartitioner(): void
    {
        $generator = new StandardGenerator(fn (): int => 42);

        $this->assertSame(42, $generator->next()->partition);
    }

    /**
     * Ported from Go's TestStandardGenerator_SeqOverflow. Note: this test
     * preserves a quirk that exists in the real Go implementation too —
     * see the docblock on StandardGenerator::nextWithoutClockCheckForTesting()
     * for a full explanation. Because Next() always compares against the
     * REAL current time first, and real time is always vastly larger than
     * the fixture's ts=1000, that live-clock guard fires before the
     * overflow branch is ever reached. The Go test's assertions are loose
     * enough to pass via this path; we replicate that here for fidelity.
     */
    public function testSeqOverflowGoFixtureFidelity(): void
    {
        $generator = new StandardGenerator(new NilPartitioner());
        $generator->setStateForTesting(1000, 0xFFFFFFFF);

        $k1 = $generator->next();
        $k2 = $generator->next();

        $this->assertFalse(
            $k1->timestamp === $k2->timestamp && $k1->seq === $k2->seq,
            'overflow handling produced a duplicate (Timestamp, Seq) pair'
        );

        [$tsAfter] = $generator->getStateForTesting();
        $this->assertGreaterThan(1000, $tsAfter);
    }

    /**
     * Stricter than the Go-ported test above: bypasses the live wall-clock
     * check entirely (via the test-only seam) so the overflow guard is
     * genuinely, deterministically exercised and its exact before/after
     * values can be asserted.
     */
    public function testSeqOverflowDeterministic(): void
    {
        $generator = new StandardGenerator(new NilPartitioner());
        $generator->setStateForTesting(1000, 0xFFFFFFFF);

        $k1 = $generator->nextWithoutClockCheckForTesting();
        $k2 = $generator->nextWithoutClockCheckForTesting();

        // StandardGenerator checks the POST-increment value (g.seq == 0
        // after g.seq++), so the timestamp bump happens on the SAME call
        // that wraps seq to 0 — k1 already shows the advanced timestamp.
        $this->assertSame(1001, $k1->timestamp);
        $this->assertSame(0xFFFFFFFF, $k1->seq);

        $this->assertSame(1001, $k2->timestamp);
        $this->assertSame(0, $k2->seq);

        $this->assertTrue($k1->validate());
        $this->assertTrue($k2->validate());
    }

    public function testSeqResetsToZeroWhenTimestampAdvances(): void
    {
        $generator = new StandardGenerator(new NilPartitioner());
        $generator->setStateForTesting(1000, 41);

        $k = $generator->nextWithoutClockCheckForTesting();

        $this->assertSame(41, $k->seq);

        // Force the stored timestamp backwards relative to "now" so the
        // live-clock branch in next() fires and resets seq.
        $generator->setStateForTesting(0, 999);
        $k2 = $generator->next();

        $this->assertSame(0, $k2->seq);
    }

    public function testNextReturnsAFreshKsuidEachTime(): void
    {
        $generator = new StandardGenerator(new NilPartitioner());

        $a = $generator->next();
        $b = $generator->next();

        $this->assertNotSame($a, $b);
    }
}
