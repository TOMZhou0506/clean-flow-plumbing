<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Address;

use App\Service\Address\PhotonAddress;
use Cake\TestSuite\TestCase;

/**
 * Turning Photon results into the form's four fields, without a network.
 *
 * The shapes below are trimmed from real Photon responses for Victorian
 * addresses, including the Melbourne quirk of the suburb arriving as
 * "district" with "Melbourne" as the city.
 */
class PhotonAddressTest extends TestCase
{
    /**
     * A house result, so the tests below only say what is different.
     *
     * @param array<string, mixed> $overrides Properties to change.
     * @return array<string, mixed>
     */
    private function house(array $overrides = []): array
    {
        return $overrides + [
            'type' => 'house',
            'housenumber' => '12',
            'street' => 'Wattle Street',
            'district' => 'Thomastown',
            'city' => 'Melbourne',
            'state' => 'Victoria',
            'postcode' => '3074',
            'countrycode' => 'AU',
        ];
    }

    public function testAHouseBecomesTheFourFieldsWithTheSuburbFromTheDistrict(): void
    {
        $this->assertSame([
            'street' => '12 Wattle Street',
            'suburb' => 'Thomastown',
            'state' => 'VIC',
            'postcode' => '3074',
        ], PhotonAddress::fromFeature($this->house()));
    }

    public function testOutsideACityTheCityIsTheSuburb(): void
    {
        $fields = PhotonAddress::fromFeature($this->house(['district' => null, 'city' => 'Koondrook']));

        $this->assertSame('Koondrook', $fields['suburb'] ?? null);
    }

    public function testAStreetTakesTheHouseNumberTheCustomerTyped(): void
    {
        $street = [
            'type' => 'street',
            'name' => 'Wattle Street',
            'district' => 'Prahran',
            'city' => 'Melbourne',
            'state' => 'Victoria',
            'postcode' => '3181',
            'countrycode' => 'AU',
        ];

        $this->assertSame('12 Wattle Street', PhotonAddress::fromFeature($street, '12 Wattle St')['street'] ?? null);
        $this->assertSame('3/45 Wattle Street', PhotonAddress::fromFeature($street, '3/45 Wattle')['street'] ?? null);
        $this->assertSame('Wattle Street', PhotonAddress::fromFeature($street, 'Wattle Street')['street'] ?? null);
    }

    public function testHalfAnAddressIsNoAddress(): void
    {
        $this->assertNull(PhotonAddress::fromFeature($this->house(['postcode' => ''])), 'no postcode');
        $this->assertNull(PhotonAddress::fromFeature($this->house(['postcode' => '30'])), 'short postcode');
        $this->assertNull(PhotonAddress::fromFeature($this->house(['housenumber' => ''])), 'house with no number');
        $this->assertNull(PhotonAddress::fromFeature($this->house(['state' => 'Bavaria'])), 'unknown state');
        $this->assertNull(PhotonAddress::fromFeature($this->house(['countrycode' => 'NZ'])), 'not Australia');
        $this->assertNull(PhotonAddress::fromFeature($this->house(['type' => 'city'])), 'not a house or street');
    }

    public function testTheLabelReadsLikeAnAddress(): void
    {
        $fields = PhotonAddress::fromFeature($this->house());

        $this->assertSame('12 Wattle Street, Thomastown VIC 3074', PhotonAddress::label($fields));
    }
}
