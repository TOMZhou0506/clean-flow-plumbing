<?php
declare(strict_types=1);

namespace App\Validation;

/**
 * Password-strength checks shared by registration validation.
 */
final class StrongPassword
{
    public const REQUIRED = 'Password is required.';
    public const LENGTH = 'Password must be between 8 and 32 characters.';
    public const UPPERCASE = 'Password must include at least one uppercase letter.';
    public const LOWERCASE = 'Password must include at least one lowercase letter.';
    public const NUMBER = 'Password must include at least one number.';
    public const SPECIAL = 'Password must include at least one special character.';

    /**
     * Return each unmet rule separately so forms can show specific errors.
     *
     * @return list<string>
     */
    public static function errors(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [self::REQUIRED];
        }

        $errors = [];
        if (mb_strlen($value) < 8 || mb_strlen($value) > 32) {
            $errors[] = self::LENGTH;
        }
        if (!preg_match('/[A-Z]/', $value)) {
            $errors[] = self::UPPERCASE;
        }
        if (!preg_match('/[a-z]/', $value)) {
            $errors[] = self::LOWERCASE;
        }
        if (!preg_match('/\d/', $value)) {
            $errors[] = self::NUMBER;
        }
        if (!preg_match('/[^A-Za-z0-9\s]/', $value)) {
            $errors[] = self::SPECIAL;
        }

        return $errors;
    }

    /**
     * Whether the candidate meets every password-strength rule.
     *
     * @param mixed $value Password candidate.
     * @return bool
     */
    public static function isValid(mixed $value): bool
    {
        return self::errors($value) === [];
    }
}
