<?php

declare(strict_types=1);

namespace App\Platform\Content;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;

/**
 * Validates parsed content against its JSON Schema.
 *
 * Schemas are the contract between content authors and the game. They catch the
 * mistakes that are cheap to make in YAML and expensive to debug at runtime:
 * a misspelled enum, a missing field, a negative cooldown.
 */
final class JsonSchemaValidator
{
    private readonly Validator $validator;

    public function __construct(private readonly string $schemaDirectory)
    {
        $this->validator = new Validator();
        $this->validator->setMaxErrors(50);
    }

    /**
     * @param mixed $data Parsed YAML, as associative arrays.
     *
     * @return list<ContentIssue>
     */
    public function validate(mixed $data, string $schemaName, string $source): array
    {
        $schemaPath = sprintf('%s/%s.schema.json', $this->schemaDirectory, $schemaName);

        if (!is_file($schemaPath)) {
            throw new RuntimeException(sprintf('Schema "%s" not found at %s.', $schemaName, $schemaPath));
        }

        $schema = json_decode((string) file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR);

        // Opis operates on the JSON data model, where objects are stdClass.
        // Re-encoding is the standard way to convert PHP's associative arrays.
        $subject = json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $result = $this->validator->validate($subject, $schema);

        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();

        if ($error === null) {
            return [];
        }

        $issues = [];

        // formatKeyed groups messages by their JSON pointer into the data, so
        // an author is told which entry and which field is wrong, not merely
        // that the file is invalid.
        foreach ((new ErrorFormatter())->formatKeyed($error) as $pointer => $messages) {
            foreach ((array) $messages as $message) {
                $issues[] = new ContentIssue($source, (string) $pointer, (string) $message);
            }
        }

        return $issues;
    }
}
