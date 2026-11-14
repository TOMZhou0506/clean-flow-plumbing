<?php
declare(strict_types=1);

namespace App\Service\Address;

/**
 * Turns one G-NAF record into the four fields the job request form stores.
 *
 * Kept apart from the HTTP client on purpose. This is the part most likely to
 * be wrong, because real addresses are stranger than the example in anybody's
 * documentation: units, lot numbers, number ranges, streets with no type.
 * Separating it means the tests can throw those at it directly without a
 * network, a key, or a quota.
 */
class GnafAddress
{
    /**
     * Street addresses are capped at 255 in the table, and nothing G-NAF
     * produces comes close, but I would rather truncate here than have the
     * save fail after the customer thinks they have picked an address.
     *
     * @var int
     */
    private const MAX_STREET_LENGTH = 255;

    /**
     * @param array<string, mixed> $record The /get response body.
     * @return array{street: string, suburb: string, state: string, postcode: string}|null
     */
    public static function fromRecord(array $record): ?array
    {
        $state = strtoupper(self::text($record, 'state'));
        $suburb = self::titleCase(self::text($record, 'locality_name'));
        $postcode = self::text($record, 'postcode');
        $street = self::street($record);

        // A record missing any of these is no use to the form. Returning null
        // leaves the customer typing by hand, which is a worse experience but
        // a correct one, and far better than writing half an address into the
        // fields and letting them submit it without noticing.
        if ($state === '' || $suburb === '' || $street === '') {
            return null;
        }

        if (!preg_match('/^\d{4}$/', $postcode)) {
            return null;
        }

        return [
            'street' => $street,
            'suburb' => $suburb,
            'state' => $state,
            'postcode' => $postcode,
        ];
    }

    /**
     * Assemble the street line: unit, then number, then the street itself.
     *
     * G-NAF splits all of this apart, so the line has to be put back together
     * rather than read out of a field. Written as "Unit 2, 200 George Street"
     * because that is how an Australian writes it on an envelope, and the
     * plumber reading the job sheet is the one who has to find the place.
     *
     * @param array<string, mixed> $record The /get response body.
     * @return string
     */
    private static function street(array $record): string
    {
        $parts = [];

        $flatType = self::text($record, 'flat_type');
        $flatNumber = self::text($record, 'flat_number');
        if ($flatNumber !== '') {
            $parts[] = trim(self::titleCase($flatType !== '' ? $flatType : 'Unit') . ' ' . $flatNumber) . ',';
        }

        // Tested against street_name on its own, not against the assembled
        // line. My first version checked whether the whole line came out
        // empty, which a record with no street name still passes: the type
        // survives on its own and the customer is handed "14 Highway" as
        // though it were an address. There is no usable street line without a
        // name, whatever else the record carries.
        $streetName = self::text($record, 'street_name');
        if ($streetName === '') {
            return '';
        }

        $number = self::number($record);
        $name = trim(implode(' ', array_filter([
            self::titleCase($streetName),
            self::titleCase(self::text($record, 'street_type')),
            self::titleCase(self::text($record, 'street_suffix')),
        ], static fn(string $value): bool => $value !== '')));

        if ($number !== '') {
            $parts[] = $number;
        }

        $parts[] = $name;

        return mb_substr(trim(implode(' ', $parts)), 0, self::MAX_STREET_LENGTH);
    }

    /**
     * The street number, which is a range often enough to be worth handling.
     *
     * A property can be "2-6 Smith Street". number_last holds the far end of
     * that range and is empty for an ordinary address. Where there is no street
     * number at all I fall back to the lot number, which is how new estates and
     * rural blocks are identified before they are given one.
     *
     * @param array<string, mixed> $record The /get response body.
     * @return string
     */
    private static function number(array $record): string
    {
        $first = self::text($record, 'number_first');
        $last = self::text($record, 'number_last');

        if ($first === '') {
            $lot = self::text($record, 'lot_number');

            return $lot === '' ? '' : 'Lot ' . $lot;
        }

        return $last === '' ? $first : $first . '-' . $last;
    }

    /**
     * G-NAF is stored in capitals. Shouting the address back into the form
     * looks like a bug, so I lower it and capitalise each word.
     *
     * This is not perfect and I am not pretending otherwise: "McKenzie" comes
     * back as "Mckenzie". The field stays editable, a customer who cares can
     * fix it, and the suburb, state and postcode that actually route the job
     * are unaffected.
     *
     * @param string $value The G-NAF value.
     * @return string
     */
    private static function titleCase(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $record The response body.
     * @param string $key Field to read.
     * @return string
     */
    private static function text(array $record, string $key): string
    {
        $value = $record[$key] ?? '';

        return is_scalar($value) ? trim((string)$value) : '';
    }
}
