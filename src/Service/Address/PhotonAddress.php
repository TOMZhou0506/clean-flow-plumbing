<?php
declare(strict_types=1);

namespace App\Service\Address;

/**
 * Turns one Photon search result into the four fields the job request form
 * stores, plus the label shown in the suggestion list.
 *
 * Kept apart from the HTTP client for the same reason as GnafAddress: real
 * results are untidy, and the tests can throw them at this directly with no
 * network.
 *
 * Photon is built on OpenStreetMap, which does not hold every house number. So
 * a street result is accepted as well as a house, and when the customer has
 * already typed a number ("12 Wattle St") it is put in front of the street.
 */
class PhotonAddress
{
    /**
     * @var int
     */
    private const MAX_STREET_LENGTH = 255;

    /**
     * OpenStreetMap spells states out in full; the form stores the abbreviation.
     *
     * @var array<string, string>
     */
    private const STATES = [
        'australian capital territory' => 'ACT',
        'new south wales' => 'NSW',
        'northern territory' => 'NT',
        'queensland' => 'QLD',
        'south australia' => 'SA',
        'tasmania' => 'TAS',
        'victoria' => 'VIC',
        'western australia' => 'WA',
    ];

    /**
     * @param array<string, mixed> $properties One feature's "properties" object.
     * @param string $term What the customer typed, for a leading house number.
     * @return array{street: string, suburb: string, state: string, postcode: string}|null
     */
    public static function fromFeature(array $properties, string $term = ''): ?array
    {
        if (strtoupper(self::text($properties, 'countrycode')) !== 'AU') {
            return null;
        }

        $state = self::STATES[strtolower(self::text($properties, 'state'))] ?? '';
        $postcode = self::text($properties, 'postcode');
        // In a city, OpenStreetMap puts the suburb in "district" and the city
        // name ("Melbourne") in "city", so the district wins when there is one.
        $suburb = self::text($properties, 'district')
            ?: self::text($properties, 'city')
            ?: self::text($properties, 'locality');

        $type = self::text($properties, 'type');
        if ($type === 'house') {
            $number = self::text($properties, 'housenumber');
            $streetName = self::text($properties, 'street');
            $street = $number !== '' && $streetName !== '' ? $number . ' ' . $streetName : '';
        } elseif ($type === 'street') {
            $street = self::text($properties, 'name');
            $typedNumber = self::typedNumber($term);
            if ($street !== '' && $typedNumber !== '') {
                $street = $typedNumber . ' ' . $street;
            }
        } else {
            return null;
        }

        // Same rule as GnafAddress: half an address is worse than none, because
        // it fills the form and looks finished. Without these the customer
        // types the address by hand instead.
        if ($street === '' || $suburb === '' || $state === '' || !preg_match('/^\d{4}$/', $postcode)) {
            return null;
        }

        return [
            'street' => mb_substr($street, 0, self::MAX_STREET_LENGTH),
            'suburb' => $suburb,
            'state' => $state,
            'postcode' => $postcode,
        ];
    }

    /**
     * The line shown in the suggestion list, e.g. "12 Wattle Street, Prahran VIC 3181".
     *
     * @param array{street: string, suburb: string, state: string, postcode: string} $fields
     */
    public static function label(array $fields): string
    {
        return sprintf('%s, %s %s %s', $fields['street'], $fields['suburb'], $fields['state'], $fields['postcode']);
    }

    /**
     * A house number at the start of what was typed: "12", "12A", "3/45".
     */
    private static function typedNumber(string $term): string
    {
        return preg_match('/^\s*(\d+[A-Za-z]?(?:\/\d+[A-Za-z]?)?)\s+\S/', $term, $match) === 1 ? $match[1] : '';
    }

    /**
     * @param array<string, mixed> $properties
     */
    private static function text(array $properties, string $key): string
    {
        $value = $properties[$key] ?? '';

        return is_scalar($value) ? trim((string)$value) : '';
    }
}
