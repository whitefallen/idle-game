<?php

declare(strict_types=1);

namespace App\Feature\Dungeon\Application;

use App\Feature\Character\Application\GrantDisciplineHandler;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Feature\Dungeon\Domain\Repository\DungeonRunRepository;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Persistence\TransactionManager;
use DomainException;
use Symfony\Component\Uid\Uuid;

/**
 * Confirms a discipline pick offered by a prior dungeon clear.
 *
 * A separate request from entering the dungeon on purpose: the offer is
 * computed and stored the moment the run clears (EnterDungeonHandler), but
 * confirming it is its own deliberate action — docs/dungeons.md section 3
 * requires an explicit pick-and-confirm even when only one option remains,
 * so this endpoint exists regardless of how many were offered.
 */
final class PickDungeonDisciplineHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly DungeonRunRepository $runs,
        private readonly GrantDisciplineHandler $grantDiscipline,
        private readonly AuditLogger $audit,
        private readonly TransactionManager $transactions,
    ) {
    }

    public function __invoke(
        Uuid $accountId,
        Uuid $characterId,
        Uuid $runId,
        string $disciplineId,
    ): PickedDungeonDiscipline {
        return $this->transactions->transactional(
            function () use ($accountId, $characterId, $runId, $disciplineId): PickedDungeonDiscipline {
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                $run = $this->runs->findByIdForUpdate($runId);

                if ($run === null || !$run->belongsTo($characterId)) {
                    throw ApiException::notFound('Dungeon run');
                }

                try {
                    $run->pickDiscipline($disciplineId);
                } catch (DomainException $e) {
                    throw ApiException::of(ErrorCode::Conflict, $e->getMessage());
                }

                ($this->grantDiscipline)($characterId, $disciplineId);
                $this->runs->save($run);

                $this->audit->record(
                    AuditAction::DungeonDisciplinePicked,
                    ['dungeonRunId' => $runId->toRfc4122(), 'disciplineId' => $disciplineId],
                    $accountId,
                    $characterId,
                );

                return new PickedDungeonDiscipline($run, $character);
            },
        );
    }
}
