<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Fixture\AdminsFixture;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** The manager looks after plumber accounts too, without gaining anything else. */
class PlumbersManagerAccessTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
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

    public function testManagerCreatesAStaffAccountWithoutAcceptingPostedRoleOrStatus(): void
    {
        $this->signIn(3, 'manager');
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
            ->where(['action' => 'plumber.created', 'user_id' => 3, 'entity_id' => (string)$account->admin_id])
            ->count());
    }

    public function testManagerCannotCreateAPlumberWithAWeakPassword(): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();

        foreach (['short1!', 'alllowercase1!', 'NoNumbersHere!', 'NoSpecial123'] as $weak) {
            $this->post('/plumbers/add', [
                'admin_username' => 'weakplumber',
                'admin_email' => 'weakplumber@example.com',
                'admin_password' => $weak,
                'confirm_password' => $weak,
            ]);

            $this->assertResponseOk($weak);
            $this->assertResponseContains('could not be saved');
            $this->assertSame(0, TableRegistry::getTableLocator()->get('Admins')->find()
                ->where(['admin_username' => 'weakplumber'])->count(), $weak);
        }
    }

    #[DataProvider('invalidPlumberUsernames')]
    public function testNewPlumberUsernameRules(string $username, string $message): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();

        $this->post('/plumbers/add', [
            'admin_username' => $username,
            'admin_email' => 'candidate@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains($message);
        $this->assertSame(0, TableRegistry::getTableLocator()->get('Admins')->find()
            ->where(['admin_email' => 'candidate@example.com'])->count());
    }

    public static function invalidPlumberUsernames(): iterable
    {
        $length = 'Username must be between 3 and 32 characters.';
        $characters = 'Username may contain only English letters, numbers, underscores, and hyphens.';
        $reserved = 'The usernames admin, manager, and root are reserved.';

        yield 'too short' => ['ab', $length];
        yield 'too long' => [str_repeat('a', 33), $length];
        yield 'space' => ['plumber name', $characters];
        yield 'punctuation' => ['plumber!', $characters];
        yield 'non-ASCII letter' => ['plúmber', $characters];
        yield 'admin' => ['ADMIN', $reserved];
        yield 'manager' => ['Manager', $reserved];
        yield 'root' => ['root', $reserved];
    }

    public function testNewPlumberUsernameBoundariesAndUniqueness(): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $accounts = TableRegistry::getTableLocator()->get('Admins');

        foreach (['a_1', str_repeat('a', 31) . '-'] as $index => $username) {
            $this->post('/plumbers/add', [
                'admin_username' => $username,
                'admin_email' => "plumber{$index}@example.com",
                'admin_password' => AdminsFixture::PASSWORD,
                'confirm_password' => AdminsFixture::PASSWORD,
            ]);
            $this->assertRedirect('/plumbers');
            $this->assertSame(1, $accounts->find()->where(['admin_username' => $username])->count());
        }

        $this->post('/plumbers/add', [
            'admin_username' => 'staffuser',
            'admin_email' => 'duplicate@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('This username is already in use.');
        $this->assertSame(0, $accounts->find()->where(['admin_email' => 'duplicate@example.com'])->count());

        $usernameIndex = $accounts->getSchema()->getConstraint('uq_admins_username');
        $this->assertSame('unique', $usernameIndex['type'] ?? null);
    }

    public function testManagerCanDeactivateAndReactivateAPlumberButNoOtherRole(): void
    {
        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $accounts = TableRegistry::getTableLocator()->get('Admins');

        $this->post('/plumbers/deactivate/2');
        $this->assertRedirect('/plumbers');
        $this->assertFalse((bool)$accounts->get(2)->active);

        $this->post('/plumbers/reactivate/2');
        $this->assertRedirect('/plumbers');
        $this->assertTrue((bool)$accounts->get(2)->active);

        Configure::write('debug', false);
        foreach ([1, 3] as $notAPlumber) {
            $this->post('/plumbers/deactivate/' . $notAPlumber);
            $this->assertResponseCode(403);
            $this->assertTrue((bool)$accounts->get($notAPlumber)->active, 'account ' . $notAPlumber);
        }
    }

    public function testManagerSeesAPlumbersEntryInTheSidebar(): void
    {
        $this->signIn(3, 'manager');

        $this->get('/manager-jobs');

        $this->assertResponseOk();
        $this->assertResponseContains('href="/plumbers"');
        $this->assertResponseContains('Admin accounts');
    }

    public function testStaffAndSignedOutVisitorsCannotUseThePlumberAdministration(): void
    {
        Configure::write('debug', false);
        $this->enableCsrfToken();
        $accounts = TableRegistry::getTableLocator()->get('Admins');

        $this->get('/plumbers');
        $this->assertRedirectContains('/login');
        $this->post('/plumbers/add', ['admin_username' => 'sneaky']);
        $this->assertRedirectContains('/login');

        $this->signIn(2, 'staff');
        $this->get('/plumbers');
        $this->assertResponseCode(403);
        $this->get('/plumbers/add');
        $this->assertResponseCode(403);
        $this->post('/plumbers/add', [
            'admin_username' => 'sneaky',
            'admin_email' => 'sneaky@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);
        $this->assertResponseCode(403);
        $this->post('/plumbers/deactivate/2');
        $this->assertResponseCode(403);

        $this->assertSame(0, $accounts->find()->where(['admin_username' => 'sneaky'])->count());
        $this->assertTrue((bool)$accounts->get(2)->active);
    }

    public function testTheManagerStillCannotUsePlumberOnlyOrJobManagementPages(): void
    {
        Configure::write('debug', false);
        $this->signIn(3, 'manager');

        foreach (['/plumbers/dashboard', '/plumbers/jobs', '/plumbers/availability', '/job-requests/manage', '/vehicles'] as $url) {
            $this->get($url);
            $this->assertResponseCode(403, $url);
        }
    }
}
