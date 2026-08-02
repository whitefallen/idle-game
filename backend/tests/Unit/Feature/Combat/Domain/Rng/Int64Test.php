<?php

declare(strict_types=1);

namespace App\Tests\Unit\Feature\Combat\Domain\Rng;

use App\Feature\Combat\Domain\Rng\Int64;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * These tests are the foundation of the determinism guarantee. If wrapping
 * arithmetic is wrong, every roll, every replay and every stored combat log is
 * wrong with it — so the operations are pinned against explicit bit patterns
 * rather than against PHP's own arithmetic, which is what they exist to avoid.
 */
#[CoversClass(Int64::class)]
final class Int64Test extends TestCase
{
    /**
     * Results are compared as hex because a 64-bit pattern with the top bit set
     * reads as a negative decimal, which is unreadable in a failure message.
     */
    private static function hex(int $value): string
    {
        return sprintf('%016X', $value);
    }

    #[DataProvider('additionCases')]
    public function testAddWrapsModulo2Pow64(int $a, int $b, string $expectedHex): void
    {
        self::assertSame($expectedHex, self::hex(Int64::add($a, $b)));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function additionCases(): iterable
    {
        yield 'trivial' => [1, 1, '0000000000000002'];
        yield 'zero identity' => [0x0123456789ABCDEF, 0, '0123456789ABCDEF'];
        yield 'carry across the 32-bit boundary' => [0xFFFFFFFF, 1, '0000000100000000'];
        yield 'negative one plus one wraps to zero' => [-1, 1, '0000000000000000'];
        yield 'max int overflows to min int' => [PHP_INT_MAX, 1, '8000000000000000'];
        yield 'all ones plus all ones' => [-1, -1, 'FFFFFFFFFFFFFFFE'];
    }

    #[DataProvider('multiplicationCases')]
    public function testMulWrapsModulo2Pow64(int $a, int $b, string $expectedHex): void
    {
        self::assertSame($expectedHex, self::hex(Int64::mul($a, $b)));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function multiplicationCases(): iterable
    {
        yield 'trivial' => [2, 3, '0000000000000006'];
        yield 'zero annihilates' => [0x0123456789ABCDEF, 0, '0000000000000000'];
        yield 'one is identity' => [0x0123456789ABCDEF, 1, '0123456789ABCDEF'];

        // (2^64 - 1)^2 mod 2^64 == 1
        yield 'all ones squared' => [-1, -1, '0000000000000001'];

        // The 32x32 case that would overflow a naive lane split.
        yield '2^32 squared wraps to zero' => [0x100000000, 0x100000000, '0000000000000000'];

        yield 'high lane is discarded' => [0x100000000, 0x1000000000, '0000000000000000'];
    }

    /**
     * Differential test against bcmath, an independent arbitrary-precision
     * implementation. This is stronger evidence than hand-derived constants:
     * it exercises thousands of operand pairs, including every lane-boundary
     * and carry-propagation case, against an implementation that shares no code
     * with the one under test.
     *
     * The operand pairs are drawn from a fixed seed so a failure is reproducible.
     */
    public function testMatchesBcmathAcrossManyOperands(): void
    {
        mt_srand(20260802);

        for ($i = 0; $i < 2000; ++$i) {
            $a = self::randomInt64();
            $b = self::randomInt64();

            self::assertSame(
                self::bcWrap(bcadd(self::toUnsignedString($a), self::toUnsignedString($b))),
                self::toUnsignedString(Int64::add($a, $b)),
                sprintf('add(%s, %s)', self::hex($a), self::hex($b)),
            );

            self::assertSame(
                self::bcWrap(bcmul(self::toUnsignedString($a), self::toUnsignedString($b))),
                self::toUnsignedString(Int64::mul($a, $b)),
                sprintf('mul(%s, %s)', self::hex($a), self::hex($b)),
            );
        }
    }

    /** 2^64, the modulus that both operations wrap at. */
    private const string TWO_POW_64 = '18446744073709551616';

    private static function randomInt64(): int
    {
        // mt_rand covers 31 bits; three draws span the full 64.
        return (mt_rand() << 33) ^ (mt_rand() << 2) ^ mt_rand();
    }

    /**
     * Reinterpret a signed PHP integer as its unsigned 64-bit decimal value.
     *
     * @return numeric-string
     */
    private static function toUnsignedString(int $value): string
    {
        return $value >= 0
            ? (string) $value
            : bcadd((string) $value, self::TWO_POW_64);
    }

    /**
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    private static function bcWrap(string $value): string
    {
        return bcmod($value, self::TWO_POW_64);
    }

    /**
     * @param int<0, 63> $bits
     */
    #[DataProvider('shiftCases')]
    public function testUnsignedShiftRightZeroFills(int $value, int $bits, string $expectedHex): void
    {
        self::assertSame($expectedHex, self::hex(Int64::unsignedShiftRight($value, $bits)));
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function shiftCases(): iterable
    {
        yield 'zero bits is identity' => [-1, 0, 'FFFFFFFFFFFFFFFF'];
        yield 'one bit clears the sign' => [-1, 1, '7FFFFFFFFFFFFFFF'];
        yield 'thirty-one bits' => [-1, 31, '00000001FFFFFFFF'];
        yield 'thirty-three bits' => [-1, 33, '000000007FFFFFFF'];
        yield 'sixty-three bits leaves one bit' => [-1, 63, '0000000000000001'];
        yield 'positive value behaves like arithmetic shift' => [0x00FF000000000000, 48, '00000000000000FF'];
    }

    /**
     * The distinguishing property versus PHP's native `>>`, which sign-extends
     * and would corrupt the splitmix64 mixing steps.
     */
    public function testUnsignedShiftRightDiffersFromArithmeticShiftOnNegatives(): void
    {
        self::assertSame(-1, -1 >> 1, 'PHP native shift is expected to sign-extend.');
        self::assertSame(PHP_INT_MAX, Int64::unsignedShiftRight(-1, 1));
    }

    /**
     * Guards the reason these helpers exist: the native operators leave the
     * integer domain on overflow, and a float would break reproducibility.
     *
     * That Int64 stays in the integer domain is guaranteed by its return type,
     * so it is asserted here through the resulting bit pattern rather than
     * through a type check the analyser would call redundant.
     */
    public function testNativeOperatorsWouldOverflowToFloat(): void
    {
        self::assertIsFloat(PHP_INT_MAX + 1, 'Native addition is expected to overflow to float.');

        self::assertSame('8000000000000000', self::hex(Int64::add(PHP_INT_MAX, 1)));
        self::assertSame('0000000000000001', self::hex(Int64::mul(PHP_INT_MAX, PHP_INT_MAX)));
    }
}
