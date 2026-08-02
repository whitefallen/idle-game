<?php

declare(strict_types=1);

namespace App\Platform\Content;

/**
 * One problem found in the content library.
 *
 * Issues are collected rather than thrown one at a time, so a designer who has
 * made five mistakes learns about all five from one run instead of rediscovering
 * them one build at a time.
 */
final readonly class ContentIssue
{
    public function __construct(
        public string $source,
        public string $path,
        public string $message,
    ) {
    }

    public function __toString(): string
    {
        return $this->path === ''
            ? sprintf('%s: %s', $this->source, $this->message)
            : sprintf('%s (%s): %s', $this->source, $this->path, $this->message);
    }
}
