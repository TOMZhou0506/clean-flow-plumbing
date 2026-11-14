<?php
declare(strict_types=1);

namespace App\Validation;

/**
 * Small, predictable checks for public free-text descriptions.
 */
class MeaningfulText
{
    /**
     * Reject obvious filler without trying to judge grammar or meaning.
     *
     * @param mixed $value Submitted field value.
     * @return bool
     */
    public static function isMeaningful(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }

        $text = trim($value);
        if (strlen($text) < 5 || preg_match('/[A-Za-z]/', $text) !== 1) {
            return false;
        }

        if (
            filter_var($text, FILTER_VALIDATE_EMAIL) !== false
            || filter_var($text, FILTER_VALIDATE_URL) !== false
            || preg_match('/^www\.\S+$/i', $text) === 1
        ) {
            return false;
        }

        // Only reject the most obvious placeholder text. Requiring three
        // different characters or measuring letter frequency also rejected
        // short, legitimate messages such as "SOS!!!".
        $alphanumeric = strtolower((string)preg_replace('/[^A-Za-z0-9]/', '', $text));

        return preg_match('/^([a-z0-9])\1*$/', $alphanumeric) !== 1;
    }
}
