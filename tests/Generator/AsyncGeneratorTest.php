<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Tests\Generator;

use FallenKomradesDev\KSUID\Generator\AsyncGenerator;
use FallenKomradesDev\KSUID\Partition\NilPartitioner;
use FallenKomradesDev\KSUID\Partition\StringPartitioner;
use PHPUnit\Framework\TestCase;

/**
 * Direct port of the AsyncGenerator tests in ksuid-go/generator_test.go,
 * adapted for PHP's lack of goroutines/background threads. See the
 * class-level docblock on AsyncGenerator for the full rationale behind
 * run()/stop()/tick()/autoNext().
 *
 * @covers \FallenKomradesDev\KSUID\Generator\AsyncGenerator
 */
final class AsyncGeneratorTest extends TestCase
{
    public function testGeneratesUniqueIdsAcrossManyCalls(): void
    {
        $generator = new AsyncGenerator(new NilPartitioner());
        $seen = [];

        for ($i = 0; $i < 10_000; $i++) {
            $hex = $generator->autoNext()->toString();

            $this->assertArrayNotHasKey($hex, $seen, 'duplicate KSUID generated: ' . $hex);
            $seen[$hex] = true;
        }

        $this->assertCount(10_000, $seen);
    }

    public function testEveryGeneratedKsuidValidates(): void
    {
        $generator = new AsyncGenerator(new NilPartitioner());

        for ($i = 0; $i < 1_000; $i++) {
            $k = $generator->autoNext();

            $this->assertTrue($k->validate(), 'generated KSUID failed validate(): ' . $k->toString());
        }
    }

    public function testDefaultPartitionerIsNilWhenNoneProvided(): void
    {
        $generator = new AsyncGenerator();
        $k = $generator->autoNext();

        $this->assertSame(0, $k->partition);
    }

    public function testCustomPartitionerIsUsedForEveryGeneratedId(): void
    {
        $generator = new AsyncGenerator(new StringPartitioner('shard-B'));
        $expectedPartition = (new StringPartitioner('shard-B'))();

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame($expectedPartition, $generator->autoNext()->partition);
        }
    }

    public function testRunAndStopToggleIsRunningFlag(): void
    {
        $generator = new AsyncGenerator(new NilPartitioner());

        $this->assertFalse($generator->isRunning());

        $generator->run();
        $this->assertTrue($generator->isRunning());

        $generator->stop();
        $this->assertFalse($generator->isRunning());
    }

    public function testRunIsIdempotent(): void
    {
        $generator = new AsyncGenerator(new NilPartitioner());

        $generator->run();
        $generator->run();

        $this->assertTrue($generator->isRunning());
    }

    public function testStopIsIdempotent(): void
    {
        $generator = new AsyncGenerator(new NilPartitioner());

        $generator->stop();
        $generator->stop();

        $this->assertFalse($generator->isRunning());
    }

    /**
     * This is the critical regression test for the historical "100ms
     * ticker" bug: next() (unlike autoNext()) must NEVER touch the wall
     * clock or reset seq on its own — only tick() (or its caller) controls
     * when seq resets. Calling next() repeatedly with a fixed fixture
     * state must produce a pure, uninterrupted increment sequence.
     *
     * NOTE: the starting seq value is deliberately non-zero (1, not 0).
     * This exactly mirrors a real quirk/property of the Go implementation
     * itself (not a PHP-specific issue): both Next() methods gate their
     * overflow guard on "the returned/post-increment seq equals 0 AND
     * ts > 0" specifically so a wrap-around is indistinguishable from a
     * fresh start at seq=0 with a non-zero ts. If you seed ts to a
     * non-zero fixture value AND seq to 0, the very first call's returned
     * seq is legitimately 0, which trips the overflow guard immediately —
     * in both languages. This is documented directly in Go's source
     * comment "We gate on ts > 0 to avoid bumping on normal generator
     * startup" (startup meaning ts == 0, not seq == 0). Seeding seq to a
     * non-zero value sidesteps that inherent ambiguity entirely so this
     * test can cleanly isolate "next() doesn't touch the clock".
     */
    public function testNextNeverTouchesTheClockOrResetsSeqOnItsOwn(): void
    {
        $generator = new AsyncGenerator(new NilPartitioner());
        $generator->setStateForTesting(1000, 1);

        for ($i = 0; $i < 5; $i++) {
            $generator->next();
        }

        [$tsBefore, $seqBefore] = $generator->getStateForTesting();
        $this->assertSame(1000, $tsBefore, 'next() must never advance ts on its own');
        $this->assertSame(6, $seqBefore);

        $generator->next();
        [$tsAfter, $seqAfter] = $generator->getStateForTesting();

        $this->assertSame(1000, $tsAfter);
        $this->assertSame(7, $seqAfter);
    }

    public function testTickResetsSeqOnlyWhenTimestampGenuinelyAdvances(): void
    {
        $generator = new AsyncGenerator(new NilPartitioner());
        $generator->setStateForTesting(1000, 5);

        // Calling tick() while the stored ts is already far in the future
        // relative to "now" (impossible in practice, but we simulate the
        // opposite — ts in the past — to force a genuine advance) and
        // confirm seq resets to 0 only in that case.
        $generator->setStateForTesting(0, 5); // ts=0 guarantees "now" > ts
        $generator->tick();

        [$tsAfterTick, $seqAfterTick] = $generator->getStateForTesting();

        $this->assertGreaterThan(0, $tsAfterTick);
        $this->assertSame(0, $seqAfterTick);
    }

    public function testTickIsANoOpWhenTimestampHasNotAdvanced(): void
    {
        $generator = new AsyncGenerator(new NilPartitioner());

        // Seed ts to "now" (the constructor already does this) then
        // immediately tick — since no real time has passed, ts should not
        // change and seq must remain untouched.
        [$tsInitial, ] = $generator->getStateForTesting();
        $generator->setStateForTesting($tsInitial, 7);

        $generator->tick();

        [$tsAfter, $seqAfter] = $generator->getStateForTesting();
        $this->assertSame($tsInitial, $tsAfter);
        $this->assertSame(7, $seqAfter);
    }

    /**
     * Ported from Go's TestAsyncGenerator_SeqOverflow. Unlike
     * StandardGenerator, AsyncGenerator.Next() never touches the wall
     * clock, so this test is NOT subject to the live-clock-guard quirk
     * documented on StandardGeneratorTest — it deterministically and
     * exactly reproduces Go's atomic Add(1)-1 semantics.
     */
    public function testSeqOverflowExactGoSemantics(): void
    {
        $generator = new AsyncGenerator(new NilPartitioner());
        $generator->setStateForTesting(2000, 0xFFFFFFFF);

        $k1 = $generator->next();
        $k2 = $generator->next();

        $this->assertFalse(
            $k1->timestamp === $k2->timestamp && $k1->seq === $k2->seq,
            'overflow handling produced a duplicate (Timestamp, Seq) pair'
        );

        // Exact trace matching Go's `seq := g.seq.Add(1) - 1` semantics:
        // starting counter = 0xFFFFFFFF, ts = 2000.
        // call1: Add(1) wraps counter to 0; returned seq = newVal-1 = 0xFFFFFFFF.
        //        seq != 0, so the overflow guard does NOT fire yet.
        // call2: Add(1) makes counter 1; returned seq = 1-1 = 0.
        //        seq == 0, so the overflow guard fires: ts becomes 2001.
        $this->assertSame(2000, $k1->timestamp);
        $this->assertSame(0xFFFFFFFF, $k1->seq);

        $this->assertSame(2001, $k2->timestamp);
        $this->assertSame(0, $k2->seq);

        $this->assertTrue($k1->validate());
        $this->assertTrue($k2->validate());
    }

    public function testOverflowGuardDoesNotFireOnFreshGeneratorStartup(): void
    {
        // The overflow guard is gated on `$this->ts > 0` specifically to
        // avoid spuriously bumping ts on a brand-new generator whose ts
        // happens to start at 0 (epoch boundary) and whose first seq
        // returned is also 0 (the normal, non-overflow case).
        $generator = new AsyncGenerator(new NilPartitioner());
        $generator->setStateForTesting(0, 0);

        $k = $generator->next();

        $this->assertSame(0, $k->timestamp, 'ts must not be bumped on ordinary startup at ts=0');
        $this->assertSame(0, $k->seq);
    }

    public function testAutoNextCombinesTickAndNext(): void
    {
        $generator = new AsyncGenerator(new NilPartitioner());
        $generator->setStateForTesting(0, 5); // force tick() to see an advance

        $k = $generator->autoNext();

        $this->assertGreaterThan(0, $k->timestamp);
        $this->assertSame(0, $k->seq); // tick() reset seq to 0 before next()
        $this->assertTrue($k->validate());
    }

    public function testAcceptsPlainClosureAsPartitioner(): void
    {
        $generator = new AsyncGenerator(fn (): int => 99);

        $this->assertSame(99, $generator->autoNext()->partition);
    }
}
