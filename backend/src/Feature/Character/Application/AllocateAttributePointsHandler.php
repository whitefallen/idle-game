<?php

declare(strict_types=1);

namespace App\Feature\Character\Application;

use App\Feature\Character\Domain\Entity\Character;
use App\Feature\Character\Domain\Model\Attribute;
use App\Feature\Character\Domain\Repository\CharacterRepository;
use App\Platform\Audit\AuditAction;
use App\Platform\Audit\AuditLogger;
use App\Platform\Clock\Clock;
use App\Platform\Http\ApiException;
use App\Platform\Http\ErrorCode;
use App\Platform\Persistence\TransactionManager;
use DomainException;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

final class AllocateAttributePointsHandler
{
    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly Clock $clock,
        private readonly TransactionManager $transactions,
        private readonly AuditLogger $audit,
        private readonly CharacterStats $stats,
    ) {
    }

    /**
     * @param array<string, mixed> $allocation Keyed by Attribute value.
     */
    public function __invoke(Uuid $accountId, Uuid $characterId, array $allocation): Character
    {
        $normalised = $this->normalise($allocation);

        return $this->transactions->transactional(
            function () use ($accountId, $characterId, $normalised): Character {
                // Locked for update: without it, two concurrent allocations both
                // read the same unspent-point count and both succeed.
                $character = $this->characters->findByIdForUpdate($characterId);

                if ($character === null || !$character->isOwnedBy($accountId)) {
                    throw ApiException::notFound('Character');
                }

                try {
                    // Equipment is supplied so the recomputed power score
                    // reflects what the character is actually wearing.
                    $character->allocatePoints(
                        $normalised,
                        $this->stats->equipmentOf($character->id()),
                        $this->clock->now(),
                    );
                } catch (DomainException $e) {
                    throw ApiException::of(
                        ErrorCode::InsufficientPoints,
                        $e->getMessage(),
                        ['available' => $character->unspentPoints()],
                    );
                } catch (InvalidArgumentException $e) {
                    throw ApiException::of(ErrorCode::ValidationFailed, $e->getMessage());
                }

                $this->audit->record(
                    AuditAction::AttributesAllocated,
                    [
                        'allocation' => $normalised,
                        'attributes' => $character->attributes()->toArray(),
                        'unspentPoints' => $character->unspentPoints(),
                    ],
                    $accountId,
                    $character->id(),
                );

                return $character;
            },
        );
    }

    /**
     * @param array<string, mixed> $allocation
     *
     * @return array<string, int>
     */
    private function normalise(array $allocation): array
    {
        $normalised = [];

        foreach ($allocation as $key => $value) {
            $attribute = Attribute::tryFrom((string) $key);

            if ($attribute === null) {
                throw ApiException::of(
                    ErrorCode::ValidationFailed,
                    sprintf('Unknown attribute "%s".', (string) $key),
                    ['allowed' => array_map(static fn (Attribute $a): string => $a->value, Attribute::cases())],
                );
            }

            if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
                throw ApiException::of(
                    ErrorCode::ValidationFailed,
                    sprintf('Points for %s must be a whole number.', $attribute->value),
                );
            }

            $points = (int) $value;

            if ($points < 0) {
                throw ApiException::of(
                    ErrorCode::ValidationFailed,
                    'Points cannot be negative. Use respec to reset an allocation.',
                );
            }

            if ($points > 0) {
                $normalised[$attribute->value] = $points;
            }
        }

        if ($normalised === []) {
            throw ApiException::of(ErrorCode::ValidationFailed, 'Allocate at least one point.');
        }

        return $normalised;
    }
}
