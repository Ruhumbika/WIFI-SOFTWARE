<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class PhoneNormalizer
{
    public static function normalize(string $value): string
    {
        $phone = preg_replace('/[\s()+-]/', '', trim($value));
        if (preg_match('/^0[67]\d{8}$/', $phone)) {
            $phone = '255'.substr($phone, 1);
        } elseif (preg_match('/^[67]\d{8}$/', $phone)) {
            $phone = '255'.$phone;
        }
        if (! preg_match('/^255[67]\d{8}$/', $phone)) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid Tanzania mobile number.']);
        }

        return $phone;
    }
}
