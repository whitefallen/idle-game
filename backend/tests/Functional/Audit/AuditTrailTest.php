<?php

declare(strict_types=1);

namespace App\Tests\Functional\Audit;

use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;

/**
 * The audit trail.
 *
 * docs/economy.md section 5 requires every currency mutation to be recorded
 * with source, amount and resulting balance, and notes that this cannot be
 * added retroactively. These tests are what keep that true as handlers change.
 */
final class AuditTrailTest extends ApiTestCase
{
    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(string $action): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT action, account_id, character_id, context, ip_hash FROM audit_log WHERE action = ? ORDER BY occurred_at',
            [$action],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function contextOf(array $row): array
    {
        return json_decode((string) $row['context'], true, 32, JSON_THROW_ON_ERROR);
    }

    public function testRegistrationIsAudited(): void
    {
        $this->registerAccount('audited@example.com');

        $rows = $this->entries('account.registered');

        self::assertCount(1, $rows);
        self::assertSame('audited@example.com', $this->contextOf($rows[0])['email']);
        self::assertNotNull($rows[0]['account_id']);
    }

    /**
     * A failed login has no transaction to join and the request that triggered
     * it fails, so the record must be written immediately or it is lost — which
     * is precisely the record an intrusion investigation needs.
     */
    public function testFailedAuthenticationIsRecordedEvenThoughTheRequestFails(): void
    {
        $this->registerAccount('failing@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'failing@example.com',
            'password' => 'definitely-not-the-password',
        ]);

        self::assertSame(401, $response['status']);
        self::assertCount(1, $this->entries('auth.failed'));
    }

    /**
     * Recording a failure must not require confirming whether the address is
     * registered — doing so would turn the audit trail into an oracle.
     */
    public function testFailedAuthenticationForAnUnknownAddressIsAuditedWithoutIdentifyingIt(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody-here@example.com',
            'password' => 'whatever',
        ]);

        $rows = $this->entries('auth.failed');

        self::assertCount(1, $rows);
        self::assertNull($rows[0]['account_id']);
    }

    public function testClientAddressIsHashedNeverStoredRaw(): void
    {
        $this->registerAccount('hashed@example.com');

        $hash = $this->entries('account.registered')[0]['ip_hash'];

        self::assertIsString($hash);
        self::assertSame(64, strlen($hash), 'A sha256 digest is 64 hex characters.');
        self::assertStringNotContainsString('127.0.0.1', $hash);
    }

    /**
     * The record economy investigations actually depend on: every mutation the
     * encounter caused, each with its amount and the balance it produced.
     */
    public function testEncounterRecordsEveryMutationWithResultingBalances(): void
    {
        $this->registerAndLogin('economy@example.com');
        $character = $this->createCharacter('Economist');

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

        $rows = $this->entries('encounter.resolved');
        self::assertCount(1, $rows);

        $context = $this->contextOf($rows[0]);
        $rewards = $resolved['body']['data']['encounter']['rewards'];
        $character = $resolved['body']['data']['character'];

        self::assertSame($character['id'], $rows[0]['character_id']);
        self::assertSame('victory', $context['outcome']);

        // Amounts and balances must agree with what the player was told.
        self::assertSame($rewards['gold'], $context['gold']['granted']);
        self::assertSame($character['gold'], $context['gold']['balance']);
        self::assertSame($rewards['experience'], $context['experience']['granted']);
        self::assertSame($character['experience'], $context['experience']['balance']);
        self::assertSame(10, $context['vigor']['spent']);
        self::assertSame($character['vigor']['current'], $context['vigor']['balance']);

        // The seed ties the record to a reproducible fight, so an investigation
        // can replay the encounter that produced the currency.
        self::assertSame($resolved['body']['data']['encounter']['seed'], $context['seed']);
    }

    /**
     * A record that survives a rolled-back action would be worse than none: it
     * would assert something happened that did not.
     */
    public function testRejectedActionsLeaveNoAuditRecord(): void
    {
        $this->registerAndLogin('rejected@example.com');
        $character = $this->createCharacter('Rejected');

        $this->client->request(
            'POST',
            '/api/v1/encounters',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['character_id' => $character['id'], 'encounter_id' => 'encounter.stretch1.stalker'],
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertSame(422, $this->decode()['status']);
        self::assertCount(0, $this->entries('encounter.resolved'));
    }

    public function testAttributeAllocationIsAudited(): void
    {
        $this->registerAndLogin('allocated@example.com');
        $character = $this->createCharacter('Allocated');

        $this->postJson('/api/v1/characters/' . $character['id'] . '/attributes', [
            'allocation' => ['CON' => 4],
        ]);

        $rows = $this->entries('character.attributes_allocated');

        self::assertCount(1, $rows);

        $context = $this->contextOf($rows[0]);

        self::assertSame(4, $context['allocation']['CON']);
        self::assertSame(9, $context['attributes']['CON']);
        self::assertSame(6, $context['unspentPoints']);
    }

    /**
     * Retention deletes against occurred_at, so it must be populated and
     * indexed. A record with no usable timestamp would never be pruned and
     * never be found by a time-bounded query.
     */
    public function testEntriesCarryAnOccurrenceTimeThatRetentionCanUse(): void
    {
        $this->registerAccount('timestamped@example.com');

        $occurredAt = $this->connection()->fetchOne(
            "SELECT occurred_at FROM audit_log WHERE action = 'account.registered'",
        );

        self::assertNotFalse($occurredAt);
        self::assertNotNull($occurredAt);

        $indexes = $this->connection()->fetchFirstColumn(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'audit_log'",
        );

        self::assertContains('idx_audit_log_occurred_at', $indexes);
    }
}
