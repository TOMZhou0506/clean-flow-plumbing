<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Fixture\AdminsFixture;
use Authentication\IdentityInterface;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/** US 11.2: the manager creates and removes staff and admin accounts, from sign-in to history. */
class ManagerAccountLifecycleTest extends TestCase
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

    private function signIn(int $id, string $role, string $name = 'someone'): void
    {
        $this->session(['AdminAuth' => ['admin_id' => $id, 'admin_username' => $name, 'role' => $role]]);
    }

    private function signedInIdentity(): ?IdentityInterface
    {
        $identity = $this->_controller?->getRequest()->getAttribute('identity');

        return $identity instanceof IdentityInterface ? $identity : null;
    }

    /** Submits the sign-in form with nobody signed in. */
    private function signInWithTheForm(string $email, string $password): void
    {
        // Forget the manager who created the account. session() merges rather than
        // replaces, so without this the form would be posted from a signed-in session.
        $this->cleanup();
        $this->enableCsrfToken();
        $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    private function accountId(string $username): int
    {
        return (int)TableRegistry::getTableLocator()->get('Admins')->find()
            ->where(['admin_username' => $username])->firstOrFail()->admin_id;
    }

    public function testAStaffAccountTheManagerCreatesCanSignInAndOnlyUsePlumberPages(): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $this->post('/plumbers/add', [
            'admin_username' => 'freshplumber',
            'admin_email' => 'freshplumber@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);
        $this->assertRedirect('/plumbers');
        $id = $this->accountId('freshplumber');
        $this->assertSame('staff', TableRegistry::getTableLocator()->get('Admins')->get($id)->role);

        $this->signInWithTheForm('freshplumber@example.com', AdminsFixture::PASSWORD);
        $this->assertRedirect('/plumbers/dashboard');
        $this->assertSame($id, (int)$this->signedInIdentity()?->offsetGet('admin_id'));

        Configure::write('debug', false);
        $this->signIn($id, 'staff', 'freshplumber');
        $this->get('/plumbers/dashboard');
        $this->assertResponseOk();
        foreach (['/plumbers', '/job-requests/manage', '/admins', '/vehicles', '/manager-jobs'] as $url) {
            $this->get($url);
            $this->assertResponseCode(403, $url);
        }
    }

    public function testAnAdminAccountTheManagerCreatesCanSignInAndOnlyUseOfficePages(): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $this->post('/admins/add', [
            'admin_username' => 'freshadmin',
            'admin_email' => 'freshadmin@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);
        $this->assertRedirect('/admins');
        $id = $this->accountId('freshadmin');
        $this->assertSame('admin', TableRegistry::getTableLocator()->get('Admins')->get($id)->role);

        $this->signInWithTheForm('freshadmin@example.com', AdminsFixture::PASSWORD);
        $this->assertRedirect('/job-requests/manage');
        $this->assertSame($id, (int)$this->signedInIdentity()?->offsetGet('admin_id'));

        Configure::write('debug', false);
        $this->signIn($id, 'admin', 'freshadmin');
        foreach (['/job-requests/manage', '/plumbers', '/vehicles'] as $url) {
            $this->get($url);
            $this->assertResponseOk($url);
        }
        foreach (['/admins', '/admins/add', '/plumbers/dashboard', '/manager-jobs'] as $url) {
            $this->get($url);
            $this->assertResponseCode(403, $url);
        }
    }

    public function testWeakPasswordsAreRefusedForAdminAccountsToo(): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();

        foreach (['short1!', 'alllowercase1!', 'NoNumbersHere!', 'NoSpecial123', 'password123', 'Sh0rt!'] as $weak) {
            $this->post('/admins/add', [
                'admin_username' => 'weakadmin',
                'admin_email' => 'weakadmin@example.com',
                'admin_password' => $weak,
                'confirm_password' => $weak,
            ]);

            $this->assertResponseOk($weak);
            $this->assertResponseContains('could not be saved');
            $this->assertSame(0, TableRegistry::getTableLocator()->get('Admins')->find()
                ->where(['admin_username' => 'weakadmin'])->count(), $weak);
        }
    }

    public function testARemovedStaffOrAdminAccountCanNoLongerSignInOrUseAnOldSession(): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $this->post('/plumbers/deactivate/2');
        $this->assertRedirect('/plumbers');
        $this->post('/admins/deactivate/1', ['manager_password' => AdminsFixture::PASSWORD]);
        $this->assertRedirect('/admins');

        foreach (['staffuser@example.com', 'hannah@example.com'] as $email) {
            $this->signInWithTheForm($email, AdminsFixture::PASSWORD);
            $this->assertResponseOk($email);
            $this->assertResponseContains('Either email or password is incorrect.');
            $this->assertNull($this->signedInIdentity(), $email);
        }

        Configure::write('debug', false);
        $this->signIn(2, 'staff', 'staffuser');
        $this->get('/plumbers/dashboard');
        $this->assertRedirectContains('/login');
        $this->signIn(1, 'admin', 'hannah');
        $this->get('/job-requests/manage');
        $this->assertRedirectContains('/login');
    }

    public function testRemovingAPlumberKeepsTheirPastAllocationsOnRecord(): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get(1);
        $job->set('status', 'completed');
        $jobs->saveOrFail($job);
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $assignments->saveOrFail($assignments->newEntity([
            'job_request_id' => 1,
            'plumber_admin_id' => 2,
            'assigned_by' => 1,
            'scheduled_start' => '2026-10-01 23:00:00',
            'scheduled_end' => '2026-10-02 01:00:00',
        ]));

        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $this->post('/plumbers/deactivate/2');
        $this->assertRedirect('/plumbers');

        $account = TableRegistry::getTableLocator()->get('Admins')->get(2);
        $this->assertFalse((bool)$account->active);
        $this->assertSame('staffuser', $account->admin_username);
        $this->assertSame(1, $assignments->find()->where(['job_request_id' => 1, 'plumber_admin_id' => 2])->count());
        $this->assertSame('completed', $jobs->get(1)->status);

        $this->get('/manager-jobs/view/1');
        $this->assertResponseOk();
        $this->assertResponseContains('staffuser');
    }

    public function testAPlumberWithWorkStillToDoCannotBeRemoved(): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get(1);
        $job->set('status', 'assigned');
        $jobs->saveOrFail($job);
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $assignments->saveOrFail($assignments->newEntity([
            'job_request_id' => 1,
            'plumber_admin_id' => 2,
            'assigned_by' => 1,
            'scheduled_start' => '2030-10-01 23:00:00',
            'scheduled_end' => '2030-10-02 01:00:00',
        ]));

        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $this->post('/plumbers/deactivate/2');

        $this->assertRedirect('/plumbers');
        $this->assertFlashMessage(
            'This plumber still has assigned work. Resolve those jobs before deactivating the account.',
        );
        $this->assertTrue((bool)TableRegistry::getTableLocator()->get('Admins')->get(2)->active);
    }

    public function testTheManagerCannotRemoveTheirOwnAccountByAnyRoute(): void
    {
        Configure::write('debug', false);
        $this->signIn(3, 'manager', 'manageruser');
        $this->enableCsrfToken();

        $this->post('/admins/deactivate/3', ['manager_password' => AdminsFixture::PASSWORD]);
        $this->assertResponseCode(403);
        $this->post('/plumbers/deactivate/3');
        $this->assertResponseCode(403);
        $this->post('/admins/delete/3');
        $this->assertResponseCode(403);
        $this->get('/admins/deactivate/3');
        $this->assertResponseCode(403);

        $this->assertTrue((bool)TableRegistry::getTableLocator()->get('Admins')->get(3)->active);
        $this->assertSame(1, TableRegistry::getTableLocator()->get('Admins')->find()
            ->where(['admin_username' => 'manageruser'])->count());
    }
}
