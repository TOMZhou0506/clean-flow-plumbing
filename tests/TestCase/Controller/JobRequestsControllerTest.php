<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DateTimeZone;

/**
 * US 2.1-2.3 customer request submission tests.
 */
class JobRequestsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Customers',
        'app.JobRequests',
    ];

    private function loginAsCustomer(int $id = 1): void
    {
        $identity = [
            'cust_id' => $id,
            'cust_email' => $id === 1 ? 'kash@example.com' : 'other@example.com',
            'cust_fname' => $id === 1 ? 'Kash' : 'Other',
            'cust_lname' => $id === 1 ? 'Patel' : 'Customer',
        ];
        $this->session([
            'CustomerAuth' => $identity,
        ]);
    }

    public function testLoggedOutCustomerIsSentToCustomerLogin(): void
    {
        $this->get('/job-requests/add');

        // The shared sign-in page. The site used to have two login buttons
        // and the client could not tell them apart, so everything now
        // funnels to /login, which asks which kind of account you have.
        $this->assertRedirectContains('/login');
    }

    public function testAdminSessionDoesNotCountAsCustomerLogin(): void
    {
        $this->session([
            'AdminAuth' => [
                'admin_id' => 1,
                'admin_username' => 'hannah',
                'role' => 'admin',
            ],
        ]);

        $this->get('/job-requests/add');

        // Turned away. The point of this test is that an admin session is
        // not accepted as a customer one.
        $this->assertRedirectContains('/login');
    }

    public function testCustomerLoginAcceptsCorrectCredentials(): void
    {
        $this->enableCsrfToken();
        $this->post('/login', [
            'email' => 'kash@example.com',
            'password' => 'Customer#2026',
        ]);
        $this->assertRedirect('/job-requests/add');
    }

    public function testLoginReturnsCustomerToRequestedJobPage(): void
    {
        $this->enableCsrfToken();
        $this->post('/login?redirect=%2Fjob-requests', [
            'email' => 'kash@example.com',
            'password' => 'Customer#2026',
        ]);

        $this->assertRedirect('/job-requests');
    }

    public function testCustomerMenuChangesWithLoginState(): void
    {
        $this->get('/');
        $this->assertResponseContains('Log in');
        // Signing up is offered on the sign-in page, not in the menu.
        $this->assertResponseNotContains('Create account');

        $this->loginAsCustomer();
        $this->get('/');
        $this->assertResponseContains('Kash');
        // The placeholder "My account (Coming soon)" item is gone.
        $this->assertResponseNotContains('Coming soon');
        $this->assertResponseContains('My job requests');
        $this->assertResponseContains('Request service');
        $this->assertResponseContains('Log out');
    }

    public function testCustomerSeesOnlyTheirOwnJobRequests(): void
    {
        $this->loginAsCustomer();
        $this->get('/job-requests');

        $this->assertResponseOk();
        $this->assertResponseContains('REQ-000001');
        $this->assertResponseContains('12 Clayton Road');
        $this->assertResponseNotContains('REQ-000002');
        $this->assertResponseNotContains('99 Private Street');
    }

    public function testCustomerLogoutUsesPostAndReturnsHome(): void
    {
        $this->loginAsCustomer();
        $this->enableCsrfToken();
        $this->post('/customers/logout');

        $this->assertRedirect('/');
    }

    public function testCustomerLogoutRejectsGet(): void
    {
        $this->loginAsCustomer();
        $this->get('/customers/logout');

        $this->assertResponseCode(405);
    }

    public function testLoggedInCustomerCanSubmitARequest(): void
    {
        $this->loginAsCustomer();
        $this->enableCsrfToken();
        $this->post('/job-requests/add', [
            'street_address' => '8 Test Avenue',
            'suburb' => 'Clayton',
            'state' => 'VIC',
            'postcode' => '3168',
            'job_description' => 'The shower drain is blocked.',
            'preferred_datetime' => '2030-11-20T14:30',
            'contact_phone' => '0412 345 678',
            'access_instructions' => 'Ring the bell at reception.',
            'customer_id' => 2,
            'status' => 'approved',
        ]);

        $this->assertResponseCode(302);
        $table = TableRegistry::getTableLocator()->get('JobRequests');
        $saved = $table->find()->where(['street_address' => '8 Test Avenue'])->firstOrFail();
        $this->assertSame(1, $saved->customer_id, 'The customer id must come from the session.');
        $this->assertSame('submitted', $saved->status, 'Customers cannot approve their own request.');
        $this->assertSame(
            '2030-11-20 03:30',
            $saved->preferred_datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
            'The Melbourne preference must be stored as the matching UTC instant.',
        );
        $this->assertRedirectContains('/job-requests/confirmation/' . $saved->id);
    }

    public function testPreferencePickerOnlyListsBusinessHourStartSlots(): void
    {
        $this->loginAsCustomer();
        $this->get('/job-requests/add');

        $this->assertResponseOk();
        $this->assertResponseContains('name="preferred_date"');
        $this->assertResponseContains('name="preferred_time"');
        $this->assertResponseContains('value="08:00"');
        $this->assertResponseContains('value="16:00"');
        $this->assertResponseNotContains('value="16:30"');
        $this->assertResponseNotContains('type="datetime-local"');
    }

    public function testCustomerCanSubmitUsingTheDateAndTimePickerFields(): void
    {
        $this->loginAsCustomer();
        $this->enableCsrfToken();
        $this->post('/job-requests/add', [
            'street_address' => '9 Test Avenue',
            'suburb' => 'Clayton',
            'state' => 'VIC',
            'postcode' => '3168',
            'job_description' => 'The kitchen tap is leaking.',
            'preferred_date' => '2030-11-20',
            'preferred_time' => '14:30',
            'contact_phone' => '0412 345 678',
        ]);

        $this->assertRedirectContains('/job-requests/confirmation/');
        $saved = TableRegistry::getTableLocator()->get('JobRequests')->find()
            ->where(['street_address' => '9 Test Avenue'])->firstOrFail();
        $this->assertSame(
            '2030-11-20 03:30',
            $saved->preferred_datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i'),
        );
    }

    public function testServerRejectsWeekendOutsideHoursAndPastPickerValues(): void
    {
        $this->loginAsCustomer();
        $this->enableCsrfToken();

        $invalidPreferences = [
            ['2030-11-23', '10:00'], // Saturday
            ['2030-11-20', '07:30'],
            ['2030-11-20', '16:30'],
            ['2020-11-20', '10:00'],
        ];
        foreach ($invalidPreferences as [$date, $time]) {
            $this->post('/job-requests/add', [
                'street_address' => 'Rejected Test Avenue',
                'suburb' => 'Clayton',
                'state' => 'VIC',
                'postcode' => '3168',
                'job_description' => 'The kitchen tap is leaking.',
                'preferred_date' => $date,
                'preferred_time' => $time,
                'contact_phone' => '0412 345 678',
            ]);
            $this->assertResponseOk("{$date} {$time}");
            $this->assertResponseContains('Choose a future weekday between 08:00 and 16:00');
            $this->assertFalse(TableRegistry::getTableLocator()->get('JobRequests')
                ->exists(['street_address' => 'Rejected Test Avenue']));
        }
    }

    public function testCustomerCannotRequestVisitOutsideBusinessHours(): void
    {
        $this->loginAsCustomer();
        $this->enableCsrfToken();
        $this->post('/job-requests/add', [
            'street_address' => '8 Test Avenue',
            'suburb' => 'Clayton',
            'state' => 'VIC',
            'postcode' => '3168',
            'job_description' => 'The shower drain is blocked.',
            'preferred_datetime' => '2030-11-20T04:00',
            'contact_phone' => '0412 345 678',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('Choose a future weekday between 08:00 and 16:00');
        $this->assertFalse(TableRegistry::getTableLocator()->get('JobRequests')
            ->exists(['street_address' => '8 Test Avenue']));
    }

    public function testCustomerCanSeeTheirOwnConfirmation(): void
    {
        $this->loginAsCustomer();
        $this->get('/job-requests/confirmation/1');

        $this->assertResponseOk();
        $this->assertResponseContains('REQ-000001');
        $this->assertResponseContains('Leaking kitchen tap.');
    }

    public function testOnlyAPhoneNumberTheFormAcceptsIsPrefilled(): void
    {
        $customers = TableRegistry::getTableLocator()->get('Customers');
        $this->loginAsCustomer();

        // An older profile can hold a number in a format the request form rejects.
        $customers->updateAll(['cust_phone' => '1234 567 899'], ['cust_id' => 1]);
        $this->get('/job-requests/add');
        $this->assertResponseOk();
        $this->assertResponseNotContains('1234 567 899');

        $customers->updateAll(['cust_phone' => '0412 345 678'], ['cust_id' => 1]);
        $this->get('/job-requests/add');
        $this->assertResponseContains('0412 345 678');

        $customers->updateAll(['cust_phone' => '03 9123 4567'], ['cust_id' => 1]);
        $this->get('/job-requests/add');
        $this->assertResponseContains('03 9123 4567');
    }

    public function testCustomerSeesTheProgressBarInTheirOwnWords(): void
    {
        $this->loginAsCustomer();
        $this->get('/job-requests/confirmation/1');

        $this->assertResponseOk();
        $this->assertResponseContains('job-progress');
        $this->assertResponseContains('Under review');
        $this->assertResponseContains('Appointment scheduled');
        // Internal steps stay on the office side.
        $this->assertResponseNotContains('Compliance check');
        $this->assertResponseNotContains('Assign plumber');
        $this->assertResponseNotContains('Plumber approval');
    }

    public function testCustomerCannotSeeAnotherCustomersConfirmation(): void
    {
        Configure::write('debug', false);
        $this->loginAsCustomer();
        $this->get('/job-requests/confirmation/2');

        $this->assertResponseCode(404);
        $this->assertResponseNotContains('This belongs to another customer.');
    }
}
