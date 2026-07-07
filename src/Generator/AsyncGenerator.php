<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Generator;

use FallenKomradesDev\KSUID\Ksuid;
use FallenKomradesDev\KSUID\Partition\NilPartitioner;
use FallenKomradesDev\KSUID\Partition\PartitionerInterface;

/**
 * AsyncGenerator is a port of Go's AsyncGenerator, adapted to PHP's
 * execution model.
 *
 * Go's AsyncGenerator runs a background goroutine that wakes up once per
 * second (via time.Ticker) and refreshes a cached, atomically-stored
 * timestamp, while Next() does a lock-free atomic increment of the
 * sequence counter. That design exists specifically to avoid taking a
 * mutex on every call in a multi-goroutine, multi-core environment.
 *
 * Standard PHP (php-fpm, mod_php, classic CLI scripts) has no background
 * threads and no goroutine scheduler, so there is nothing to "run in the
 * background" in the literal Go sense. This port preserves the same
 * *external* lifecycle shape — run() / stop() / tick() — and, crucially,
 * the same *sequencing semantics*, including the specific bug this class's
 * Go counterpart fixes: the sequence counter must only reset when the
 * timestamp has genuinely advanced, never merely because a timer fired.
 *
 * Two usage patterns are supported:
 *
 *   1. Explicit ticking (matches Go's model exactly): call tick()
 *      periodically from your own timer/event-loop callback — e.g. a
 *      Swoole/ReactPHP/RoadRunner periodic timer, or any other mechanism
 *      that fires roughly once a second — then call next() to generate
 *      IDs. next() never touches the wall clock itself, exactly like Go's
 *      Next(), which only ever reads ts/seq that the background goroutine
 *      maintains.
 *
 *   2. Polling (PHP-specific convenience, no Go equivalent): call
 *      autoNext() instead of next(). It calls tick() immediately before
 *      next(), so each call checks the wall clock and advances ts itself —
 *      convenient in plain scripts with no separate event loop to drive
 *      tick() for you.
 *
 * Whichever style you use, the overflow guard and the "only reset seq when
 * ts genuinely advances" guard (the fix for the historical 100ms-ticker
 * bug) behave identically to Go's implementation.
 */
final class AsyncGenerator implements GeneratorInterface
{
    private readonly PartitionerInterface|\Closure $partitioner;

    private int $ts      = 0;
    private int $seq     = 0;
    private bool $running = false;

    /**
     * @param PartitionerInterface|\Closure|null $partitioner Defaults to
     *        NilPartitioner.
     */
    public function __construct(PartitionerInterface|\Closure|null $partitioner = null)
    {
        $this->partitioner = $partitioner ?? new NilPartitioner();
        $this->ts          = $this->currentEpochSeconds();
    }

    /**
     * run marks the generator as active. Provided for API-shape parity
     * with Go's NewAsyncGenerator() (which starts its goroutine
     * immediately) / Run(stop) (the goroutine body). There is no actual
     * background thread to start in PHP; this simply flips an internal
     * flag and is safe to call multiple times.
     */
    public function run(): void
    {
        $this->running = true;
    }

    /**
     * stop marks the generator as inactive. Mirrors Go's Stop(), which
     * signals the background goroutine to exit and waits for it. Safe to
     * call more than once, matching the Go implementation's idempotency.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * isRunning reports whether run() has been called without a matching
     * stop(). Not present in the Go API (which has no equivalent getter)
     * but useful for test assertions in a language without goroutine
     * introspection.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * tick applies one "timer fired" event, exactly mirroring the guard
     * inside Go's AsyncGenerator.Run():
     *
     *   newTs := uint32(t.UTC().Unix() - Epoch)
     *   if newTs > g.ts.Load() {
     *       g.ts.Store(newTs)
     *       g.seq.Store(0)
     *   }
     *
     * This is the fix for the historical "100ms ticker" bug: seq must
     * never be reset just because a timer fired — only when the
     * timestamp has genuinely advanced to a new second.
     */
    public function tick(): void
    {
        $newTs = $this->currentEpochSeconds();

        if ($newTs > $this->ts) {
            $this->ts  = $newTs;
            $this->seq = 0;
        }
    }

    /**
     * next generates the next KSUID. Mirrors Go's AsyncGenerator.Next()
     * exactly: an atomic-style post-increment of seq (returning the
     * pre-increment value), with an overflow guard that advances ts when
     * seq wraps back to 0.
     *
     * IMPORTANT — this method deliberately does NOT touch the wall clock.
     * In Go, only the background goroutine (Run()) ever refreshes ts; Next()
     * only reads it. This class preserves that separation of concerns
     * faithfully: call tick() yourself (directly, or via your event loop /
     * a real timer) to advance ts when a new second starts. If you want
     * "checks the clock on every call" semantics instead, call tick()
     * immediately before next() — see the autoNext() convenience method.
     */
    public function next(): Ksuid
    {
        $seq = $this->seq;
        $this->seq = ($this->seq + 1) & Ksuid::MASK32;

        // Overflow guard: seq just wrapped to 0, meaning the counter
        // exhausted all 4.29 billion values and cycled back around. This
        // mirrors Go precisely: `seq := g.seq.Add(1) - 1` yields the
        // pre-increment value, and when that pre-increment value is 0, it
        // means seq was 0xFFFFFFFF before this call and just wrapped.
        // Advance ts so the new seq=0 lands in a different bucket than the
        // previous seq=0, preventing a duplicate (Timestamp, Seq) pair.
        // Gated on ts > 0 to avoid bumping on normal generator startup,
        // exactly matching the Go guard.
        if ($seq === 0 && $this->ts > 0) {
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
     * autoNext is a convenience wrapper that calls tick() immediately
     * before next(), giving "always check the wall clock first" semantics
     * in a single call. This has no Go equivalent (Go achieves the same
     * end result via the background goroutine + plain Next()) but is
     * useful in plain single-threaded PHP scripts where there is no
     * separate ticking thread driving tick() for you.
     */
    public function autoNext(): Ksuid
    {
        $this->tick();

        return $this->next();
    }

    /**
     * currentEpochSeconds returns seconds-since-Ksuid::getEpoch() for "now".
     */
    private function currentEpochSeconds(): int
    {
        return (time() - Ksuid::getEpoch()) & Ksuid::MASK32;
    }

    /**
     * setStateForTesting directly assigns the internal ts/seq state.
     * Test-only seam; see StandardGenerator::setStateForTesting() for
     * rationale.
     */
    public function setStateForTesting(int $ts, int $seq): void
    {
        $this->ts  = $ts & Ksuid::MASK32;
        $this->seq = $seq & Ksuid::MASK32;
    }

    /**
     * getStateForTesting returns the internal [ts, seq] state.
     *
     * @return array{0:int,1:int}
     */
    public function getStateForTesting(): array
    {
        return [$this->ts, $this->seq];
    }
}
