<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Fixture\AdminsFixture;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/** US 11.2 security checks: privilege escalation, role injection, stale sessions and CSRF. */
class AccountSecurityChecksTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.LoginRateLimits',
        'app.Customers',
        'app.JobRequests',
        'app.JobAssignments',
    ];

    private function signIn(int $id, string $role): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => $id,
            'admin_username' => match ($id) {
                1 => 'hannah',
                2 => 'staffuser',
                default => 'manageruser',
            },
            'role' => $role,
        ]]);
    }

    private function signInAsCustomer(int $id = 1): void
    {
        $this->session(['CustomerAuth' => [
            'cust_id' => $id,
            'cust_email' => 'kash@example.com',
            'cust_fname' => 'Kash',
            'cust_lname' => 'Patel',
        ]]);
    }

    private function adminCount(): int
    {
        return TableRegistry::getTableLocator()->get('Admins')->find()->count();
    }

    private function assertNothingChanged(): void
    {
        $accounts = TableRegistry::getTableLocator()->get('Admins');
        $this->assertSame(3, $accounts->find()->count(), 'an account was created or removed');
        $this->assertSame(0, $accounts->find()->where(['admin_username' => 'sneaky'])->count());
        foreach ([1, 2, 3] as $id) {
            $this->assertTrue((bool)$accounts->get($id)->active, 'account ' . $id . ' was deactivated');
            $this->assertSame(['admin', 'staff', 'manager'][$id - 1], $accounts->get($id)->role);
        }
    }

    /** A refusal is a 403, or a redirect to the sign-in page. Never a page, and never a success redirect. */
    private function assertRefused(string $what): void
    {
        $code = $this->_response->getStatusCode();
        $ok = $code === 403 || ($code === 302 && str_contains($this->_response->getHeaderLine('Location'), '/login'));
        $this->assertTrue($ok, $what . ' was not refused (status ' . $code . ')');
    }

    /** Every way of reaching admin account management, as page loads and as form posts. */
    private function tryAdminAccountManagement(): void
    {
        $this->enableCsrfToken();
        $new = [
            'admin_username' => 'sneaky',
            'admin_email' => 'sneaky@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
            'role' => 'manager',
        ];

        foreach (['/admins', '/admins/add', '/admins/deactivate/1', '/admins/reactivate/1'] as $url) {
            $this->get($url);
            $this->assertRefused('GET ' . $url);
        }
        $this->post('/admins/add', $new);
        $this->assertRefused('POST /admins/add');
        $this->post('/admins/deactivate/1', ['manager_password' => AdminsFixture::PASSWORD]);
        $this->assertRefused('POST /admins/deactivate/1');
        $this->post('/admins/reactivate/1', ['manager_password' => AdminsFixture::PASSWORD]);
        $this->assertRefused('POST /admins/reactivate/1');
    }

    public function testNoOneButTheManagerCanReachAdminAccountManagement(): void
    {
        Configure::write('debug', false);

        // Signed out.
        $this->tryAdminAccountManagement();
        $this->assertNothingChanged();

        // An admin, a plumber and a customer.
        $this->signIn(1, 'admin');
        $this->tryAdminAccountManagement();
        $this->assertNothingChanged();

        $this->signIn(2, 'staff');
        $this->tryAdminAccountManagement();
        $this->assertNothingChanged();

        $this->cleanup();
        $this->signInAsCustomer();
        $this->tryAdminAccountManagement();
        $this->assertNothingChanged();
    }

    public function testEachNonManagerIsTurnedAwayFromAdminAccountPagesInTheRightWay(): void
    {
        Configure::write('debug', false);

        $this->get('/admins/add');
        $this->assertRedirectContains('/login');

        $this->signInAsCustomer();
        $this->get('/admins/add');
        $this->assertRedirectContains('/login');

        $this->cleanup();
        $this->signIn(1, 'admin');
        $this->get('/admins/add');
        $this->assertResponseCode(403);

        $this->signIn(2, 'staff');
        $this->get('/admins/add');
        $this->assertResponseCode(403);
    }

    public function testCustomersAndStaffCannotAddOrRemovePlumberAccounts(): void
    {
        Configure::write('debug', false);
        $this->enableCsrfToken();

        foreach (['signed out', 'customer', 'staff'] as $who) {
            $this->cleanup();
            $this->enableCsrfToken();
            if ($who === 'customer') {
                $this->signInAsCustomer();
            } elseif ($who === 'staff') {
                $this->signIn(2, 'staff');
            }

            $this->get('/plumbers/add');
            $this->assertRefused($who . ' GET /plumbers/add');
            $this->post('/plumbers/add', [
                'admin_username' => 'sneaky',
                'admin_email' => 'sneaky@example.com',
                'admin_password' => AdminsFixture::PASSWORD,
                'confirm_password' => AdminsFixture::PASSWORD,
            ]);
            $this->assertRefused($who . ' POST /plumbers/add');
            $this->post('/plumbers/deactivate/2');
            $this->assertRefused($who . ' POST /plumbers/deactivate/2');
        }

        $this->assertNothingChanged();
    }

    public function testACustomerCannotMakeThemselvesAnAdminWhenRegistering(): void
    {
        $this->enableCsrfToken();
        $before = $this->adminCount();

        $this->post('/customers/register', [
            'cust_email' => 'climber@example.com',
            'cust_fname' => 'Sneaky',
            'cust_lname' => 'Customer',
            'cust_phone' => '0412 345 678',
            'cust_address' => '1 Test Street',
            'password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
            'role' => 'admin',
            'admin_id' => 1,
            'admin_username' => 'climber',
            'cust_type' => 'admin',
            'cust_id' => 999,
            'cust_password_hash' => 'not-a-real-hash',
        ]);

        $customers = TableRegistry::getTableLocator()->get('Customers');
        $customer = $customers->find()->where(['cust_email' => 'climber@example.com'])->firstOrFail();
        $this->assertNotSame(999, (int)$customer->cust_id);
        $this->assertNull($customer->cust_type);
        $this->assertTrue(password_verify(AdminsFixture::PASSWORD, $customer->cust_password_hash));
        $this->assertSame($before, $this->adminCount());
        $this->assertSame(0, TableRegistry::getTableLocator()->get('Admins')->find()
            ->where(['admin_username IN' => ['climber', 'climber@example.com']])->count());

        // And as a signed-in customer, none of the office is open to them.
        Configure::write('debug', false);
        $this->cleanup();
        $this->signInAsCustomer((int)$customer->cust_id);
        foreach (['/admins', '/admins/add', '/plumbers', '/job-requests/manage', '/vehicles', '/manager-jobs'] as $url) {
            $this->get($url);
            $this->assertRedirectContains('/login');
        }
    }

    public function testAnExistingSessionStopsWorkingTheMomentItsAccountIsRemovedOrDemoted(): void
    {
        Configure::write('debug', false);
        $accounts = TableRegistry::getTableLocator()->get('Admins');
        $connection = $accounts->getConnection();

        // Demoted from admin to plumber while still signed in as an admin.
        $this->signIn(1, 'admin');
        $this->get('/job-requests/manage');
        $this->assertResponseOk();
        $connection->update('admins', ['role' => 'staff'], ['admin_id' => 1]);
        $this->get('/job-requests/manage');
        $this->assertResponseCode(403);

        // Deactivated while still signed in.
        $connection->update('admins', ['active' => 0], ['admin_id' => 1]);
        $this->get('/plumbers/dashboard');
        $this->assertRedirectContains('/login');

        // Deleted outright while still signed in.
        $this->signIn(2, 'staff');
        $connection->delete('admins', ['admin_id' => 2]);
        $this->get('/plumbers/dashboard');
        $this->assertRedirectContains('/login');
    }

    public function testNoAccountCanBeCreatedOrRemovedWithoutAValidCsrfToken(): void
    {
        Configure::write('debug', false);
        $this->signIn(3, 'manager');
        $newAdmin = [
            'admin_username' => 'sneaky',
            'admin_email' => 'sneaky@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ];

        // A form on another site cannot know the token, so it sends none.
        $this->post('/admins/add', $newAdmin);
        $this->assertResponseCode(403);
        $this->post('/plumbers/add', $newAdmin);
        $this->assertResponseCode(403);
        $this->post('/admins/deactivate/1', ['manager_password' => AdminsFixture::PASSWORD]);
        $this->assertResponseCode(403);
        $this->post('/plumbers/deactivate/2');
        $this->assertResponseCode(403);

        // A token that does not match the one in the cookie is no better.
        $this->cookie('csrfToken', 'cookie-token-value');
        $this->post('/admins/add', $newAdmin + ['_csrfToken' => 'some-other-value']);
        $this->assertResponseCode(403);
        $this->post('/admins/deactivate/1', [
            'manager_password' => AdminsFixture::PASSWORD,
            '_csrfToken' => 'some-other-value',
        ]);
        $this->assertResponseCode(403);

        $this->assertNothingChanged();
    }

    public function testTheSameRequestsWorkForTheManagerWithAValidToken(): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();

        $this->post('/admins/add', [
            'admin_username' => 'legitadmin',
            'admin_email' => 'legitadmin@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertRedirect('/admins');
        $this->assertSame(1, TableRegistry::getTableLocator()->get('Admins')->find()
            ->where(['admin_username' => 'legitadmin'])->count());
    }
}
