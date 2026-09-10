<?php

declare(strict_types=1);

namespace CarnetEquidad\Tests\Unit;

use CarnetEquidad\Validation\CedulaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CedulaValidatorTest extends TestCase
{
    private CedulaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CedulaValidator();
    }

    public function testCleanStripsDotsSpacesAndDashes(): void
    {
        self::assertSame('1094290592', $this->validator->clean('1.094.290-592'));
        self::assertSame('12345678', $this->validator->clean("  12 345 678 \n"));
        self::assertSame('1234567', $this->validator->clean('1_234_567'));
    }

    public function testCleanDropsAnyNonDigitCharacter(): void
    {
        self::assertSame('1234', $this->validator->clean('12ab34'));
        self::assertSame('', $this->validator->clean('abc'));
    }

    #[DataProvider('validCedulas')]
    public function testAcceptsSixToElevenDigits(string $input): void
    {
        self::assertTrue($this->validator->isValid($input));
    }

    /** @return iterable<string, array{string}> */
    public static function validCedulas(): iterable
    {
        yield 'six digits' => ['123456'];
        yield 'eleven digits' => ['12345678901'];
        yield 'ten digits with dots' => ['1.094.290.592'];
        yield 'seven digits with dashes' => ['1-234-567'];
    }

    #[DataProvider('invalidCedulas')]
    public function testRejectsAnythingOutsideRangeOrNonNumeric(string $input): void
    {
        self::assertFalse($this->validator->isValid($input));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCedulas(): iterable
    {
        yield 'five digits' => ['12345'];
        yield 'twelve digits' => ['123456789012'];
        yield 'empty' => [''];
        yield 'only separators' => ['..--  '];
        yield 'letters' => ['abcdef'];
        yield 'mixed too short after cleaning' => ['12ab'];
    }

    public function testNormalizeReturnsCleanedValueWhenValid(): void
    {
        self::assertSame('1094290592', $this->validator->normalize('1.094.290-592'));
    }

    public function testNormalizeReturnsNullWhenInvalid(): void
    {
        self::assertNull($this->validator->normalize('123'));
        self::assertNull($this->validator->normalize('not-a-number'));
    }

    public function testCustomBoundsViaConstructor(): void
    {
        $validator = new CedulaValidator(8, 10);

        self::assertTrue($validator->isValid('123456789'));       // 9 digits
        self::assertSame('123456789', $validator->normalize('123.456.789'));

        self::assertFalse($validator->isValid('1234567'));        // 7 digits
        self::assertFalse($validator->isValid('12345678901'));    // 11 digits
        self::assertNull($validator->normalize('1234567'));
    }

    public function testPathologicallyLongInputIsRejectedBeforeTheRegexRuns(): void
    {
        $input = str_repeat('1', 129);

        self::assertSame('', $this->validator->clean($input));
        self::assertFalse($this->validator->isValid($input));
        self::assertNull($this->validator->normalize($input));
    }

    public function testInputOfExactly128CharactersStillGoesThroughTheRegex(): void
    {
        $input = str_repeat('1', 128);

        // The 128-char guard is a strict "> 128" check, so this still cleans.
        self::assertSame($input, $this->validator->clean($input));
    }

    public function testDegenerateBoundsAreCoercedSanely(): void
    {
        // min < 1 is treated as 1; max < min is treated as min.
        $collapsed = new CedulaValidator(-5, -5);
        self::assertTrue($collapsed->isValid('1'));
        self::assertFalse($collapsed->isValid('12'));
        self::assertFalse($collapsed->isValid(''));

        // Positive but inverted bounds collapse max down to min.
        $inverted = new CedulaValidator(10, 3);
        self::assertTrue($inverted->isValid('1234567890'));   // 10 digits
        self::assertFalse($inverted->isValid('123456789'));   // 9 digits
    }
}
