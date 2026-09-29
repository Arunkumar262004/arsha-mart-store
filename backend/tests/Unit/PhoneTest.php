<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function numbers(): array
    {
        return [
            'local 10 digits' => ['9876543210', '+919876543210'],
            'spaces' => ['98765 43210', '+919876543210'],
            'leading zero' => ['09876543210', '+919876543210'],
            'country code without plus' => ['919876543210', '+919876543210'],
            'international with dashes' => ['+91-98765-43210', '+919876543210'],
            'other country' => ['+44 7911 123456', '+447911123456'],
            'blank' => ['  ', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('numbers')]
    public function testItNormalizesToE164(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, Phone::normalize($input));
    }

    public function testValidity(): void
    {
        $this->assertTrue(Phone::isValid('+919876543210'));
        $this->assertFalse(Phone::isValid(Phone::normalize('12345')));
        $this->assertFalse(Phone::isValid(Phone::normalize('abc')));
        $this->assertFalse(Phone::isValid(null));
    }
}
