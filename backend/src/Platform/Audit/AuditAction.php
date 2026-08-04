<?php

declare(strict_types=1);

namespace App\Platform\Audit;

/**
 * The audited action vocabulary.
 *
 * An enum rather than free strings so that a typo cannot create a second,
 * silently separate category — an audit trail that is only mostly consistent is
 * not usable for the aggregate queries it exists to serve.
 *
 * Values are persisted, so they are never renamed. Retiring one means leaving
 * the case in place with a comment.
 */
enum AuditAction: string
{
    // Account lifecycle and authentication.
    case AccountRegistered = 'account.registered';
    case AuthenticationFailed = 'auth.failed';
    case AuthenticationSucceeded = 'auth.succeeded';
    case RateLimitExceeded = 'auth.rate_limited';

    // Character lifecycle.
    case CharacterCreated = 'character.created';
    case AttributesAllocated = 'character.attributes_allocated';

    /**
     * Carries every currency, experience and Vigor mutation an encounter
     * caused, with amounts and resulting balances, which is what
     * docs/economy.md section 5 requires.
     *
     * Cases for individual currency movements will be added when something
     * moves currency outside an encounter — the shop and refinement will. They
     * are deliberately absent until then rather than declared in advance.
     */
    case EncounterResolved = 'encounter.resolved';

    /**
     * A Holding claim, with the amount, the elapsed time it covered and the
     * resulting balances (docs/idle.md rule T6).
     *
     * Idle games are attacked through time, and time exploits are found in
     * aggregate data — a claim rate that outruns the clock, an elapsed figure
     * that exceeds the cap. That data has to exist before the exploit does,
     * which is why this is audited from the first claim rather than added after
     * the first incident.
     */
    case HoldingClaimed = 'holding.claimed';

    /**
     * Reassigning a production line. Not a resource movement, but it discards
     * pending accrual, so it is the natural first suspect in any "my materials
     * vanished" report.
     */
    case HoldingSlotAssigned = 'holding.slot_assigned';

    /**
     * Whether this action is a security event rather than ordinary gameplay.
     * Security events are retained and alerted on differently.
     */
    public function isSecurityEvent(): bool
    {
        return match ($this) {
            self::AuthenticationFailed,
            self::AuthenticationSucceeded,
            self::RateLimitExceeded,
            self::AccountRegistered => true,
            default => false,
        };
    }
}
