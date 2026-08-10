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
     * An attribute respec: the gold it cost, the balance left, and any gear the
     * reallocation stripped. The unequipped list is audited because it is the
     * one part of a respec a player did not explicitly ask for, and "where did
     * my sword go" needs an answer that does not depend on the client having
     * rendered the response.
     */
    case CharacterRespecced = 'character.respecced';

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
     * An item's refinement level advancing, with the gold and material spent
     * and the resulting balances (docs/idle.md rule T6's reasoning applies
     * here too: this is the currency movement docs/economy.md section 5 asks
     * for, and the one EncounterResolved's docblock named in advance).
     */
    case ItemRefined = 'item.refined';

    /**
     * A Vendor purchase, with the gold spent and the resulting balance — the
     * "shop" currency movement EncounterResolved's docblock named in advance.
     */
    case ItemPurchased = 'item.purchased';

    /**
     * A Vendor sale, with the gold awarded and the resulting balance.
     */
    case ItemSold = 'item.sold';

    /**
     * A quest accepted: the character's combat state was snapshotted and its
     * timer started. See docs/adr/0008-quest-snapshot-resolution.md.
     */
    case QuestAccepted = 'quest.accepted';

    /**
     * A quest claimed: the snapshot resolved into a simulated fight and,
     * on a win, granted its fixed reward. Carries the outcome, seed and
     * rewards granted.
     */
    case QuestClaimed = 'quest.claimed';

    /**
     * A dungeon entered: its key was consumed. Recorded separately from
     * DungeonCompleted so a consumed key with no matching completion is
     * findable even if the run itself failed for an unrelated reason.
     */
    case DungeonEntered = 'dungeon.entered';

    /**
     * A dungeon run finished, cleared or not, with every stage's outcome and
     * the rewards granted.
     */
    case DungeonCompleted = 'dungeon.completed';

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
