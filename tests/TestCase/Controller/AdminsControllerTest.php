<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Fixture\AdminsFixture;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/** Manager-only administration and self-service password changes. */
class AdminsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.LoginRateLimits',
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

    public function testOnlyManagerCanSeeAccountListWithUsernames(): void
    {
        $this->signIn(3, 'manager');
        $this->get('/admins');
        $this->assertResponseOk();
        $this->assertResponseContains('Admin accounts');
        $this->assertResponseContains('hannah');
        $this->assertResponseNotContains(AdminsFixture::PASSWORD);
        $this->assertResponseNotContains('Job requests');

        Configure::write('debug', false);
        $this->signIn(1, 'admin');
        $this->get('/admins');
        $this->assertResponseCode(403);
    }

    public function testPromotedManagerWithOldSessionGetsManagerNavigation(): void
    {
        $this->signIn(3, 'admin');
        $this->get('/admins');

        $this->assertResponseOk();
        $this->assertResponseContains('Admin accounts');
        $this->assertResponseNotContains('Job requests');
    }

    public function testManagerCannotOpenJobManagement(): void
    {
        Configure::write('debug', false);
        $this->signIn(3, 'manager');
        $this->get('/job-requests/manage');
        $this->assertResponseCode(403);
    }

    public function testManagerCreatesActiveAdminWithoutAcceptingPostedRole(): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $this->post('/admins/add', [
            'admin_username' => 'newadmin',
            'admin_email' => 'newadmin@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
            'role' => 'manager',
            'active' => false,
        ]);
        $this->assertRedirect('/admins');

        $created = TableRegistry::getTableLocator()->get('Admins')->find()
            ->where(['admin_username' => 'newadmin'])->firstOrFail();
        $this->assertSame('admin', $created->role);
        $this->assertTrue((bool)$created->active);
        $this->assertTrue(password_verify(AdminsFixture::PASSWORD, $created->admin_password));
    }

    public function testDuplicateAdminUsernameShowsFormError(): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $this->post('/admins/add', [
            'admin_username' => 'hannah',
            'admin_email' => 'someone.new@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('This username is already in use.');
    }

    public function testAdminCannotCreateDeactivateOrDeleteAccounts(): void
    {
        Configure::write('debug', false);
        $this->signIn(1, 'admin');
        $this->enableCsrfToken();
        $this->post('/admins/add', [
            'admin_username' => 'forged',
            'admin_email' => 'forged@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);
        $this->assertResponseCode(403);

        $this->post('/admins/deactivate/2');
        $this->assertResponseCode(403);

        $this->post('/admins/delete/2');
        $this->assertResponseCode(403);
    }

    /**
     * Signed in as staff, not admin. Admins lost this page entirely when the
     * team moved account records to the manager, but plumbers sign in on the
     * staff role and still change their own password here, so the page and
     * this check both still matter.
     *
     * @return void
     */
    public function testPasswordPageHasNoAccountManagementLinks(): void
    {
        $this->signIn(2, 'staff');
        $this->get('/admins/edit/2');

        $this->assertResponseOk();
        $this->assertResponseContains('Current password');
        $this->assertResponseNotContains('Admin accounts');
        $this->assertResponseNotContains('Delete');
    }

    /**
     * An admin cannot open the password page at all, not even their own.
     *
     * "Including their own" was explicit in the team's decision, and this is
     * the one action an admin could otherwise still reach in this controller.
     *
     * Worth stating the consequence here rather than only in the code: an
     * admin who needs a new password now asks the manager for one, so the
     * manager sets or knows it. That follows from the decision, not from a
     * bug, but somebody reading this test later should not have to work it out.
     *
     * @return void
     */
    public function testAdminCannotOpenTheirOwnAccountPage(): void
    {
        Configure::write('debug', false);
        $this->signIn(1, 'admin');
        $this->get('/admins/edit/1');

        $this->assertResponseCode(403);
        $this->assertResponseNotContains('Current password');
    }

    /**
     * And the nav stops offering it to them.
     *
     * @return void
     */
    public function testChangePasswordLinkIsHiddenFromAdmins(): void
    {
        $this->signIn(1, 'admin');
        $this->get('/contact-submissions');

        $this->assertResponseOk();
        $this->assertResponseNotContains('Change password');
    }

    public function testManagerCanDeactivateAndReactivateAdminButNotSelf(): void
    {
        Configure::write('debug', false);
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();

        $this->get('/admins/deactivate/1');
        $this->assertResponseOk();
        $this->assertResponseContains('Enter your own manager password');
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $this->assertTrue((bool)$admins->get(1)->active);

        $this->post('/admins/deactivate/1', ['manager_password' => AdminsFixture::PASSWORD]);
        $this->assertRedirect('/admins');

        $this->assertFalse((bool)$admins->get(1)->active);
        $this->assertSame(1, TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'admin.deactivated', 'user_id' => 3, 'entity_id' => '1'])
            ->count());

        $this->post('/admins/deactivate/3', ['manager_password' => AdminsFixture::PASSWORD]);
        $this->assertResponseCode(403);
        $this->assertTrue((bool)$admins->get(3)->active);

        $this->get('/admins/reactivate/1');
        $this->assertResponseOk();
        $this->assertFalse((bool)$admins->get(1)->active);

        $this->post('/admins/reactivate/1', ['manager_password' => 'wrong-password']);
        $this->assertResponseOk();
        $this->assertFalse((bool)$admins->get(1)->active);

        $this->post('/admins/reactivate/1', ['manager_password' => AdminsFixture::PASSWORD]);
        $this->assertRedirect('/admins');
        $this->assertTrue((bool)$admins->get(1)->active);
    }

    public function testStatusChangeNeedsManagersPasswordNotTargetsPassword(): void
    {
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $manager = $admins->get(3);
        $manager->set('admin_password', 'ManagerOnly#2026');
        $admins->saveOrFail($manager);

        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $this->post('/admins/deactivate/1', ['manager_password' => AdminsFixture::PASSWORD]);
        $this->assertResponseOk();
        $this->assertResponseContains('Your password is incorrect.');
        $this->assertFalse(str_contains((string)$this->_response->getBody(), AdminsFixture::PASSWORD));
        $this->assertTrue((bool)$admins->get(1)->active);

        $this->post('/admins/deactivate/1', ['manager_password' => 'ManagerOnly#2026']);
        $this->assertRedirect('/admins');
        $this->assertFalse((bool)$admins->get(1)->active);
    }

    public function testConfirmationAttemptsAreLimited(): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admins/deactivate/1', ['manager_password' => 'wrong-password']);
            $this->assertResponseOk();
        }

        $this->post('/admins/deactivate/1', ['manager_password' => AdminsFixture::PASSWORD]);
        $this->assertResponseOk();
        $this->assertResponseContains('Too many attempts. Please try again in 15 minutes.');
        $this->assertTrue((bool)TableRegistry::getTableLocator()->get('Admins')->get(1)->active);
    }

    public function testDeactivatedAdminSessionCannotOpenProtectedPage(): void
    {
        Configure::write('debug', false);
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $account = $admins->get(1);
        $account->set('active', false);
        $admins->saveOrFail($account);

        $this->signIn(1, 'admin');
        $this->get('/admins/edit/1');
        $this->assertResponseCode(302);
    }

    public function testChangingPasswordRequiresOldPasswordAndCannotEditAnotherAccount(): void
    {
        Configure::write('debug', false);
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $oldHash = $admins->get(2)->admin_password;
        $this->signIn(2, 'staff');
        $this->enableCsrfToken();

        $this->post('/admins/edit/2', [
            'current_password' => 'wrong-password',
            'admin_password' => 'Different#Secure8',
            'confirm_password' => 'Different#Secure8',
        ]);
        $this->assertResponseOk();
        $this->assertResponseNotContains('wrong-password');
        $this->assertSame($oldHash, $admins->get(2)->admin_password);

        $this->post('/admins/edit/3', [
            'current_password' => AdminsFixture::PASSWORD,
            'admin_password' => 'Different#Secure8',
            'confirm_password' => 'Different#Secure8',
        ]);
        $this->assertResponseCode(403);
        $this->assertSame($oldHash, $admins->get(2)->admin_password);
    }

    public function testSelfPasswordChangeIgnoresForgedAccountFields(): void
    {
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $this->signIn(2, 'staff');
        $this->enableCsrfToken();
        $this->post('/admins/edit/2', [
            'current_password' => AdminsFixture::PASSWORD,
            'admin_password' => 'Different#Secure8',
            'confirm_password' => 'Different#Secure8',
            'admin_username' => 'forged-name',
            'role' => 'manager',
            'active' => false,
        ]);
        $this->assertRedirect('/admins/edit/2');

        $after = $admins->get(2);
        $this->assertSame('staffuser', $after->admin_username);
        $this->assertSame('staff', $after->role);
        $this->assertTrue((bool)$after->active);
        $this->assertTrue(password_verify('Different#Secure8', $after->admin_password));
    }

    public function testNoOneCanPermanentlyDeleteAnAccount(): void
    {
        Configure::write('debug', false);
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $this->post('/admins/delete/1');
        $this->assertResponseCode(403);
        $this->assertNotNull(TableRegistry::getTableLocator()->get('Admins')->get(1));
    }
}
