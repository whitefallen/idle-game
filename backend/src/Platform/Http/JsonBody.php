<?php

declare(strict_types=1);

namespace App\Platform\Http;

use JsonException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Typed access to a JSON request body.
 *
 * Exists so that controllers never index into a raw array and never guess at a
 * type. Every accessor either returns the requested type or throws a structured
 * validation error, which keeps the "controllers coordinate, never calculate"
 * rule honest: parsing is not the controller's job either.
 */
final readonly class JsonBody
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    public static function from(Request $request): self
    {
        $raw = $request->getContent();

        if (trim($raw) === '') {
            return new self([]);
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ApiException::of(ErrorCode::MalformedRequest, 'The request body is not valid JSON.', [
                'reason' => $e->getMessage(),
            ]);
        }

        if (!is_array($decoded)) {
            throw ApiException::of(ErrorCode::MalformedRequest, 'The request body must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    public function requireString(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw ApiException::of(
                ErrorCode::ValidationFailed,
                sprintf('"%s" is required.', $key),
                ['field' => $key],
            );
        }

        return $value;
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function requireInt(string $key): int
    {
        $value = $this->data[$key] ?? null;

        if (!is_int($value)) {
            throw ApiException::of(
                ErrorCode::ValidationFailed,
                sprintf('"%s" is required and must be an integer.', $key),
                ['field' => $key],
            );
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function requireObject(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!is_array($value) || array_is_list($value)) {
            throw ApiException::of(
                ErrorCode::ValidationFailed,
                sprintf('"%s" must be an object.', $key),
                ['field' => $key],
            );
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    public function requireList(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!is_array($value) || !array_is_list($value)) {
            throw ApiException::of(
                ErrorCode::ValidationFailed,
                sprintf('"%s" must be an array.', $key),
                ['field' => $key],
            );
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}
