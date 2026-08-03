<?php

declare(strict_types=1);

namespace App\Platform\Uid;

use Symfony\Component\Uid\Uuid;

/**
 * Supplies entity identifiers.
 *
 * Injected rather than called statically inside constructors so that entity
 * construction stays deterministic: a test can pin identifiers, and the
 * Application layer stays the single place where ambient state enters.
 */
interface IdentifierGenerator
{
    public function generate(): Uuid;
}
