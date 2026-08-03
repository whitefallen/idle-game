<?php

declare(strict_types=1);

namespace App\Platform\Security;

/**
 * Password hashing, abstracted so the Application layer never has to reach into
 * the security component or reference an Infrastructure class.
 *
 * The algorithm is deliberately not named anywhere in application code: it is
 * configured once and can be upgraded without touching a single handler.
 */
interface PasswordHasher
{
    public function hash(string $plaintext): string;

    public function verify(string $hash, string $plaintext): bool;
}
