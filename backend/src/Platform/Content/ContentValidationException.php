<?php

declare(strict_types=1);

namespace App\Platform\Content;

use RuntimeException;

/**
 * Thrown when content fails validation at load time.
 *
 * In practice this should never surface at runtime: the same validation runs in
 * CI, so malformed content fails the build long before it reaches a server. It
 * exists as the backstop for the case where it somehow does.
 */
final class ContentValidationException extends RuntimeException
{
    /**
     * @param list<ContentIssue> $issues
     */
    public function __construct(public readonly array $issues)
    {
        parent::__construct(sprintf(
            "Content validation failed with %d issue(s):\n  - %s",
            count($issues),
            implode("\n  - ", array_map(strval(...), $issues)),
        ));
    }
}
