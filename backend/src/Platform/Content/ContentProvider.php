<?php

declare(strict_types=1);

namespace App\Platform\Content;

/**
 * Implemented by each feature's content repository.
 *
 * The interface lives in Platform and the implementations live in feature
 * Infrastructure, which is what lets the `content:validate` command check the
 * whole library without Platform depending on any feature. Dependencies still
 * point inward.
 */
interface ContentProvider
{
    /** Human-readable label for command output, e.g. "abilities". */
    public function contentName(): string;

    /**
     * Validates this provider's content, including references it makes into
     * other providers' content. Returns every problem found rather than
     * stopping at the first.
     *
     * @return list<ContentIssue>
     */
    public function validateContent(): array;
}
