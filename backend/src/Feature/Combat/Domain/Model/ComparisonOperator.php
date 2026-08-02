<?php

declare(strict_types=1);

namespace App\Feature\Combat\Domain\Model;

enum ComparisonOperator: string
{
    case LessThan = 'lt';
    case LessOrEqual = 'lte';
    case GreaterThan = 'gt';
    case GreaterOrEqual = 'gte';
    case Equal = 'eq';

    public function compare(int $left, int $right): bool
    {
        return match ($this) {
            self::LessThan => $left < $right,
            self::LessOrEqual => $left <= $right,
            self::GreaterThan => $left > $right,
            self::GreaterOrEqual => $left >= $right,
            self::Equal => $left === $right,
        };
    }
}
