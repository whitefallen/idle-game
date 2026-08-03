<?php

declare(strict_types=1);

namespace App\Platform\Persistence;

use RuntimeException;
use Throwable;

/**
 * A unique constraint was violated.
 *
 * Exists so that handlers can convert a race into a sensible error without
 * importing a Doctrine exception. Application code checks uniqueness before
 * writing to produce a good message, but that check always loses to a
 * concurrent request — the index is the actual guarantee, and this is how it
 * reports itself.
 */
final class DuplicateKeyException extends RuntimeException
{
    public function __construct(string $message = 'A unique constraint was violated.', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
