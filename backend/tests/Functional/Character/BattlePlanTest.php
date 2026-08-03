<?php

declare(strict_types=1);

namespace App\Tests\Functional\Character;

use App\Tests\Functional\ApiTestCase;

final class BattlePlanTest extends ApiTestCase
{
    /**
     * @param list<array<string, mixed>> $rules
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function savePlan(string $characterId, array $rules): array
    {
        $this->client->request(
            'PUT',
            '/api/v1/characters/' . $characterId . '/battle-plan',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['rules' => $rules], JSON_THROW_ON_ERROR),
        );

        return $this->decode();
    }

    /**
     * @return array{condition: list<array<string, mixed>>, ability: string}
     */
    private static function always(string $abilityId): array
    {
        return ['condition' => [['subject' => 'always']], 'ability' => $abilityId];
    }

    public function testGrammarIsPublishedSoTheEditorNeedNotHardcodeIt(): void
    {
        $this->registerAndLogin('grammar@example.com');

        $response = $this->getJson('/api/v1/battle-plan/grammar');

        self::assertSame(200, $response['status']);

        $grammar = $response['body']['data']['grammar'];

        self::assertSame(8, $grammar['limits']['max_rules']);
        self::assertSame(3, $grammar['limits']['max_terms_per_condition']);
        self::assertContains('lt', $grammar['operators']);

        $subjects = [];
        foreach ($grammar['subjects'] as $subject) {
            $subjects[$subject['value']] = $subject;
        }

        // The editor renders inputs from these flags rather than from its own
        // copy of the rules.
        self::assertTrue($subjects['self.health_percent']['requires_operator']);
        self::assertTrue($subjects['self.health_percent']['is_percentage']);
        self::assertTrue($subjects['target.has_effect']['requires_effect']);
        self::assertFalse($subjects['always']['requires_operator']);

        // Only effects something can actually apply are offered.
        self::assertContains('effect.bleeding', $grammar['effects']);
    }

    public function testCharacterPayloadCarriesAbilityMetadata(): void
    {
        $this->registerAndLogin('abilities@example.com');
        $character = $this->createCharacter('Abilities');

        $byId = [];
        foreach ($character['abilities'] as $ability) {
            $byId[$ability['id']] = $ability;
        }

        // The editor needs cost and cooldown to say which abilities may serve
        // as the fallback, rather than letting a player find out by rejection.
        self::assertTrue($byId['ability.measured_strike']['can_be_fallback']);
        self::assertSame(0, $byId['ability.measured_strike']['focus_cost']);
        self::assertFalse($byId['ability.rupture']['can_be_fallback']);
        self::assertGreaterThan(0, $byId['ability.rupture']['focus_cost']);
    }

    public function testSavingAValidPlan(): void
    {
        $this->registerAndLogin('planner@example.com');
        $character = $this->createCharacter('Planner');

        $rules = [
            [
                'condition' => [['subject' => 'target.health_percent', 'operator' => 'lte', 'value' => 50]],
                'ability' => 'ability.rupture',
            ],
            self::always('ability.measured_strike'),
        ];

        $response = $this->savePlan($character['id'], $rules);

        self::assertSame(200, $response['status'], self::describe($response['body']));
        self::assertSame([], $response['body']['data']['warnings']);

        $saved = $response['body']['data']['character']['battle_plan'];

        self::assertCount(2, $saved);
        self::assertSame('ability.rupture', $saved[0]['abilityId']);
        self::assertSame('lte', $saved[0]['condition'][0]['operator']);

        // And it survives a round trip, so the plan the engine will use is the
        // plan the player wrote.
        $reloaded = $this->getJson('/api/v1/characters/' . $character['id']);

        self::assertSame($saved, $reloaded['body']['data']['character']['battle_plan']);
    }

    public function testTheSavedPlanIsTheOneCombatUses(): void
    {
        $this->registerAndLogin('inuse@example.com');
        $character = $this->createCharacter('Inuse');

        // A plan that only ever uses the basic strike.
        $this->savePlan($character['id'], [self::always('ability.measured_strike')]);

        $this->client->request(
            'POST',
            '/api/v1/encounters',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['character_id' => $character['id'], 'encounter_id' => 'encounter.stretch1.patrol'],
                JSON_THROW_ON_ERROR,
            ),
        );

        $resolved = $this->decode();
        self::assertSame(201, $resolved['status'], self::describe($resolved['body']));

        $used = [];
        foreach ($resolved['body']['data']['encounter']['log']['events'] as $event) {
            if ($event['t'] === 'plan.matched' && $event['actor'] === $character['id']) {
                $used[] = $event['ability'];
            }
        }

        self::assertNotEmpty($used);
        self::assertSame(['ability.measured_strike'], array_values(array_unique($used)));
    }

    public function testValidationErrorsPointAtTheOffendingRule(): void
    {
        $this->registerAndLogin('invalid@example.com');
        $character = $this->createCharacter('Invalidplan');

        $response = $this->savePlan($character['id'], [
            ['condition' => [['subject' => 'self.health_percent', 'operator' => 'lt', 'value' => 900]], 'ability' => 'ability.rupture'],
            self::always('ability.measured_strike'),
        ]);

        self::assertSame(422, $response['status']);
        self::assertSame('VALIDATION_FAILED', $response['body']['error']['code']);

        $issues = $response['body']['error']['details']['issues'];

        self::assertCount(1, $issues);
        self::assertSame('TERM_PERCENT_OUT_OF_RANGE', $issues[0]['code']);
        self::assertSame(0, $issues[0]['rule']);
        self::assertSame(0, $issues[0]['term']);
    }

    /**
     * Without this check a player could grant themselves any ability in the
     * content library simply by naming it in a plan.
     */
    public function testCannotUseAnAbilityTheCharacterHasNotLearned(): void
    {
        $this->registerAndLogin('cheater@example.com');
        $character = $this->createCharacter('Cheater');

        $response = $this->savePlan($character['id'], [
            ['condition' => [['subject' => 'round', 'operator' => 'gte', 'value' => 1]], 'ability' => 'ability.sweeping_arc'],
            self::always('ability.measured_strike'),
        ]);

        self::assertSame(422, $response['status']);
        self::assertSame('RULE_ABILITY_NOT_LEARNED', $response['body']['error']['details']['issues'][0]['code']);

        // And the stored plan is untouched.
        $current = $this->getJson('/api/v1/characters/' . $character['id']);
        $abilities = array_column($current['body']['data']['character']['battle_plan'], 'abilityId');

        self::assertNotContains('ability.sweeping_arc', $abilities);
    }

    public function testRejectsAPlanWhoseFallbackCouldBeUnavailable(): void
    {
        $this->registerAndLogin('fallback@example.com');
        $character = $this->createCharacter('Fallback');

        $response = $this->savePlan($character['id'], [self::always('ability.rupture')]);

        self::assertSame(422, $response['status']);
        self::assertSame(
            'PLAN_FINAL_RULE_NOT_ALWAYS_AVAILABLE',
            $response['body']['error']['details']['issues'][0]['code'],
        );
    }

    /**
     * A mistake worth flagging, not worth refusing. The plan saves and the
     * warning travels with it.
     */
    public function testUnreachableRulesSaveWithAWarning(): void
    {
        $this->registerAndLogin('unreachable@example.com');
        $character = $this->createCharacter('Unreachable');

        $response = $this->savePlan($character['id'], [
            self::always('ability.measured_strike'),
            self::always('ability.measured_strike'),
        ]);

        self::assertSame(200, $response['status'], self::describe($response['body']));

        $warnings = $response['body']['data']['warnings'];

        self::assertCount(1, $warnings);
        self::assertSame('RULE_UNREACHABLE', $warnings[0]['code']);
        self::assertSame(1, $warnings[0]['rule']);
    }

    public function testCannotEditAnotherAccountsPlan(): void
    {
        $this->registerAndLogin('owner3@example.com');
        $characterId = $this->createCharacter('Ownerthree')['id'];

        $this->registerAndLogin('intruder@example.com');

        self::assertSame(404, $this->savePlan($characterId, [self::always('ability.measured_strike')])['status']);
    }

    public function testRejectsAMalformedBody(): void
    {
        $this->registerAndLogin('malformedplan@example.com');
        $character = $this->createCharacter('Malformedplan');

        $this->client->request(
            'PUT',
            '/api/v1/characters/' . $character['id'] . '/battle-plan',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['rules' => 'not-a-list'], JSON_THROW_ON_ERROR),
        );

        self::assertSame(422, $this->decode()['status']);
    }
}
