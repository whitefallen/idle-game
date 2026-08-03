<?php

declare(strict_types=1);

namespace App\Tests\Functional\Character;

use App\Feature\Character\Application\CreateCharacterHandler;
use App\Feature\Character\Domain\Service\VigorRules;
use App\Tests\Functional\ApiTestCase;

final class CharacterFlowTest extends ApiTestCase
{
    public function testCreateCharacterReturnsAFullDetailPayload(): void
    {
        $this->registerAndLogin('creator@example.com');

        $character = $this->createCharacter('Aldric');

        self::assertSame('Aldric', $character['name']);
        self::assertSame(1, $character['level']);
        self::assertSame(10, $character['unspent_points']);
        self::assertSame(VigorRules::CAP, $character['vigor']['current']);

        // Every attribute starts at the base value.
        self::assertSame(
            ['STR' => 5, 'DEX' => 5, 'INT' => 5, 'CON' => 5, 'LUK' => 5],
            $character['attributes'],
        );

        // A new character can play without opening the battle plan editor, so
        // the default plan must be present and usable.
        self::assertCount(2, $character['battle_plan']);
        self::assertSame('ability.measured_strike', $character['battle_plan'][1]['abilityId']);
    }

    /**
     * Derived values are computed, never stored, so they must match the
     * documented formulas exactly. maxHealth = 50 + 12*CON + 8*level.
     */
    public function testDerivedStatsMatchTheProgressionFormulas(): void
    {
        $this->registerAndLogin('stats@example.com');

        $character = $this->createCharacter('Statwarden');
        $stats = $character['derived_stats'];

        self::assertSame(50 + 12 * 5 + 8 * 1, $stats['maxHealth']);
        self::assertSame(10 * 5 + 5 * 1, $stats['initiative']);
        self::assertSame(30 + 2 * 5, $stats['maxFocus']);
        self::assertSame(500 + 25 * 5, $stats['critChanceBp']);
        self::assertSame(200 + 12 * 5, $stats['dodgeChanceBp']);
        self::assertSame(10000 + 70 * 5, $stats['scalingBp']);
    }

    public function testDuplicateNameIsRejected(): void
    {
        $this->registerAndLogin('first@example.com');
        $this->createCharacter('Unique');

        $response = $this->postJson('/api/v1/characters', ['name' => 'Unique']);

        self::assertSame(409, $response['status']);
        self::assertSame('CHARACTER_NAME_TAKEN', $response['body']['error']['code']);
    }

    public function testNameIsUniqueAcrossAccounts(): void
    {
        $this->registerAndLogin('owner-a@example.com');
        $this->createCharacter('Contested');

        $this->registerAndLogin('owner-b@example.com');
        $response = $this->postJson('/api/v1/characters', ['name' => 'Contested']);

        self::assertSame(409, $response['status']);
    }

    public function testInvalidNameIsRejected(): void
    {
        $this->registerAndLogin('badname@example.com');

        foreach (['ab', '1Leading', 'has_underscore', str_repeat('a', 25)] as $name) {
            $response = $this->postJson('/api/v1/characters', ['name' => $name]);

            self::assertSame(422, $response['status'], sprintf('"%s" should be rejected.', $name));
            self::assertSame('VALIDATION_FAILED', $response['body']['error']['code']);
        }
    }

    public function testCharacterLimitIsEnforced(): void
    {
        $this->registerAndLogin('collector@example.com');

        for ($i = 1; $i <= CreateCharacterHandler::FREE_CHARACTER_SLOTS; ++$i) {
            $this->createCharacter('Warden' . $i);
        }

        $response = $this->postJson('/api/v1/characters', ['name' => 'OneTooMany']);

        self::assertSame(422, $response['status']);
        self::assertSame('CHARACTER_LIMIT_REACHED', $response['body']['error']['code']);
        self::assertSame(
            CreateCharacterHandler::FREE_CHARACTER_SLOTS,
            $response['body']['error']['details']['limit'],
        );
    }

    public function testListReturnsOnlyTheCallersCharacters(): void
    {
        $this->registerAndLogin('lister-a@example.com');
        $this->createCharacter('Mine');

        $this->registerAndLogin('lister-b@example.com');
        $this->createCharacter('Theirs');

        $response = $this->getJson('/api/v1/characters');

        self::assertSame(200, $response['status']);
        self::assertCount(1, $response['body']['data']['characters']);
        self::assertSame('Theirs', $response['body']['data']['characters'][0]['name']);
    }

    /**
     * Another account's character must be reported as absent rather than
     * forbidden: a 403 confirms the id exists and turns the endpoint into an
     * enumeration oracle. See docs/api.md section 5.
     */
    public function testAnotherAccountsCharacterIsReportedAsNotFound(): void
    {
        $this->registerAndLogin('victim@example.com');
        $victimId = $this->createCharacter('Victim')['id'];

        $this->registerAndLogin('snooper@example.com');
        $response = $this->getJson('/api/v1/characters/' . $victimId);

        self::assertSame(404, $response['status']);
        self::assertSame('NOT_FOUND', $response['body']['error']['code']);
    }

    public function testMalformedIdIsNotFoundRatherThanAServerError(): void
    {
        $this->registerAndLogin('malformed@example.com');

        $response = $this->getJson('/api/v1/characters/not-a-uuid');

        self::assertSame(404, $response['status']);
    }

    // -----------------------------------------------------------------
    // Attribute allocation
    // -----------------------------------------------------------------

    public function testAllocatingPointsUpdatesAttributesAndDerivedStats(): void
    {
        $this->registerAndLogin('allocator@example.com');
        $character = $this->createCharacter('Allocator');

        $response = $this->postJson(
            '/api/v1/characters/' . $character['id'] . '/attributes',
            ['allocation' => ['CON' => 6, 'STR' => 4]],
        );

        self::assertSame(200, $response['status'], self::describe($response['body']));

        $updated = $response['body']['data']['character'];

        self::assertSame(11, $updated['attributes']['CON']);
        self::assertSame(9, $updated['attributes']['STR']);
        self::assertSame(0, $updated['unspent_points']);

        // Derived values must follow immediately, since they are computed.
        self::assertSame(50 + 12 * 11 + 8, $updated['derived_stats']['maxHealth']);
        self::assertSame(10000 + 70 * 9, $updated['derived_stats']['scalingBp']);
    }

    public function testAllocatingMorePointsThanAvailableIsRejected(): void
    {
        $this->registerAndLogin('overspender@example.com');
        $character = $this->createCharacter('Overspender');

        $response = $this->postJson(
            '/api/v1/characters/' . $character['id'] . '/attributes',
            ['allocation' => ['CON' => 99]],
        );

        self::assertSame(422, $response['status']);
        self::assertSame('INSUFFICIENT_POINTS', $response['body']['error']['code']);
        self::assertSame(10, $response['body']['error']['details']['available']);
    }

    public function testAllocationIsRejectedForUnknownAttributes(): void
    {
        $this->registerAndLogin('unknownattr@example.com');
        $character = $this->createCharacter('Unknownattr');

        $response = $this->postJson(
            '/api/v1/characters/' . $character['id'] . '/attributes',
            ['allocation' => ['CHA' => 1]],
        );

        self::assertSame(422, $response['status']);
        self::assertSame('VALIDATION_FAILED', $response['body']['error']['code']);
    }

    public function testNegativePointsCannotBeUsedToDrainAttributes(): void
    {
        $this->registerAndLogin('negative@example.com');
        $character = $this->createCharacter('Negative');

        $response = $this->postJson(
            '/api/v1/characters/' . $character['id'] . '/attributes',
            ['allocation' => ['CON' => 20, 'STR' => -20]],
        );

        self::assertSame(422, $response['status']);
    }

    public function testAllocationRequiresOwnership(): void
    {
        $this->registerAndLogin('owner@example.com');
        $characterId = $this->createCharacter('Owned')['id'];

        $this->registerAndLogin('attacker@example.com');

        $response = $this->postJson(
            '/api/v1/characters/' . $characterId . '/attributes',
            ['allocation' => ['CON' => 1]],
        );

        self::assertSame(404, $response['status']);
    }

    public function testVigorIsReportedWithItsRateAndFullTime(): void
    {
        $this->registerAndLogin('vigor@example.com');
        $character = $this->createCharacter('Vigorous');

        $vigor = $character['vigor'];

        self::assertSame(VigorRules::CAP, $vigor['current']);
        self::assertSame(VigorRules::CAP, $vigor['max']);
        self::assertSame(VigorRules::SECONDS_PER_POINT, $vigor['seconds_per_point']);
        self::assertArrayHasKey('full_at', $vigor);
    }
}
