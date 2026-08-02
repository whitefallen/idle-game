<?php

declare(strict_types=1);

namespace App\Platform\Content;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads, validates and indexes the raw content library.
 *
 * Deliberately returns plain arrays rather than typed definitions: each feature
 * maps the raw data into its own domain objects. That is what keeps this class
 * in Platform — it would be recognisable in any application that loads
 * schema-validated configuration, and it knows nothing about the game.
 *
 * Content is read from disk here and compiled into a cached registry at deploy
 * time; it is never read from disk during a request.
 * See docs/architecture.md section 6.
 */
final class ContentSource
{
    public function __construct(
        private readonly string $contentDirectory,
        private readonly JsonSchemaValidator $validator,
    ) {
    }

    /**
     * Loads a content directory, indexed by id.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws ContentValidationException
     */
    public function load(string $subdirectory, string $schemaName): array
    {
        [$entries, $issues] = $this->read($subdirectory, $schemaName);

        if ($issues !== []) {
            throw new ContentValidationException($issues);
        }

        return $entries;
    }

    /**
     * Validates without throwing, for the `content:validate` command.
     *
     * @return list<ContentIssue>
     */
    public function inspect(string $subdirectory, string $schemaName): array
    {
        return $this->read($subdirectory, $schemaName)[1];
    }

    /**
     * @return array{array<string, array<string, mixed>>, list<ContentIssue>}
     */
    private function read(string $subdirectory, string $schemaName): array
    {
        $directory = $this->contentDirectory . '/' . $subdirectory;
        $issues = [];
        $entries = [];

        /** @var array<string, string> $idSources Tracks which file first defined each id. */
        $idSources = [];

        $files = glob($directory . '/*.yaml') ?: [];
        sort($files, SORT_STRING);

        if ($files === []) {
            return [[], [new ContentIssue($subdirectory, '', 'No content files found in this directory.')]];
        }

        foreach ($files as $file) {
            $relative = $subdirectory . '/' . basename($file);

            try {
                $parsed = Yaml::parseFile($file);
            } catch (ParseException $e) {
                $issues[] = new ContentIssue($relative, '', 'Invalid YAML: ' . $e->getMessage());

                continue;
            }

            if (!is_array($parsed) || !array_is_list($parsed)) {
                $issues[] = new ContentIssue($relative, '', 'A content file must contain a list of definitions.');

                continue;
            }

            $fileIssues = $this->validator->validate($parsed, $schemaName, $relative);

            if ($fileIssues !== []) {
                $issues = [...$issues, ...$fileIssues];

                continue;
            }

            /** @var list<array<string, mixed>> $parsed */
            foreach ($parsed as $entry) {
                $id = (string) $entry['id'];

                if (isset($idSources[$id])) {
                    // Duplicate ids are checked here rather than by the schema,
                    // which cannot see across files. Ids are referenced by live
                    // item instances and stored logs, so collisions are serious.
                    $issues[] = new ContentIssue(
                        $relative,
                        $id,
                        sprintf('Duplicate id, already defined in %s.', $idSources[$id]),
                    );

                    continue;
                }

                $idSources[$id] = $relative;
                $entries[$id] = $entry;
            }
        }

        // Ids sort deterministically so that any iteration downstream is stable.
        ksort($entries, SORT_STRING);

        return [$entries, $issues];
    }
}
