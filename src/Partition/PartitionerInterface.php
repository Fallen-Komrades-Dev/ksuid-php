<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Partition;

/**
 * PartitionerInterface is the PHP analogue of Go's `PartitionFunc func() uint32`.
 *
 * Go uses a bare function type so any `func() uint32` can be a partitioner.
 * PHP has no first-class function types in the same sense usable as a
 * property type with autocompletion-friendly contracts, so this interface
 * plays that role: any callable conforming to it (via __invoke) can be
 * used as a partitioner.
 *
 * A plain `callable` (Closure, first-class callable syntax, etc.) that
 * returns an int is also accepted everywhere a partitioner is expected —
 * see Generator\StandardGenerator and Generator\AsyncGenerator — so
 * implementing this interface is a convenience, not a strict requirement.
 */
interface PartitionerInterface
{
    /**
     * Returns the partition value (treated as an unsigned 32-bit integer;
     * values are masked to [0, 0xFFFFFFFF] by the generator).
     */
    public function __invoke(): int;
}
