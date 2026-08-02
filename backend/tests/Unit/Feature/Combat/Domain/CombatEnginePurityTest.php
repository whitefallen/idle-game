<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Combat\Domain;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the property that makes combat testable, replayable and auditable:
 * the engine is a pure function of its arguments.
 *
 * This is enforced by a failing build rather than by convention because purity
 * is exactly the kind of property that erodes invisibly. Nobody ever decides to
 * make the engine impure; someone injects a repository "just for this one
 * lookup", and six months later the fights are no longer reproducible.
 *
 * See ADR-0002 and docs/combat.md section 8.
 */
final class CombatEnginePurityTest extends TestCase
{
    private const string COMBAT_DOMAIN = __DIR__ . '/../../../../../src/Feature/Combat/Domain';

    /**
     * Namespaces the combat domain must never import. Persistence, the
     * container and the HTTP layer all imply ambient state the engine must not
     * observe.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_NAMESPACES = [
        'Doctrine',
        'Symfony',
        'Psr',
        'App\\Platform',
    ];

    /**
     * Functions that read ambient state. Every one of these makes a fight
     * unreproducible, which is precisely the failure this design exists to
     * prevent (determinism rule R4).
     *
     * @var list<string>
     */
    private const array FORBIDDEN_FUNCTIONS = [
        'time',
        'microtime',
        'hrtime',
        'date',
        'getdate',
        'mktime',
        'strtotime',
        'rand',
        'mt_rand',
        'random_int',
        'random_bytes',
        'array_rand',
        'shuffle',
        'str_shuffle',
        'uniqid',
        'getenv',
        'spl_object_hash',
        'spl_object_id',
        'setlocale',
        'file_get_contents',
        'file_put_contents',
        'fopen',
        'curl_init',
    ];

    /**
     * Classes that represent ambient state, so are forbidden even though they
     * are not function calls.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_CLASSES = [
        'DateTime',
        'DateTimeImmutable',
        'DateTimeZone',
    ];

    public function testCombatDomainImportsNothingFromTheFramework(): void
    {
        $violations = [];

        foreach ($this->domainFiles() as $file) {
            $source = (string) file_get_contents($file->getPathname());

            foreach ($this->importedNamespaces($source) as $import) {
                foreach (self::FORBIDDEN_NAMESPACES as $forbidden) {
                    if ($import === $forbidden || str_starts_with($import, $forbidden . '\\')) {
                        $violations[] = sprintf('%s imports %s', $file->getFilename(), $import);
                    }
                }
            }
        }

        self::assertSame([], $violations, $this->explain(
            'The combat domain must not depend on the framework or persistence.',
            $violations,
        ));
    }

    public function testCombatDomainDoesNotReadAmbientState(): void
    {
        $violations = [];

        foreach ($this->domainFiles() as $file) {
            $source = (string) file_get_contents($file->getPathname());

            foreach ($this->identifiersUsed($source) as $identifier) {
                if (in_array(strtolower($identifier), self::FORBIDDEN_FUNCTIONS, true)) {
                    $violations[] = sprintf('%s calls %s()', $file->getFilename(), $identifier);
                }

                if (in_array($identifier, self::FORBIDDEN_CLASSES, true)) {
                    $violations[] = sprintf('%s references %s', $file->getFilename(), $identifier);
                }
            }
        }

        self::assertSame([], $violations, $this->explain(
            'The combat engine must not observe the clock, the environment or unseeded randomness.',
            $violations,
        ));
    }

    /**
     * The engine must remain constructible with no arguments. A constructor
     * dependency is the most likely way purity would be lost in practice.
     */
    public function testEngineHasNoConstructorDependencies(): void
    {
        $constructor = (new \ReflectionClass(\App\Feature\Combat\Domain\Engine\CombatEngine::class))
            ->getConstructor();

        self::assertTrue(
            $constructor === null || $constructor->getNumberOfParameters() === 0,
            'CombatEngine must not take constructor dependencies.',
        );
    }

    /**
     * A sanity check on the guard itself: a test that can never fail is worse
     * than no test, so confirm the detector actually detects.
     */
    public function testTheGuardDetectsViolations(): void
    {
        $offending = <<<'PHP'
            <?php
            use Doctrine\ORM\EntityManagerInterface;
            final class Bad { public function f(): int { return time(); } }
            PHP;

        self::assertContains('Doctrine\ORM\EntityManagerInterface', $this->importedNamespaces($offending));
        self::assertContains('time', $this->identifiersUsed($offending));
    }

    /**
     * @return list<SplFileInfo>
     */
    private function domainFiles(): array
    {
        $directory = realpath(self::COMBAT_DOMAIN);
        self::assertIsString($directory, 'Combat domain directory not found.');

        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        self::assertNotEmpty($files, 'Expected to find combat domain sources to inspect.');

        return $files;
    }

    /**
     * Namespaces brought in by `use` statements.
     *
     * Uses the tokeniser rather than a regular expression so that text inside
     * comments and strings cannot produce a false positive — this file's own
     * docblocks mention Doctrine, for instance.
     *
     * @return list<string>
     */
    private function importedNamespaces(string $source): array
    {
        $tokens = token_get_all($source);
        $imports = [];
        $collecting = false;
        $current = '';

        foreach ($tokens as $token) {
            if (is_array($token) && $token[0] === T_USE) {
                $collecting = true;
                $current = '';

                continue;
            }

            if (!$collecting) {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $current .= $token[1];

                continue;
            }

            if ($token === ';' || $token === '{' || $token === ',') {
                if ($current !== '') {
                    $imports[] = ltrim($current, '\\');
                }

                $current = '';
                $collecting = $token === ',';
            }
        }

        return $imports;
    }

    /**
     * Function names and class references appearing in executable code.
     *
     * @return list<string>
     */
    private function identifiersUsed(string $source): array
    {
        $identifiers = [];

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $identifiers[] = ltrim($token[1], '\\');
            }
        }

        return $identifiers;
    }

    /**
     * @param list<string> $violations
     */
    private function explain(string $headline, array $violations): string
    {
        if ($violations === []) {
            return $headline;
        }

        return $headline . "\n  - " . implode("\n  - ", $violations);
    }
}
