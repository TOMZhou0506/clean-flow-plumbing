<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\AddressesController;
use App\Test\TestCase\Service\Address\StubLookup;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The address lookup endpoints.
 *
 * Every test runs against a stub, so the suite never needs a network, a key or
 * any of the provider's daily allowance. What is under test is the guard
 * around the lookup rather than the lookup itself: who may call it, what
 * happens when it is switched off, and that a failure arrives as an empty
 * answer rather than as an error the form would have to cope with.
 *
 * @link \App\Controller\AddressesController
 */
class AddressesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'app.Admins',
        'app.Customers',
        'app.FormRateLimits',
        'app.JobRequests',
    ];

    /**
     * @var \App\Test\TestCase\Service\Address\StubLookup
     */
    private StubLookup $lookup;

    /**
     * Swaps the real client for the stub, once.
     *
     * Registered here rather than per test so repeated requests in one test do
     * not stack up listeners. The stub is read through $this at call time, so
     * a test can still change what the provider "says" before asking.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->lookup = new StubLookup();

        EventManager::instance()->on('Controller.initialize', function (EventInterface $event): void {
            $controller = $event->getSubject();
            if ($controller instanceof AddressesController) {
                $controller->setLookup($this->lookup);
            }
        });

        // A key has to look present for the endpoints to do anything. The
        // value is never used, because the client behind them is the stub.
        Configure::write('Address.enabled', true);
        Configure::write('Address.apiKey', 'test-key');
        Configure::write('Address.state', 'VIC');
    }

    /**
     * @return void
     */
    private function signInAsCustomer(): void
    {
        $this->session(['CustomerAuth' => [
            'cust_id' => 1,
            'cust_email' => 'kash@example.com',
            'cust_fname' => 'Kash',
            'cust_lname' => 'Patel',
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(): array
    {
        $decoded = json_decode((string)$this->_response->getBody(), true);

        $this->assertIsArray($decoded, 'The endpoint should always answer with JSON.');

        return $decoded;
    }

    public function testSuggestsAddressesToASignedInCustomer(): void
    {
        $this->lookup->suggestions = [
            ['id' => 'GAVIC419734226', 'label' => '26 STIRLING ST, FOOTSCRAY VIC 3011'],
        ];
        $this->signInAsCustomer();

        $this->get('/job-requests/address/suggest?q=26+Stirling');

        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Type', 'application/json');
        $this->assertSame('GAVIC419734226', $this->body()['suggestions'][0]['id']);
    }

    /**
     * The job request form is behind a customer login, so nobody needs this
     * anonymously and there is no reason to let a stranger spend the daily
     * allowance.
     */
    public function testRefusesASignedOutVisitor(): void
    {
        $this->lookup->suggestions = [['id' => 'GAVIC419734226', 'label' => 'should not appear']];

        $this->get('/job-requests/address/suggest?q=26+Stirling');

        $this->assertRedirectContains('/login');
        $this->assertSame(0, $this->lookup->suggestCalls, 'The provider should never have been asked.');
    }

    public function testResolvesASuggestionIntoTheFourFields(): void
    {
        $this->lookup->resolved = [
            'street' => '26 Stirling Street',
            'suburb' => 'Footscray',
            'state' => 'VIC',
            'postcode' => '3011',
        ];
        $this->signInAsCustomer();

        $this->get('/job-requests/address/resolve?id=GAVIC419734226');

        $this->assertResponseOk();
        $address = $this->body()['address'];
        $this->assertSame('26 Stirling Street', $address['street']);
        $this->assertSame('Footscray', $address['suburb']);
        $this->assertSame('VIC', $address['state']);
        $this->assertSame('3011', $address['postcode']);
    }

    /**
     * The whole point of the fallback. With the lookup off, the endpoint still
     * answers 200 with an empty result, so the script finds nothing to show
     * and the customer types the address in themselves.
     */
    public function testAnswersEmptyWhenTheLookupIsSwitchedOff(): void
    {
        Configure::write('Address.enabled', false);
        $this->lookup->suggestions = [['id' => 'GAVIC419734226', 'label' => 'should not appear']];
        $this->signInAsCustomer();

        $this->get('/job-requests/address/suggest?q=26+Stirling');

        $this->assertResponseOk();
        $this->assertSame([], $this->body()['suggestions']);
        $this->assertSame(0, $this->lookup->suggestCalls, 'The kill switch should stop the call, not filter it.');
    }

    /**
     * No key and the fallback turned off: there is no provider at all, so the
     * customer types the address by hand.
     */
    public function testAnswersEmptyWithNoApiKeyAndNoFallback(): void
    {
        Configure::write('Address.apiKey', '');
        Configure::write('Address.fallback', '');
        $this->lookup->suggestions = [['id' => 'GAVIC419734226', 'label' => 'should not appear']];
        $this->signInAsCustomer();

        $this->get('/job-requests/address/suggest?q=26+Stirling');

        $this->assertResponseOk();
        $this->assertSame([], $this->body()['suggestions']);
        $this->assertSame(0, $this->lookup->suggestCalls);
    }

    /**
     * No key is the normal case for a fresh copy of the project, and the free
     * Photon fallback answers then. The stub stands in for it here, so this
     * checks the controller still asks, not what Photon returns.
     */
    public function testFallsBackToPhotonWithNoApiKey(): void
    {
        Configure::write('Address.apiKey', '');
        Configure::write('Address.fallback', 'photon');
        $this->lookup->suggestions = [['id' => 'ph' . str_repeat('a', 32), 'label' => '12 Wattle Street, Thomastown VIC 3074']];
        $this->signInAsCustomer();

        $this->get('/job-requests/address/suggest?q=12+Wattle');

        $this->assertResponseOk();
        $this->assertSame('12 Wattle Street, Thomastown VIC 3074', $this->body()['suggestions'][0]['label']);
        $this->assertSame(1, $this->lookup->suggestCalls);
    }

    /**
     * With Photon as the provider, the form tells the browser where it is so
     * suggestions can be fetched directly. With a getAddress() key, nothing
     * about Photon reaches the page and everything goes through the server.
     */
    public function testTheFormPointsTheBrowserAtPhotonOnlyWhenItIsTheProvider(): void
    {
        $this->signInAsCustomer();

        Configure::write('Address.apiKey', '');
        Configure::write('Address.fallback', 'photon');
        $this->get('/job-requests/add');
        $this->assertResponseOk();
        $this->assertResponseContains('data-photon-url="https://photon.komoot.io/api/"');
        $this->assertResponseContains('data-photon-state="VIC"');

        Configure::write('Address.apiKey', 'test-key');
        $this->get('/job-requests/add');
        $this->assertResponseOk();
        $this->assertResponseNotContains('data-photon-url');
        $this->assertResponseContains('data-suggest-url');
    }

    /**
     * A provider that cannot be reached returns null from the client, and that
     * has to arrive at the browser as "no address" rather than as an error.
     */
    public function testAnswersNullWhenTheProviderCannotResolve(): void
    {
        $this->lookup->resolved = null;
        $this->signInAsCustomer();

        $this->get('/job-requests/address/resolve?id=GAVIC419734226');

        $this->assertResponseOk();
        $this->assertNull($this->body()['address']);
    }

    /**
     * These are read-only lookups and the browser only ever gets them. A POST
     * should be refused outright rather than quietly reinterpreted, which is
     * the failure mode that made the job status buttons look like they did
     * nothing at all.
     */
    public function testRefusesAPost(): void
    {
        $this->signInAsCustomer();
        $this->enableCsrfToken();

        $this->post('/job-requests/address/suggest', ['q' => '26 Stirling']);

        $this->assertResponseCode(405);
    }

    /**
     * An office account has no business here either. It is not their form, and
     * the endpoint spends a metered allowance.
     */
    public function testRefusesAnOfficeAccount(): void
    {
        $this->session(['Auth' => [
            'admin_id' => 1,
            'admin_email' => 'kate@example.com',
            'role' => 'admin',
        ]]);

        $this->get('/job-requests/address/suggest?q=26+Stirling');

        $this->assertRedirectContains('/login');
        $this->assertSame(0, $this->lookup->suggestCalls);
    }
}
