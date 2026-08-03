<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Character\Domain\Service\StartingLoadout;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Persistence\DuplicateKeyException;
use App\Platform\Persistence\TransactionManager;
use App\Platform\Uid\IdentifierGenerator;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final class CreateCharacterHandler
{
    /**
     * Additional slots are an Emberdust convenience purchase, never a gold
     * sink: putting an earned and a paid currency in competition for the same
     * need is what makes free players feel taxed. See docs/economy.md section 6.
     */
    public const int FREE_CHARACTER_SLOTS = 2;

    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly IdentifierGenerator $identifiers,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Uuid $accountId, string $name): Character
    {
        $name = trim($name);

        try {
            Character::assertValidName($name);
        } catch (InvalidArgumentException $e) {
            throw ApiException::of(ErrorCode::ValidationFailed, $e->getMessage(), ['field' => 'name']);
        }

        if ($this->characters->countByAccount($accountId) >= self::FREE_CHARACTER_SLOTS) {
            throw ApiException::of(
                ErrorCode::CharacterLimitReached,
                sprintf('An account may hold %d characters.', self::FREE_CHARACTER_SLOTS),
                ['limit' => self::FREE_CHARACTER_SLOTS],
            );
        }

        if ($this->characters->existsByName($name)) {
            throw ApiException::of(ErrorCode::CharacterNameTaken, 'That name is already taken.');
        }

        $character = new Character(
            $this->identifiers->generate(),
            $accountId,
            $name,
            StartingLoadout::battlePlan(),
            StartingLoadout::abilityIds(),
            $this->clock->now(),
        );

        $this->characters->save($character);

        $this->audit->record(
            AuditAction::CharacterCreated,
            ['name' => $name],
            $accountId,
            $character->id(),
        );

        try {
            $this->transactions->commit();
        } catch (DuplicateKeyException) {
            throw ApiException::of(ErrorCode::CharacterNameTaken, 'That name is already taken.');
        }

        return $character;
    }
}
