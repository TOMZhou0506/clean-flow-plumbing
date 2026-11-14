<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Address;

use App\Service\Address\GnafAddress;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The part of the lookup most likely to be wrong, tested without a network.
 *
 * Real addresses are stranger than the one example in the provider's
 * documentation, so these are the shapes I expect to meet: a plain street
 * number, a unit, a number range, a lot, and records with pieces missing.
 */
class GnafAddressTest extends TestCase
{
    /**
     * Every field G-NAF returns, so the tests below only have to say what is
     * different about their case.
     *
     * @param array<string, mixed> $overrides Fields to change.
     * @return array<string, mixed>
     */
    private function record(array $overrides = []): array
    {
        return $overrides + [
            'flat_type' => '',
            'flat_number' => '',
            'number_first' => '14',
            'number_last' => '',
            'lot_number' => '',
            'street_name' => 'BELLARINE',
            'street_type' => 'HIGHWAY',
            'street_suffix' => '',
            'locality_name' => 'GEELONG',
            'state' => 'VIC',
            'postcode' => '3220',
        ];
    }

    public function testBuildsAPlainStreetAddress(): void
    {
        $fields = GnafAddress::fromRecord($this->record());

        $this->assertSame([
            'street' => '14 Bellarine Highway',
            'suburb' => 'Geelong',
            'state' => 'VIC',
            'postcode' => '3220',
        ], $fields);
    }

    public function testPutsTheUnitInFrontOfTheNumber(): void
    {
        $fields = GnafAddress::fromRecord($this->record([
            'flat_type' => 'UNIT',
            'flat_number' => '2',
        ]));

        $this->assertSame('Unit 2, 14 Bellarine Highway', $fields['street']);
    }

    /**
     * A flat number with no type still has to read sensibly, so the word is
     * supplied rather than left as a bare number stuck on the front.
     */
    public function testAssumesUnitWhenTheFlatTypeIsMissing(): void
    {
        $fields = GnafAddress::fromRecord($this->record(['flat_number' => '7']));

        $this->assertSame('Unit 7, 14 Bellarine Highway', $fields['street']);
    }

    public function testKeepsANumberRangeTogether(): void
    {
        $fields = GnafAddress::fromRecord($this->record([
            'number_first' => '2',
            'number_last' => '6',
        ]));

        $this->assertSame('2-6 Bellarine Highway', $fields['street']);
    }

    /**
     * New estates and rural blocks are identified by lot before they are given
     * a street number.
     */
    public function testFallsBackToTheLotNumber(): void
    {
        $fields = GnafAddress::fromRecord($this->record([
            'number_first' => '',
            'lot_number' => '183',
        ]));

        $this->assertSame('Lot 183 Bellarine Highway', $fields['street']);
    }

    public function testKeepsAStreetSuffix(): void
    {
        $fields = GnafAddress::fromRecord($this->record([
            'street_name' => 'CIRCULAR QUAY',
            'street_type' => '',
            'street_suffix' => 'EAST',
        ]));

        $this->assertSame('14 Circular Quay East', $fields['street']);
    }

    /**
     * Half an address in the form is worse than none, because the customer
     * would submit it without noticing. Each of these has to be refused.
     *
     * @param array<string, mixed> $overrides What is wrong with the record.
     */
    #[DataProvider('unusableRecords')]
    public function testRefusesAnUnusableRecord(array $overrides): void
    {
        $this->assertNull(GnafAddress::fromRecord($this->record($overrides)));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusableRecords(): array
    {
        return [
            'no street name' => [['street_name' => '']],
            'no suburb' => [['locality_name' => '']],
            'no state' => [['state' => '']],
            'no postcode' => [['postcode' => '']],
            'postcode is not four digits' => [['postcode' => '322']],
            'postcode has letters' => [['postcode' => '3K20']],
        ];
    }

    /**
     * The table caps street_address at 255. Truncating here is not pretty, but
     * it beats a save that fails after the customer thinks they have chosen an
     * address.
     */
    public function testTruncatesAnAbsurdlyLongStreetLine(): void
    {
        $fields = GnafAddress::fromRecord($this->record([
            'street_name' => str_repeat('LONGNAME ', 60),
        ]));

        $this->assertNotNull($fields);
        $this->assertLessThanOrEqual(255, mb_strlen($fields['street']));
    }
}
