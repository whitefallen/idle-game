<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;

/**
 * Rate limiting on the authentication endpoints.
 *
 * These run against the same limits production uses, so a passing test means
 * the shipped configuration works — not that a test-only configuration works.
 */
final class RateLimitTest extends ApiTestCase
{
    private const int REGISTRATION_LIMIT = 5;

    private const int LOGIN_ATTEMPT_LIMIT = 5;

    public function testRegistrationIsLimitedPerAddress(): void
    {
        for ($i = 1; $i <= self::REGISTRATION_LIMIT; ++$i) {
            $response = $this->registerAccount(sprintf('limited-%d@example.com', $i));

            self::assertSame(201, $response['status'], sprintf('registration %d should succeed', $i));
        }

        $blocked = $this->registerAccount('one-too-many@example.com');

        self::assertSame(429, $blocked['status']);
        self::assertSame('RATE_LIMITED', $blocked['body']['error']['code']);
        self::assertGreaterThan(0, $blocked['body']['error']['details']['retry_after']);

        // A 429 without Retry-After tells a client to back off but not for how
        // long, which in practice means it retries immediately.
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    /**
     * The limiter must be consumed before the password is hashed. Hashing is
     * deliberately expensive, so an attacker who could force it on every
     * request would have the cheapest denial of service available against this
     * endpoint.
     */
    public function testRegistrationLimitIsCheckedBeforeAnyWorkIsDone(): void
    {
        for ($i = 1; $i <= self::REGISTRATION_LIMIT; ++$i) {
            $this->registerAccount(sprintf('prework-%d@example.com', $i));
        }

        // A malformed payload would normally be rejected as a validation error.
        // Once limited, it must not get that far.
        $blocked = $this->postJson('/api/v1/auth/register', ['email' => 'nope', 'password' => 'x']);

        self::assertSame(429, $blocked['status']);
        self::assertSame('RATE_LIMITED', $blocked['body']['error']['code']);
    }

    public function testRepeatedFailedLoginsAreThrottled(): void
    {
        $this->registerAccount('throttled@example.com');

        for ($i = 1; $i <= self::LOGIN_ATTEMPT_LIMIT; ++$i) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'throttled@example.com',
                'password' => 'definitely-not-the-password',
            ]);

            self::assertSame(401, $response['status'], sprintf('attempt %d should be a credentials failure', $i));
            self::assertSame('INVALID_CREDENTIALS', $response['body']['error']['code']);
        }

        $throttled = $this->postJson('/api/v1/auth/login', [
            'email' => 'throttled@example.com',
            'password' => 'definitely-not-the-password',
        ]);

        // Reported distinctly from bad credentials: the client must know to
        // back off rather than re-prompt for a password.
        self::assertSame(429, $throttled['status']);
        self::assertSame('RATE_LIMITED', $throttled['body']['error']['code']);
    }

    /**
     * Throttling is itself a security event. Repeated throttling is the signal
     * worth alerting on, so it has to be in the trail.
     */
    public function testThrottlingIsAudited(): void
    {
        $this->registerAccount('audited-throttle@example.com');

        for ($i = 1; $i <= self::LOGIN_ATTEMPT_LIMIT + 1; ++$i) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'audited-throttle@example.com',
                'password' => 'definitely-not-the-password',
            ]);
        }

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        self::assertGreaterThan(
            0,
            (int) $connection->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'auth.rate_limited'"),
        );
    }
}
