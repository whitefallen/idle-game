<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Feature\Combat\Domain\Model\BattlePlan;
use App\Feature\Combat\Domain\Model\Participant;
use App\Feature\Combat\Domain\Model\Team;

/**
 * A representative player character for content smoke tests.
 *
 * Stats are hand-set rather than derived through the Character feature: this
 * test is about whether authored content resolves, and coupling it to the
 * progression formulas would make every balance change break content tests for
 * no useful reason.
 */
final class ContentTestCharacter
{
    private function __construct()
    {
    }

    public static function participant(): Participant
    {
        return new Participant(
            id: 'aaaa-character',
            definitionId: 'character',
            name: 'Test Warden',
            team: Team::Players,
            level: 5,
            maxHealth: 210,
            initiative: 155,
            maxFocus: 40,
            focusPerTurn: 6,
            weaponBaseDamage: 26,
            flatDamageBonus: 2,
            scalingBp: 11500,
            critChanceBp: 800,
            critPowerBp: 16000,
            dodgeChanceBp: 500,
            accuracyBp: 100,
            armourRating: 180,
            resistanceRatings: ['nature' => 60],
            battlePlan: BattlePlan::singleAbility('ability.measured_strike'),
            abilityIds: ['ability.measured_strike'],
        );
    }
}
