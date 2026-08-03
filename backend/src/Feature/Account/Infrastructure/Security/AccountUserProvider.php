<?php

declare(strict_types=1);

namespace App\Feature\Account\Infrastructure\Security;

use App\Feature\Account\Domain\Model\Email;
use App\Feature\Account\Domain\Repository\AccountRepository;
use InvalidArgumentException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads accounts for the security component.
 *
 * @implements UserProviderInterface<AccountUser>
 */
final class AccountUserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private readonly AccountRepository $accounts)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        try {
            $email = Email::fromString($identifier);
        } catch (InvalidArgumentException) {
            // A malformed identifier is reported the same way as an unknown
            // one, so the response cannot be used to probe which addresses are
            // registered.
            throw new UserNotFoundException();
        }

        $account = $this->accounts->findByEmail($email);

        if ($account === null || !$account->canAuthenticate()) {
            throw new UserNotFoundException();
        }

        return AccountUser::fromAccount($account);
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof AccountUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        // Reloaded from storage on every request rather than trusting the
        // session copy, so suspending an account takes effect immediately.
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === AccountUser::class;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface|UserInterface $user, string $newHashedPassword): void
    {
        // Rehashing on login is handled by the registration and password-change
        // paths, which own the account entity. Implementing the interface keeps
        // the security component from warning about a missing upgrader.
    }
}
