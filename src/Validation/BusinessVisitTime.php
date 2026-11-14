<?php
declare(strict_types=1);

namespace App\Validation;

use App\Model\Table\ContactSubmissionsTable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** Melbourne local-time rules shared by customer preferences and assignment. */
class BusinessVisitTime
{
    /** Half-hour customer preference choices from opening through the last start. */
    public static function preferredStartSlots(): array
    {
        $slots = [];
        for ($minute = 8 * 60; $minute <= 16 * 60; $minute += 30) {
            $time = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
            $slots[$time] = $time;
        }

        return $slots;
    }

    /** Parse a datetime-local form value without accepting overflow dates. */
    public static function parseForm(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, self::timezone());

        return $date !== false && $date->format('Y-m-d\TH:i') === $value ? $date : null;
    }

    /** Accept the two formats Cake may validate when creating an entity. */
    public static function preferredIsValid(mixed $value): bool
    {
        if ($value instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($value)->setTimezone(self::timezone());
        } elseif (is_string($value)) {
            $format = str_contains($value, 'T') ? '!Y-m-d\TH:i' : '!Y-m-d H:i:s';
            $date = DateTimeImmutable::createFromFormat($format, $value, self::timezone());
            if ($date === false || $date->format(substr($format, 1)) !== $value) {
                return false;
            }
        } else {
            return false;
        }

        return $date > new DateTimeImmutable('now', self::timezone())
            && (int)$date->format('N') <= 5
            && $date->format('H:i') >= '08:00'
            && $date->format('H:i') <= '16:00';
    }

    /** Return the confirmed end only when the entire visit fits in one workday. */
    public static function endForDuration(?DateTimeImmutable $start, mixed $duration): ?DateTimeImmutable
    {
        if ($start === null || !is_scalar($duration) || !ctype_digit((string)$duration)) {
            return null;
        }
        $hours = (int)$duration;
        if ($hours < 1 || $hours > 8 || !self::preferredIsValid($start)) {
            return null;
        }

        $end = $start->modify('+' . $hours . ' hours');

        return $end->format('Y-m-d') === $start->format('Y-m-d')
            && $end->format('H:i') <= '17:00' ? $end : null;
    }

    /** The business timezone, not the server's UTC default. */
    public static function timezone(): DateTimeZone
    {
        return ContactSubmissionsTable::businessTimezone();
    }
}
