<?php

declare(strict_types=1);

namespace App\Feature\Inventory\Domain\Service;

use App\Feature\Inventory\Domain\Model\ItemDefinition;

/**
 * Whether a character may wear an item.
 *
 * Extracted because two paths now ask the question and they must never answer
 * it differently: equipping checks it to refuse, and respec checks it to strip
 * gear a reallocation has invalidated. Two copies of this rule would drift, and
 * the drift would show up as an item that can be worn but not re-equipped.
 *
 * Only *allocated* attributes count. Equipment bonuses are deliberately not
 * included: letting an item's own attribute grant satisfy another item's
 * requirement makes a set of items mutually load-bearing, so removing one can
 * cascade. See docs/progression.md section 3.
 */
final class ItemRequirements
{
    /**
     * @param array<string, int> $attributes Allocated attributes, keyed by Attribute value.
     */
    public static function met(int $level, array $attributes, ItemDefinition $definition): bool
    {
        return self::firstUnmet($level, $attributes, $definition) === null;
    }

    /**
     * The first requirement this character fails, or null if it meets them all.
     *
     * Returns the failure rather than a bare bool so callers can say *which*
     * requirement failed — an error message naming the attribute is the
     * difference between a player fixing their build and guessing at it.
     *
     * @param array<string, int> $attributes Allocated attributes, keyed by Attribute value.
     *
     * @return array{kind: string, attribute?: string, required: int, current: int}|null
     */
    public static function firstUnmet(int $level, array $attributes, ItemDefinition $definition): ?array
    {
        if ($level < $definition->requiredLevel) {
            return [
                'kind' => 'level',
                'required' => $definition->requiredLevel,
                'current' => $level,
            ];
        }

        // Sorted so that an item failing several requirements always names the
        // same one. An error message that changes between two identical
        // requests is a support burden for no benefit.
        $requirements = $definition->attributeRequirements;
        ksort($requirements, SORT_STRING);

        foreach ($requirements as $code => $required) {
            $current = $attributes[$code] ?? 0;

            if ($current < $required) {
                return [
                    'kind' => 'attribute',
                    'attribute' => (string) $code,
                    'required' => $required,
                    'current' => $current,
                ];
            }
        }

        return null;
    }
}
