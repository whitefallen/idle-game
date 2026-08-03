<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Model\PlanIssue;
use App\Feature\Combat\Domain\Repository\AbilityRepository;
use App\Feature\Combat\Domain\Service\BattlePlanValidator;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Persistence\TransactionManager;
use DomainException;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * Replaces a character's battle plan.
 *
 * The plan is the game's signature mechanic and arrives entirely from untrusted
 * client input, so it is validated here against the closed grammar before it can
 * reach the combat engine. Editing is free and has no cooldown: build iteration
 * is meant to be the enjoyable part, and nothing about a plan change needs to
 * cost a player anything.
 */
final class UpdateBattlePlanHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly AbilityRepository $abilities,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rules
     */
    public function __invoke(Uuid $accountId, Uuid $characterId, array $rules): BattlePlanUpdate
    {
        return $this->transactions->transactional(
            function () use ($accountId, $characterId, $rules): BattlePlanUpdate {
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                $issues = BattlePlanValidator::validate(
                    $rules,
                    $this->abilities->all(),
                    $character->abilityIds(),
                );

                $blocking = array_values(array_filter(
                    $issues,
                    static fn (PlanIssue $issue): bool => $issue->code->isBlocking(),
                ));

                if ($blocking !== []) {
                    // Every problem at once. An editor that reports one error
                    // per save makes fixing a plan a guessing game.
                    throw ApiException::of(
                        ErrorCode::ValidationFailed,
                        'That battle plan cannot be used.',
                        ['issues' => array_map(static fn (PlanIssue $i): array => $i->toArray(), $blocking)],
                    );
                }

                try {
                    $character->replaceBattlePlan(BattlePlan::fromArray($rules), $this->clock->now());
                } catch (DomainException|InvalidArgumentException $e) {
                    // The validator should have caught anything the entity
                    // rejects. Reaching here means the two disagree, which is a
                    // defect rather than bad input — but it must not surface as
                    // a 500 to the player.
                    throw ApiException::of(ErrorCode::ValidationFailed, $e->getMessage());
                }

                $warnings = array_values(array_filter(
                    $issues,
                    static fn (PlanIssue $issue): bool => !$issue->code->isBlocking(),
                ));

                return new BattlePlanUpdate($character, $warnings);
            },
        );
    }
}
