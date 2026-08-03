<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * The hasher name is injected rather than hard-coded.
 *
 * The security component resolves a hasher by *user class* when checking
 * credentials at login, so registration must hash under that same key or the
 * two paths silently disagree — a password would be written with one
 * configuration and verified with another. Naming it in the service wiring lets
 * one configuration serve both without Platform importing a feature's class.
 */
final class SymfonyPasswordHasher implements PasswordHasher
{
    public function __construct(
        private readonly PasswordHasherFactoryInterface $factory,
        private readonly string $hasherName,
    ) {
    }

    public function hash(string $plaintext): string
    {
        return $this->factory->getPasswordHasher($this->hasherName)->hash($plaintext);
    }

    public function verify(string $hash, string $plaintext): bool
    {
        return $this->factory->getPasswordHasher($this->hasherName)->verify($hash, $plaintext);
    }
}
