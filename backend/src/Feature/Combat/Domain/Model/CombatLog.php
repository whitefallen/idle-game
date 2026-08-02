<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

/**
 * The complete, self-describing record of a resolved encounter.
 *
 * The log is complete by contract: a client can render the entire fight,
 * including health at every point, without asking the server anything further.
 * That is what allows the replay UI to play back rather than re-simulate, which
 * in turn removes any need for bit-exact PRNG parity between PHP and
 * TypeScript. See ADR-0002.
 */
final readonly class CombatLog
{
    /**
     * Incremented when the serialised shape changes. Independent of the ruleset
     * version, so the format can evolve without implying a balance change and
     * old logs stay renderable.
     */
    public const int LOG_VERSION = 1;

    /**
     * @param list<Participant> $participants
     * @param list<LogEvent>    $events
     */
    public function __construct(
        public string $rulesetVersion,
        public int $seed,
        public array $participants,
        public array $events,
        public Outcome $outcome,
        public int $rounds,
    ) {
    }

    /**
     * The wire and storage representation.
     *
     * The seed is emitted as a decimal string because it is a full 64-bit value
     * and JavaScript's number type cannot hold one without loss. Every other
     * numeric field is small enough to be safe.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'logVersion' => self::LOG_VERSION,
            'rulesetVersion' => $this->rulesetVersion,
            'seed' => (string) $this->seed,
            'outcome' => $this->outcome->value,
            'rounds' => $this->rounds,
            'participants' => array_map(
                static fn (Participant $p): array => [
                    'id' => $p->id,
                    'definitionId' => $p->definitionId,
                    'name' => $p->name,
                    'team' => $p->team->value,
                    'level' => $p->level,
                    'maxHealth' => $p->maxHealth,
                    'maxFocus' => $p->maxFocus,
                ],
                $this->participants,
            ),
            'events' => array_map(static fn (LogEvent $e): array => $e->toArray(), $this->events),
        ];
    }
}
