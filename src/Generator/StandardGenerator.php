<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Generator;

use FallenKomradesDev\KSUID\Ksuid;
use FallenKomradesDev\KSUID\Partition\NilPartitioner;
use FallenKomradesDev\KSUID\Partition\PartitionerInterface;

/**
 * StandardGenerator is a direct, behaviour-preserving port of Go's
 * StandardGenerator.
 *
 * Concurrency note: Go's StandardGenerator guards its state with a
 * sync.Mutex because goroutines can call Next() from multiple OS threads
 * simultaneously. Standard PHP request handling (mod_php, php-fpm,
 * classic CLI scripts) has exactly one thread of execution per request,
 * so there's no analogous low-level data race to guard against at the
 * language level. If you embed this generator in a genuinely multi-threaded
 * PHP runtime (pthreads, parallel, Swoole/RoadRunner workers sharing
 * memory, etc.) you are responsible for serialising calls to next()
 * yourself — exactly like you'd need to in any other language without
 * built-in green-thread-safe primitives. The sequencing *logic* itself
 * (timestamp/seq/overflow handling) is ported faithfully below.
 */
final class StandardGenerator implements GeneratorInterface
{
    private readonly PartitionerInterface|\Closure $partitioner;

    private int $ts  = 0;
    private int $seq = 0;

    /**
     * @param PartitionerInterface|\Closure|null $partitioner Defaults to
     *        NilPartitioner (everything in partition 0), matching Go's
     *        defaultGenerator.
     */
    public function __construct(PartitionerInterface|\Closure|null $partitioner = null)
    {
        $this->partitioner = $partitioner ?? new NilPartitioner();
    }

    /**
     * next generates the next KSUID.
     *
     * The sequence is monotonically increasing within each second. If the
     * sequence overflows 32 bits within a single second, the stored
     * timestamp is advanced by one to guarantee uniqueness without
     * blocking — exactly mirroring the Go overflow guard.
     */
    public function next(): Ksuid
    {
        $ts = $this->currentEpochSeconds();

        if ($ts > $this->ts) {
            $this->seq = 0;
            $this->ts  = $ts;
        }

        $seq = $this->seq;
        $this->seq = ($this->seq + 1) & Ksuid::MASK32;

        // Overflow guard: seq just wrapped to 0, meaning all 4.29 billion
        // sequence values were exhausted within one second. Advance the
        // stored timestamp by one to prevent the next seq=0 from colliding
        // with the previous seq=0.
        if ($this->seq === 0) {
            $this->ts = ($this->ts + 1) & Ksuid::MASK32;
        }

        $partitioner = $this->partitioner;

        $k = new Ksuid(
            timestamp: $this->ts,
            seq: $seq,
            partition: $partitioner(),
        );

        return new Ksuid($k->timestamp, $k->seq, $k->partition, $k->computeHash());
    }

    /**
     * currentEpochSeconds returns seconds-since-Ksuid::getEpoch() for "now",
     * matching Go's `uint32(time.Now().UTC().Unix() - Epoch)`.
     */
    private function currentEpochSeconds(): int
    {
        return (time() - Ksuid::getEpoch()) & Ksuid::MASK32;
    }

    /**
     * setStateForTesting directly assigns the internal ts/seq state.
     *
     * This exists purely to let the test suite deterministically reproduce
     * the Go test's pattern of writing `g.ts = 1000; g.seq = ^uint32(0)`
     * directly to unexported fields to force the overflow branch. PHP has
     * no package-private field access from outside the class, so this
     * explicit (clearly-named, test-only) seam is the idiomatic substitute.
     * Not part of the public API surface for normal use.
     */
    public function setStateForTesting(int $ts, int $seq): void
    {
        $this->ts  = $ts & Ksuid::MASK32;
        $this->seq = $seq & Ksuid::MASK32;
    }

    /**
     * getStateForTesting returns the internal [ts, seq] state.
     * Test-only seam, see setStateForTesting().
     *
     * @return array{0:int,1:int}
     */
    public function getStateForTesting(): array
    {
        return [$this->ts, $this->seq];
    }

    /**
     * nextWithoutClockCheckForTesting performs the seq-increment and
     * overflow-guard logic in isolation, WITHOUT the live wall-clock check
     * that next() performs first.
     *
     * Rationale: the original Go test for this overflow path
     * (TestStandardGenerator_SeqOverflow) sets g.ts to a small fixture
     * value like 1000 and expects the overflow branch to fire — but
     * because Next() always compares against the REAL current time first,
     * and real time is always vastly larger than 1000, that live-clock
     * guard resets seq to 0 before the overflow branch is ever reached.
     * This is a property of the Go implementation too, not a PHP quirk;
     * the original test's assertions are loose enough to pass anyway
     * (it only checks "no duplicate" and "ts advanced past 1000", both of
     * which hold via the normal reset path). To genuinely exercise the
     * overflow guard deterministically — which the original test's name
     * promises but its assertions don't actually prove — this seam skips
     * the clock check entirely. See StandardGeneratorTest for both the
     * Go-fidelity (loose) test and this stricter deterministic one.
     */
    public function nextWithoutClockCheckForTesting(): Ksuid
    {
        $seq = $this->seq;
        $this->seq = ($this->seq + 1) & Ksuid::MASK32;

        if ($this->seq === 0) {
            $this->ts = ($this->ts + 1) & Ksuid::MASK32;
        }

        $partitioner = $this->partitioner;

        $k = new Ksuid(
            timestamp: $this->ts,
            seq: $seq,
            partition: $partitioner(),
        );

        return new Ksuid($k->timestamp, $k->seq, $k->partition, $k->computeHash());
    }
}
