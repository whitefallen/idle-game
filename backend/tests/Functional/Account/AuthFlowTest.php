<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Tests\Functional\ApiTestCase;

/**
 * The registration and login contract.
 *
 * Error responses are asserted as carefully as success responses: clients
 * branch on the code, so a changed code is a breaking change.
 */
final class AuthFlowTest extends ApiTestCase
{
    public function testRegisterCreatesAnAccount(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'Newcomer@Example.COM',
            'password' => 'a-long-enough-password',
        ]);

        self::assertSame(201, $response['status']);
        self::assertSame('newcomer@example.com', $response['body']['data']['account']['email']);
        self::assertArrayHasKey('server_time', $response['body']['meta']);
    }

    public function testRegistrationIsCaseInsensitive(): void
    {
        $this->registerAccount('duplicate@example.com');

        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'DUPLICATE@example.com',
            'password' => 'a-long-enough-password',
        ]);

        self::assertSame(409, $response['status']);
        self::assertSame('EMAIL_ALREADY_REGISTERED', $response['body']['error']['code']);
    }

    public function testShortPasswordIsRejected(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'shortpass@example.com',
            'password' => 'tiny',
        ]);

        self::assertSame(422, $response['status']);
        self::assertSame('VALIDATION_FAILED', $response['body']['error']['code']);
        self::assertSame('password', $response['body']['error']['details']['field']);
    }

    public function testMalformedEmailIsRejected(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'not-an-email',
            'password' => 'a-long-enough-password',
        ]);

        self::assertSame(422, $response['status']);
        self::assertSame('email', $response['body']['error']['details']['field']);
    }

    public function testLoginSucceedsWithCorrectCredentials(): void
    {
        $this->registerAccount('login-ok@example.com');

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'login-ok@example.com',
            'password' => self::PASSWORD,
        ]);

        self::assertSame(200, $response['status'], self::describe($response['body']));
        self::assertSame('login-ok@example.com', $response['body']['data']['account']['email']);
    }

    /**
     * The response must be identical for a wrong password and an unknown
     * address, so it cannot be used to enumerate registered accounts.
     */
    public function testLoginFailuresAreIndistinguishable(): void
    {
        $this->registerAccount('login-bad@example.com');

        $wrongPassword = $this->postJson('/api/v1/auth/login', [
            'email' => 'login-bad@example.com',
            'password' => 'definitely-not-the-password',
        ]);

        $unknownAccount = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody-here@example.com',
            'password' => 'definitely-not-the-password',
        ]);

        self::assertSame(401, $wrongPassword['status']);
        self::assertSame('INVALID_CREDENTIALS', $wrongPassword['body']['error']['code']);
        self::assertSame($wrongPassword['body'], $unknownAccount['body']);
        self::assertSame($wrongPassword['status'], $unknownAccount['status']);
    }

    /**
     * An unauthenticated caller must be told to authenticate (401), not that it
     * is forbidden (403) — the latter tells a client to give up rather than to
     * log in. See docs/api.md section 3.
     */
    public function testProtectedEndpointRequiresAuthentication(): void
    {
        $response = $this->getJson('/api/v1/characters');

        self::assertSame(401, $response['status']);
        self::assertSame('AUTHENTICATION_REQUIRED', $response['body']['error']['code']);
    }

    public function testCurrentAccountIsReturnedWhenAuthenticated(): void
    {
        $this->registerAndLogin('me@example.com');

        $response = $this->getJson('/api/v1/auth/me');

        self::assertSame(200, $response['status']);
        self::assertSame('me@example.com', $response['body']['data']['account']['email']);
    }

    public function testMalformedJsonIsRejectedCleanly(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/auth/register',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: '{not valid json',
        );

        $response = $this->decode();

        self::assertSame(400, $response['status']);
        self::assertSame('MALFORMED_REQUEST', $response['body']['error']['code']);
    }
}
