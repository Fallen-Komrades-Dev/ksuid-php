<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Partition;

/**
 * NilPartitioner places everything into partition zero, disabling
 * partitioning. Direct port of Go's NilPartitioner.
 *
 * This is the default partitioner used by StandardGenerator/AsyncGenerator
 * when none is supplied.
 */
final class NilPartitioner implements PartitionerInterface
{
    public function __invoke(): int
    {
        return 0;
    }
}
