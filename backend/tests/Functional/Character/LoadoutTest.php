<?php

declare(strict_types=1);

namespace App\Tests\Functional\Character;

use App\Feature\Character\Domain\Service\ProgressionRules;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;

/**
 * The discipline loadout: what a character may slot, and what it may not.
 *
 * Owning a discipline is permanent and derived from level; slotting is the
 * limited resource. See docs/progression.md section 4.1.
 */
final class LoadoutTest extends ApiTestCase
{
    private const string FALLBACK = 'ability.measured_strike';
    private const string STARTER = 'ability.rupture';

    /** Granted by discipline.sweeping_arc at level 4. */
    private const string LEVEL_FOUR_ABILITY = 'ability.sweeping_arc';

    /**
     * @param list<string> $abilityIds
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function saveLoadout(string $characterId, array $abilityIds): array
    {
        $this->client->request(
            'PUT',
            '/api/v1/characters/' . $characterId . '/loadout',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ability_ids' => $abilityIds], JSON_THROW_ON_ERROR),
        );

        return $this->decode();
    }

    /**
     * Levels are reached by fighting, which is slow and irrelevant here. The
     * level is set directly so each case is about the loadout rules rather than
     * about the experience curve.
     */
    private function setLevel(string $characterId, int $level): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        $connection->executeStatement(
            'UPDATE game_character SET level = :level WHERE id = :id',
            ['level' => $level, 'id' => $characterId],
        );
    }

    public function testANewCharacterSeesTheWholeCatalogueWithLockedEntriesMarked(): void
    {
        $this->registerAndLogin('catalogue@example.com');
        $character = $this->createCharacter('Cataloguer');

        $disciplines = $character['disciplines'];

        self::assertNotEmpty($disciplines);

        $byId = [];
        foreach ($disciplines as $entry) {
            $byId[$entry['id']] = $entry;
        }

        self::assertTrue($byId['discipline.measured_strike']['unlocked']);
        self::assertTrue($byId['discipline.measured_strike']['slotted']);

        // Locked entries are present, with the level that unlocks them, so the
        // next few levels are legible rather than a surprise.
        self::assertFalse($byId['discipline.sweeping_arc']['unlocked']);
        self::assertFalse($byId['discipline.sweeping_arc']['slotted']);
        self::assertSame(4, $byId['discipline.sweeping_arc']['unlock_level']);
    }

    public function testSlottingAnUnlockedDisciplineSucceeds(): void
    {
        $this->registerAndLogin('slot@example.com');
        $character = $this->createCharacter('Slotter');
        $this->setLevel($character['id'], 6);

        // Keeps Rupture, which the starting plan opens with, and adds the
        // level 4 discipline into the third slot.
        $response = $this->saveLoadout(
            $character['id'],
            [self::FALLBACK, self::STARTER, self::LEVEL_FOUR_ABILITY],
        );

        self::assertSame(200, $response['status'], self::describe($response['body']));
        self::assertSame(
            [self::FALLBACK, self::STARTER, self::LEVEL_FOUR_ABILITY],
            $response['body']['data']['character']['ability_ids'],
        );

        $slotted = array_column(
            array_filter(
                $response['body']['data']['character']['disciplines'],
                static fn (array $d): bool => $d['slotted'] === true,
            ),
            'id',
        );

        sort($slotted);

        self::assertSame(
            ['discipline.measured_strike', 'discipline.rupture', 'discipline.sweeping_arc'],
            $slotted,
        );
    }

    /**
     * The server decides what is unlocked. A client asking for an ability its
     * level does not grant is refused, and told which ones.
     */
    public function testSlottingALockedDisciplineIsRefusedWithTheOffendingAbilities(): void
    {
        $this->registerAndLogin('locked@example.com');
        $character = $this->createCharacter('Locker');

        $response = $this->saveLoadout($character['id'], [self::FALLBACK, self::LEVEL_FOUR_ABILITY]);

        self::assertSame(422, $response['status']);
        self::assertSame('REQUIREMENT_NOT_MET', $response['body']['error']['code']);
        self::assertSame([self::LEVEL_FOUR_ABILITY], $response['body']['error']['details']['locked_abilities']);
        self::assertSame(1, $response['body']['error']['details']['character_level']);
    }

    /**
     * A monster ability has no discipline, which is exactly what makes it a
     * monster ability. It must be unreachable however it is requested.
     */
    public function testAMonsterAbilityCanNeverBeSlotted(): void
    {
        $this->registerAndLogin('monsterkit@example.com');
        $character = $this->createCharacter('Poacher');
        $this->setLevel($character['id'], ProgressionRules::MAX_LEVEL);

        $response = $this->saveLoadout($character['id'], [self::FALLBACK, 'ability.blight_lash']);

        self::assertSame(422, $response['status'], self::describe($response['body']));
        self::assertSame('REQUIREMENT_NOT_MET', $response['body']['error']['code']);
        self::assertSame(['ability.blight_lash'], $response['body']['error']['details']['locked_abilities']);
    }

    public function testTheSlotBudgetIsEnforced(): void
    {
        $this->registerAndLogin('overfill@example.com');
        $character = $this->createCharacter('Overfiller');
        $this->setLevel($character['id'], 10);

        // Level 10 grants three slots; ask for four unlocked abilities.
        $response = $this->saveLoadout($character['id'], [
            self::FALLBACK,
            self::STARTER,
            self::LEVEL_FOUR_ABILITY,
            'ability.emberdraught',
        ]);

        self::assertSame(422, $response['status']);
        self::assertSame('VALIDATION_FAILED', $response['body']['error']['code']);
        self::assertSame(3, $response['body']['error']['details']['slots']);
        self::assertSame(4, $response['body']['error']['details']['requested']);
    }

    public function testTheSameAbilityCannotFillTwoSlots(): void
    {
        $this->registerAndLogin('dupe@example.com');
        $character = $this->createCharacter('Duplicator');

        $response = $this->saveLoadout($character['id'], [self::FALLBACK, self::FALLBACK]);

        self::assertSame(422, $response['status']);
        self::assertSame('VALIDATION_FAILED', $response['body']['error']['code']);
    }

    /**
     * Unslotting an ability the battle plan still uses is refused rather than
     * silently repairing the plan. A plan is authored, sometimes carefully, and
     * quietly deleting a rule is worse than being told which rule is in the way.
     */
    public function testUnslottingAnAbilityTheBattlePlanUsesIsRefused(): void
    {
        $this->registerAndLogin('stranded@example.com');
        $character = $this->createCharacter('Strander');

        // The starting plan opens with Rupture, so dropping it strands rule 1.
        $response = $this->saveLoadout($character['id'], [self::FALLBACK]);

        self::assertSame(422, $response['status']);
        self::assertSame('VALIDATION_FAILED', $response['body']['error']['code']);
        self::assertStringContainsString(self::STARTER, $response['body']['error']['message']);
    }

    /**
     * Slotting is free and has no cooldown: build iteration is meant to be the
     * enjoyable part. Asserted so a future "loadout change costs gold" idea has
     * to be a deliberate decision rather than a quiet regression.
     */
    public function testSlottingIsFreeAndRepeatable(): void
    {
        $this->registerAndLogin('freeswap@example.com');
        $character = $this->createCharacter('Swapper');
        $this->setLevel($character['id'], 6);

        $goldBefore = $character['gold'];

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $this->saveLoadout($character['id'], [self::FALLBACK, self::STARTER])['status']);
            self::assertSame(
                200,
                $this->saveLoadout($character['id'], [self::FALLBACK, self::STARTER, self::LEVEL_FOUR_ABILITY])['status'],
            );
        }

        $current = $this->getJson('/api/v1/characters/' . $character['id'])['body']['data']['character'];

        self::assertSame($goldBefore, $current['gold'], 'Changing a loadout must not cost anything.');
    }

    public function testAnotherAccountsCharacterCannotBeReloadedOut(): void
    {
        $this->registerAndLogin('ownerloadout@example.com');
        $character = $this->createCharacter('Owned');

        $this->registerAndLogin('intruderloadout@example.com');

        $response = $this->saveLoadout($character['id'], [self::FALLBACK]);

        self::assertSame(404, $response['status'], 'Ownership failures must be indistinguishable from absence.');
    }
}
