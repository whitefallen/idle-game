<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base class for HTTP contract tests.
 *
 * Each test runs against a real Postgres schema and truncates between cases.
 * Truncation rather than a transaction rollback, because several endpoints
 * open their own transactions (SELECT ... FOR UPDATE), and nesting those inside
 * a test transaction would hide exactly the locking behaviour worth testing.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected const string PASSWORD = 'a-long-enough-password';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->catchExceptions(true);

        $this->truncateTables();
    }

    private function truncateTables(): void
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        // CASCADE reaches everything joined to an account by a foreign key —
        // characters, encounters — but the Platform tables reference no
        // aggregate, so they must be named explicitly or rows leak between
        // tests and every outbox assertion sees the whole suite's history.
        $connection->executeStatement(
            'TRUNCATE TABLE account, outbox, idempotency_record RESTART IDENTITY CASCADE',
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function postJson(string $uri, array $payload): array
    {
        $this->client->request(
            'POST',
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return $this->decode();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function getJson(string $uri): array
    {
        $this->client->request('GET', $uri);

        return $this->decode();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function decode(): array
    {
        $response = $this->client->getResponse();
        $content = (string) $response->getContent();

        /** @var array<string, mixed> $body */
        $body = $content === '' ? [] : json_decode($content, true, 64, JSON_THROW_ON_ERROR);

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }

    /**
     * Renders a response body for use in assertion messages, so that a failure
     * shows what the server actually said rather than only the status code.
     *
     * @param array<string, mixed> $body
     */
    protected static function describe(array $body): string
    {
        return json_encode($body, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    protected function registerAccount(string $email): array
    {
        return $this->postJson('/api/v1/auth/register', [
            'email' => $email,
            'password' => self::PASSWORD,
        ]);
    }

    protected function registerAndLogin(string $email): void
    {
        $this->registerAccount($email);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ]);

        self::assertSame(200, $login['status'], 'Login failed: ' . self::describe($login['body']));
    }

    /**
     * @return array<string, mixed> The created character's detail payload.
     */
    protected function createCharacter(string $name): array
    {
        $response = $this->postJson('/api/v1/characters', ['name' => $name]);

        self::assertSame(201, $response['status'], self::describe($response['body']));

        /** @var array<string, mixed> $character */
        $character = $response['body']['data']['character'];

        return $character;
    }
}
