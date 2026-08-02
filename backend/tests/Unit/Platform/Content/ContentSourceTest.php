<?php

declare(strict_types=1);

namespace App\Tests\Unit\Platform\Content;

use App\Platform\Content\ContentSource;
use App\Platform\Content\ContentValidationException;
use App\Platform\Content\JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the content pipeline actually rejects bad content.
 *
 * A validator that only ever passes is worse than no validator, because it
 * creates confidence without providing any. Each case here is a mistake a
 * content author can realistically make.
 */
#[CoversClass(ContentSource::class)]
#[CoversClass(JsonSchemaValidator::class)]
final class ContentSourceTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../Fixtures/Content';

    private function sourceFor(string $case): ContentSource
    {
        return new ContentSource(
            self::FIXTURES . '/' . $case,
            new JsonSchemaValidator(self::FIXTURES . '/schema'),
        );
    }

    public function testLoadsValidContentIndexedById(): void
    {
        $entries = $this->sourceFor('valid')->load('widgets', 'widget');

        self::assertSame(['widget.alpha', 'widget.beta'], array_keys($entries));
        self::assertSame(3, $entries['widget.alpha']['size']);
        self::assertSame(7, $entries['widget.beta']['size']);
    }

    public function testValidContentReportsNoIssues(): void
    {
        self::assertSame([], $this->sourceFor('valid')->inspect('widgets', 'widget'));
    }

    public function testDetectsSchemaViolations(): void
    {
        $issues = $this->sourceFor('invalid-schema')->inspect('widgets', 'widget');

        self::assertNotEmpty($issues, 'A value above the schema maximum must be reported.');

        $rendered = implode("\n", array_map(strval(...), $issues));

        self::assertStringContainsString('widgets/core.yaml', $rendered);
    }

    public function testDetectsDuplicateIdsAcrossFiles(): void
    {
        $issues = $this->sourceFor('duplicate-id')->inspect('widgets', 'widget');

        self::assertCount(1, $issues);
        self::assertStringContainsString('Duplicate id', (string) $issues[0]);
        self::assertStringContainsString('a-first.yaml', (string) $issues[0]);
    }

    public function testDetectsMalformedYaml(): void
    {
        $issues = $this->sourceFor('malformed-yaml')->inspect('widgets', 'widget');

        self::assertNotEmpty($issues);
        self::assertStringContainsString('Invalid YAML', (string) $issues[0]);
    }

    public function testDetectsAFileThatIsNotAListOfDefinitions(): void
    {
        $issues = $this->sourceFor('not-a-list')->inspect('widgets', 'widget');

        self::assertCount(1, $issues);
        self::assertStringContainsString('must contain a list', (string) $issues[0]);
    }

    public function testDetectsAnEmptyDirectory(): void
    {
        $issues = $this->sourceFor('valid')->inspect('nonexistent', 'widget');

        self::assertCount(1, $issues);
        self::assertStringContainsString('No content files found', (string) $issues[0]);
    }

    public function testLoadThrowsRatherThanReturningInvalidContent(): void
    {
        $this->expectException(ContentValidationException::class);

        $this->sourceFor('invalid-schema')->load('widgets', 'widget');
    }

    /**
     * Issues carry every problem found, not just the first, so an author is not
     * forced to rediscover their mistakes one build at a time.
     */
    public function testReportsAllIssuesAtOnce(): void
    {
        try {
            $this->sourceFor('invalid-schema')->load('widgets', 'widget');
            self::fail('Expected validation to fail.');
        } catch (ContentValidationException $e) {
            self::assertGreaterThanOrEqual(2, count($e->issues));
            self::assertStringContainsString('issue(s)', $e->getMessage());
        }
    }

    /**
     * Downstream iteration must be stable, so ids come back sorted regardless
     * of which file defined them or what order the filesystem returned.
     */
    public function testEntriesAreOrderedById(): void
    {
        $keys = array_keys($this->sourceFor('valid')->load('widgets', 'widget'));
        $sorted = $keys;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $keys);
    }
}
