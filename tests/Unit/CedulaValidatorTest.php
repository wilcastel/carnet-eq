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
}
