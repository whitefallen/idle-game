<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

use InvalidArgumentException;

/**
 * Everything the engine needs to resolve an encounter.
 *
 * Abilities and effects are passed in rather than looked up, so the engine
 * never reaches into the content registry — that is what keeps it a pure
 * function and testable without a container. See ADR-0002.
 */
final readonly class CombatInput
{
    /** @var list<Participant> Ordered by id ascending. */
    public array $participants;

    /** @var array<string, int> Participant id to stable RNG ordinal. */
    public array $ordinals;

    /** @var array<string, Ability> */
    public array $abilities;

    /** @var array<string, EffectDefinition> */
    public array $effects;

    /**
     * @param list<Participant>              $participants
     * @param array<string, Ability>         $abilities
     * @param array<string, EffectDefinition> $effects
     */
    public function __construct(
        array $participants,
        array $abilities,
        array $effects,
        public string $rulesetVersion,
    ) {
        if (count($participants) < 2) {
            throw new InvalidArgumentException('An encounter requires at least two participants.');
        }

        // Determinism rule R3: the engine never iterates an unordered
        // collection. Sorting by id gives a total order that does not depend on
        // insertion sequence, database row order, or hash iteration.
        usort($participants, static fn (Participant $a, Participant $b): int => strcmp($a->id, $b->id));

        $ordinals = [];
        $seenIds = [];
        $teams = [];

        foreach ($participants as $ordinal => $participant) {
            if (isset($seenIds[$participant->id])) {
                throw new InvalidArgumentException(
                    sprintf('Duplicate participant id "%s".', $participant->id),
                );
            }

            $seenIds[$participant->id] = true;
            $ordinals[$participant->id] = $ordinal;
            $teams[$participant->team->value] = true;

            foreach ($participant->abilityIds as $abilityId) {
                if (!isset($abilities[$abilityId])) {
                    throw new InvalidArgumentException(sprintf(
                        'Participant "%s" knows ability "%s", which was not supplied.',
                        $participant->id,
                        $abilityId,
                    ));
                }
            }

            foreach ($participant->battlePlan->rules as $index => $rule) {
                if (!$participant->knowsAbility($rule->abilityId)) {
                    throw new InvalidArgumentException(sprintf(
                        'Rule %d of participant "%s" uses ability "%s", which it does not know.',
                        $index + 1,
                        $participant->id,
                        $rule->abilityId,
                    ));
                }
            }
        }

        if (count($teams) < 2) {
            throw new InvalidArgumentException('An encounter requires participants on both teams.');
        }

        foreach ($abilities as $ability) {
            if ($ability->effectId !== null && !isset($effects[$ability->effectId])) {
                throw new InvalidArgumentException(sprintf(
                    'Ability "%s" applies effect "%s", which was not supplied.',
                    $ability->id,
                    $ability->effectId,
                ));
            }
        }

        $this->participants = $participants;
        $this->ordinals = $ordinals;
        $this->abilities = $abilities;
        $this->effects = $effects;
    }

    public function ordinalOf(string $participantId): int
    {
        return $this->ordinals[$participantId]
            ?? throw new InvalidArgumentException(sprintf('Unknown participant "%s".', $participantId));
    }

    public function ability(string $abilityId): Ability
    {
        return $this->abilities[$abilityId]
            ?? throw new InvalidArgumentException(sprintf('Unknown ability "%s".', $abilityId));
    }

    public function effect(string $effectId): EffectDefinition
    {
        return $this->effects[$effectId]
            ?? throw new InvalidArgumentException(sprintf('Unknown effect "%s".', $effectId));
    }
}
