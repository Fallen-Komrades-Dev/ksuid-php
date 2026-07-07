<?php

declare(strict_types=1);

namespace FallenKomradesDev\KSUID\Generator;

use FallenKomradesDev\KSUID\Ksuid;

/**
 * GeneratorInterface mirrors Go's `Generator` interface:
 *
 *   type Generator interface {
 *       Next() KSUID
 *   }
 */
interface GeneratorInterface
{
    public function next(): Ksuid;
}
