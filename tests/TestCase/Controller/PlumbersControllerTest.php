<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Fixture\AdminsFixture;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/** Plumber account management and plumber-only workspace. */
class PlumbersControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
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

    public function testOnlyAdminAndManagerCanListAndCreatePlumbers(): void
    {
        foreach ([[1, 'admin'], [3, 'manager']] as [$id, $role]) {
            $this->signIn($id, $role);
            $this->get('/plumbers');
            $this->assertResponseOk($role);
            $this->assertResponseContains('staffuser');
            $this->assertResponseNotContains('manageruser');
            $this->assertResponseNotContains(AdminsFixture::PASSWORD);
            $this->get('/plumbers/add');
            $this->assertResponseOk($role);
        }

        Configure::write('debug', false);
        $this->signIn(2, 'staff');
        $this->get('/plumbers');
        $this->assertResponseCode(403);
        $this->get('/plumbers/add');
        $this->assertResponseCode(403);
    }

    public function testAdminCreatesStaffAccountWithoutAcceptingPostedRole(): void
    {
        $this->signIn(1, 'admin');
        $this->enableCsrfToken();
        $this->post('/plumbers/add', [
            'admin_username' => 'newplumber',
            'admin_email' => 'newplumber@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
            'role' => 'manager',
            'active' => false,
        ]);
        $this->assertRedirect('/plumbers');

        $account = TableRegistry::getTableLocator()->get('Admins')->find()
            ->where(['admin_username' => 'newplumber'])->firstOrFail();
        $this->assertSame('staff', $account->role);
        $this->assertTrue((bool)$account->active);
        $this->assertTrue(password_verify(AdminsFixture::PASSWORD, $account->admin_password));
        $this->assertSame(1, TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'plumber.created', 'user_id' => 1, 'entity_id' => (string)$account->admin_id])
            ->count());
    }

    public function testAdminCanDeactivateAndReactivateOnlyPlumberAccounts(): void
    {
        $this->signIn(1, 'admin');
        $this->enableCsrfToken();
        $this->post('/plumbers/deactivate/2');
        $this->assertRedirect('/plumbers');

        $accounts = TableRegistry::getTableLocator()->get('Admins');
        $this->assertFalse((bool)$accounts->get(2)->active);

        Configure::write('debug', false);
        $this->post('/plumbers/deactivate/3');
        $this->assertResponseCode(403);
        $this->assertTrue((bool)$accounts->get(3)->active);

        $this->post('/plumbers/reactivate/2');
        $this->assertRedirect('/plumbers');
        $this->assertTrue((bool)$accounts->get(2)->active);
    }

    public function testPlumberDashboardAndPasswordChangeAreSelfServiceOnly(): void
    {
        $this->signIn(2, 'staff');
        $this->get('/plumbers/dashboard');
        $this->assertResponseOk();
        $this->assertResponseContains('Plumber dashboard');
        $this->assertResponseContains('staffuser');
        $this->assertResponseNotContains('Contact enquiries');

        $this->get('/admins/edit/2');
        $this->assertResponseOk();
        $this->assertResponseContains('Current password');

        $this->enableCsrfToken();
        $this->post('/admins/edit/2', [
            'current_password' => 'wrong-password',
            'admin_password' => 'AnotherGood#2026',
            'confirm_password' => 'AnotherGood#2026',
        ]);
        $this->assertResponseOk();
        $account = TableRegistry::getTableLocator()->get('Admins')->get(2);
        $this->assertTrue(password_verify(AdminsFixture::PASSWORD, $account->admin_password));

        $this->post('/admins/edit/2', [
            'current_password' => AdminsFixture::PASSWORD,
            'admin_password' => 'AnotherGood#2026',
            'confirm_password' => 'AnotherGood#2026',
            'role' => 'admin',
        ]);
        $this->assertRedirect('/admins/edit/2');
        $account = TableRegistry::getTableLocator()->get('Admins')->get(2);
        $this->assertTrue(password_verify('AnotherGood#2026', $account->admin_password));
        $this->assertSame('staff', $account->role);

        Configure::write('debug', false);
        $this->get('/admins/edit/1');
        $this->assertResponseCode(403);
        $this->get('/job-requests/manage');
        $this->assertResponseCode(403);
        $this->signIn(1, 'admin');
        $this->get('/plumbers/dashboard');
        $this->assertResponseCode(403);
    }

    public function testDeactivatedPlumberSessionCannotUseDashboard(): void
    {
        Configure::write('debug', false);
        $accounts = TableRegistry::getTableLocator()->get('Admins');
        $plumber = $accounts->get(2);
        $plumber->set('active', false);
        $accounts->saveOrFail($plumber);

        $this->signIn(2, 'staff');
        $this->get('/plumbers/dashboard');
        $this->assertResponseCode(302);
    }
}
