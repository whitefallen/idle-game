<?php

declare(strict_types=1);

namespace App\Feature\Account\Application;

use App\Feature\Account\Domain\Entity\Account;
use App\Feature\Account\Domain\Model\Email;
use App\Feature\Account\Domain\Repository\AccountRepository;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Persistence\DuplicateKeyException;
use App\Platform\Persistence\TransactionManager;
use App\Platform\Security\PasswordHasher;
use App\Platform\Uid\IdentifierGenerator;
use InvalidArgumentException;

final class RegisterAccountHandler
{
    private const int MIN_PASSWORD_LENGTH = 10;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PasswordHasher $passwordHasher,
        private readonly IdentifierGenerator $identifiers,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(string $rawEmail, string $password): Account
    {
        try {
            $email = Email::fromString($rawEmail);
        } catch (InvalidArgumentException $e) {
            throw ApiException::of(ErrorCode::ValidationFailed, $e->getMessage(), ['field' => 'email']);
        }

        // A length floor only. Composition rules (a digit, a symbol, a capital)
        // measurably push people toward predictable substitutions and away from
        // password managers, so length is the requirement that actually helps.
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw ApiException::of(
                ErrorCode::ValidationFailed,
                sprintf('A password must be at least %d characters.', self::MIN_PASSWORD_LENGTH),
                ['field' => 'password', 'min_length' => self::MIN_PASSWORD_LENGTH],
            );
        }

        if ($this->accounts->existsByEmail($email)) {
            throw ApiException::of(ErrorCode::EmailAlreadyRegistered, 'That email address is already registered.');
        }

        $account = new Account(
            $this->identifiers->generate(),
            $email,
            $this->passwordHasher->hash($password),
            $this->clock->now(),
        );

        $this->accounts->save($account);

        // Staged, not written immediately: if the flush below fails on a
        // concurrent registration, no record should claim an account was made.
        $this->audit->record(
            AuditAction::AccountRegistered,
            ['email' => $email->value],
            $account->id(),
        );

        try {
            $this->transactions->commit();
        } catch (DuplicateKeyException) {
            // The check above loses to a concurrent registration; the unique
            // index is the actual guarantee, and this converts it into the same
            // error the caller would otherwise have received.
            throw ApiException::of(ErrorCode::EmailAlreadyRegistered, 'That email address is already registered.');
        }

        return $account;
    }
}
