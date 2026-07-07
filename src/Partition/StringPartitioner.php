<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Partition;

use FallenKomradesDev\KSUID\XXHash32\XXHash32;

/**
 * StringPartitioner creates a partitioner from a string by hashing the
 * full string with xxHash32 (seed = 0). This ensures good distribution
 * even for strings that share a common prefix — e.g. "user-123" and
 * "user-456" produce different partitions, unlike a naive 4-byte
 * truncation scheme would.
 *
 * Direct port of Go's StringPartitioner(v string) PartitionFunc: the hash
 * is computed once at construction time and the resulting closure-like
 * object always returns that same precomputed value (pure / deterministic).
 */
final class StringPartitioner implements PartitionerInterface
{
    private readonly int $value;

    public function __construct(string $input)
    {
        $this->value = XXHash32::checksumZero($input);
    }

    public function __invoke(): int
    {
        return $this->value;
    }
}
