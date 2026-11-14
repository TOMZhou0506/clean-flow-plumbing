<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service\Address;

use App\Service\Address\PhotonClient;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

/**
 * The Photon fallback, with the network replaced by canned responses.
 */
class PhotonClientTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Configure::write('Address.state', 'VIC');
        Configure::write('Address.photonEndpoint', 'https://photon.komoot.io');
        Cache::clear('address_suggestions');
        Cache::clear('address_resolved');
    }

    public function tearDown(): void
    {
        Client::clearMockResponses();
        parent::tearDown();
    }

    /**
     * @param array<array<string, mixed>> $properties One properties object per result.
     */
    private function photonReturns(array $properties): void
    {
        $features = array_map(static fn(array $p): array => ['type' => 'Feature', 'properties' => $p], $properties);
        Client::addMockResponse(
            'GET',
            'https://photon.komoot.io/api/*',
            new Response(['HTTP/1.1 200 OK', 'Content-Type: application/json'], (string)json_encode([
                'type' => 'FeatureCollection',
                'features' => $features,
            ])),
        );
    }

    public function testSuggestsVictorianAddressesAndResolvesTheChosenOne(): void
    {
        $this->photonReturns([
            ['type' => 'house', 'housenumber' => '12', 'street' => 'Wattle Street', 'district' => 'Thomastown',
                'city' => 'Melbourne', 'state' => 'Victoria', 'postcode' => '3074', 'countrycode' => 'AU'],
            // Inside the search box but over the border, so it is dropped.
            ['type' => 'street', 'name' => 'Wattle Street', 'district' => "O'Connor", 'city' => 'North Canberra',
                'state' => 'Australian Capital Territory', 'postcode' => '2602', 'countrycode' => 'AU'],
            // No postcode, so it is dropped.
            ['type' => 'house', 'housenumber' => '3', 'street' => 'Wattle Street', 'city' => 'Darley',
                'state' => 'Victoria', 'countrycode' => 'AU'],
        ]);
        $client = new PhotonClient();

        $suggestions = $client->suggest('12 Wattle');

        $this->assertCount(1, $suggestions);
        $this->assertSame('12 Wattle Street, Thomastown VIC 3074', $suggestions[0]['label']);
        $this->assertMatchesRegularExpression('/^ph[0-9a-f]{32}$/', $suggestions[0]['id']);
        $this->assertSame([
            'street' => '12 Wattle Street',
            'suburb' => 'Thomastown',
            'state' => 'VIC',
            'postcode' => '3074',
        ], $client->resolve($suggestions[0]['id']));
    }

    public function testResolveOnlyReadsIdsItHandedOut(): void
    {
        $client = new PhotonClient();

        $this->assertNull($client->resolve('ph' . str_repeat('a', 32)), 'never suggested');
        $this->assertNull($client->resolve('GAVIC419734226'), 'a getAddress id');
        $this->assertNull($client->resolve('../../etc/passwd'), 'not an id at all');
    }

    public function testATooShortTermIsNotSent(): void
    {
        // No mock is registered, so any request would fail the test.
        $this->assertSame([], (new PhotonClient())->suggest('12 '));
    }

    public function testAProviderErrorIsNoSuggestions(): void
    {
        Client::addMockResponse(
            'GET',
            'https://photon.komoot.io/api/*',
            new Response(['HTTP/1.1 503 Service Unavailable'], ''),
        );

        $this->assertSame([], (new PhotonClient())->suggest('12 Wattle'));
    }
}
