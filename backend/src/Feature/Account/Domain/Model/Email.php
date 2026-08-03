<?php

declare(strict_types=1);

namespace App\Feature\Account\Domain\Model;

use InvalidArgumentException;

/**
 * A normalised email address.
 *
 * Normalisation happens here, in one place, so that the unique index on the
 * column is a real guarantee rather than a hope: two registrations differing
 * only in case both persist the same string and the second is rejected by the
 * database, not merely by an application check that a future code path might
 * skip.
 *
 * Postgres CITEXT was the alternative and would enforce this in the column
 * itself. A value object was chosen instead because it avoids depending on a
 * database extension, and because normalisation is then visible in the type
 * system rather than hidden in DDL.
 */
final readonly class Email
{
    public const int MAX_LENGTH = 180;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $raw): self
    {
        $normalised = strtolower(trim($raw));

        if ($normalised === '') {
            throw new InvalidArgumentException('An email address is required.');
        }

        if (strlen($normalised) > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('An email address may be at most %d characters.', self::MAX_LENGTH),
            );
        }

        if (filter_var($normalised, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('That is not a valid email address.');
        }

        return new self($normalised);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
