<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Fixture\AdminsFixture;
use Authentication\IdentityInterface;
use Cake\Core\Configure;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/** Office accounts sign in with an email address, and are created with one. */
class EmailLoginTest extends TestCase
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

    protected function tearDown(): void
    {
        // Back to the domain the fixtures use (see tests/bootstrap.php).
        Configure::write('Mail.officeDomain', 'example.com');
        parent::tearDown();
    }

    private function signInAsManager(): void
    {
        $this->session(['AdminAuth' => ['admin_id' => 3, 'admin_username' => 'manageruser', 'role' => 'manager']]);
    }

    private function signedInIdentity(): ?IdentityInterface
    {
        $identity = $this->_controller?->getRequest()->getAttribute('identity');

        return $identity instanceof IdentityInterface ? $identity : null;
    }

    private function accounts(): Table
    {
        return TableRegistry::getTableLocator()->get('Admins');
    }

    public function testTheLoginPageAsksForAnEmailForEveryRole(): void
    {
        $this->get('/login');

        $this->assertResponseOk();
        $this->assertResponseContains('name="email"');
        $this->assertResponseContains('type="email"');
        $this->assertResponseContains('autocomplete="email"');
        $this->assertResponseContains('>Email</label>');
        $this->assertResponseNotContains('Username');
        $this->assertResponseNotContains('name="username"');
    }

    public function testEveryOfficeRoleSignsInWithItsEmail(): void
    {
        foreach (['hannah@example.com' => 1, 'manageruser@example.com' => 3, 'staffuser@example.com' => 2] as $email => $id) {
            $this->cleanup();
            $this->enableCsrfToken();
            $this->post('/login', ['email' => $email, 'password' => AdminsFixture::PASSWORD]);

            $this->assertSame($id, (int)$this->signedInIdentity()?->offsetGet('admin_id'), $email);
        }
    }

    public function testExistingPlumberWithLegacyUsernameStillSignsInByEmail(): void
    {
        $this->accounts()->updateAll([
            'admin_username' => 'Legacy plumber name with spaces',
        ], ['admin_id' => 2]);
        $this->enableCsrfToken();

        $this->post('/login', [
            'email' => 'staffuser@example.com',
            'password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertSame(2, (int)$this->signedInIdentity()?->offsetGet('admin_id'));
    }

    public function testTheOldUsernameNoLongerSignsAnyoneIn(): void
    {
        foreach (['hannah', 'staffuser', 'manageruser'] as $username) {
            $this->cleanup();
            $this->enableCsrfToken();

            $this->post('/login', ['email' => $username, 'password' => AdminsFixture::PASSWORD]);
            $this->assertNull($this->signedInIdentity(), 'username typed into the email box: ' . $username);

            $this->cleanup();
            $this->enableCsrfToken();
            $this->post('/login', ['username' => $username, 'password' => AdminsFixture::PASSWORD]);
            $this->assertNull($this->signedInIdentity(), 'old username field: ' . $username);
            $this->assertResponseOk();
            $this->assertResponseContains('Either email or password is incorrect.');
        }
    }

    public function testAdminAndPlumberFormsAskForAnEmailAndAUsername(): void
    {
        $this->signInAsManager();

        foreach (['/admins/add', '/plumbers/add'] as $url) {
            $this->get($url);

            $this->assertResponseOk($url);
            $this->assertResponseContains('name="admin_email"', $url);
            $this->assertResponseContains('type="email"', $url);
            $this->assertResponseContains('Email (they sign in with this)', $url);
            $this->assertResponseContains('name="admin_username"', $url);
            $this->assertResponseContains('not used to sign in', $url);
        }
    }

    public function testBadOrMissingEmailsAreRefusedWithAClearMessage(): void
    {
        $this->signInAsManager();
        $this->enableCsrfToken();

        $cases = [
            'not-an-email' => 'Please enter a valid email address',
            'name@' => 'Please enter a valid email address',
            '@example.com' => 'Please enter a valid email address',
            'two words@example.com' => 'Please enter a valid email address',
            str_repeat('a', 250) . '@example.com' => 'at most 254 characters',
            '' => 'Please enter an email address.',
        ];
        foreach (['/admins/add', '/plumbers/add'] as $url) {
            foreach ($cases as $email => $message) {
                $this->post($url, [
                    'admin_username' => 'Someone',
                    'admin_email' => (string)$email,
                    'admin_password' => AdminsFixture::PASSWORD,
                    'confirm_password' => AdminsFixture::PASSWORD,
                ]);

                $this->assertResponseOk($url . ' ' . $email);
                $this->assertResponseContains($message, $url . ' ' . $email);
                $this->assertSame(0, $this->accounts()->find()->where(['admin_username' => 'Someone'])->count());
            }
        }
    }

    public function testAnEmailAlreadyInUseIsRefusedInAnyCase(): void
    {
        $this->signInAsManager();
        $this->enableCsrfToken();

        foreach (['/admins/add', '/plumbers/add'] as $url) {
            foreach (['hannah@example.com', 'HANNAH@Example.com', '  hannah@example.com  '] as $email) {
                $this->post($url, [
                    'admin_username' => 'Copycat',
                    'admin_email' => $email,
                    'admin_password' => AdminsFixture::PASSWORD,
                    'confirm_password' => AdminsFixture::PASSWORD,
                ]);

                $this->assertResponseOk($url . ' ' . $email);
                $this->assertResponseContains('This email address is already in use.', $url . ' ' . $email);
                $this->assertSame(0, $this->accounts()->find()->where(['admin_username' => 'Copycat'])->count());
            }
        }
    }

    public function testAnEmailIsStoredTidyAndTheAccountCanSignInAtOnce(): void
    {
        $this->signInAsManager();
        $this->enableCsrfToken();
        $this->post('/plumbers/add', [
            'admin_username' => 'Pat',
            'admin_email' => '  Pat.Plumber@Example.COM ',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertRedirect('/plumbers');
        $this->assertFlashMessage('Plumber account created. They sign in with pat.plumber@example.com.');
        $account = $this->accounts()->find()->where(['admin_username' => 'Pat'])->firstOrFail();
        $this->assertSame('pat.plumber@example.com', $account->admin_email);

        $this->cleanup();
        $this->enableCsrfToken();
        $this->post('/login', ['email' => 'pat.plumber@example.com', 'password' => AdminsFixture::PASSWORD]);
        $this->assertRedirect('/plumbers/dashboard');
        $this->assertSame((int)$account->admin_id, (int)$this->signedInIdentity()?->offsetGet('admin_id'));
    }

    public function testAnAdminAccountMessageNamesTheSignInEmail(): void
    {
        $this->signInAsManager();
        $this->enableCsrfToken();

        $this->post('/admins/add', [
            'admin_username' => 'Alex',
            'admin_email' => 'alex.admin@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertRedirect('/admins');
        $this->assertFlashMessageContains('They sign in with alex.admin@example.com.');
    }

    public function testTheAccountListsShowEachSignInEmail(): void
    {
        Configure::write('debug', false);
        $this->signInAsManager();

        $this->get('/admins');
        $this->assertResponseOk();
        $this->assertResponseContains('hannah@example.com');
        $this->assertResponseContains('>Email<');

        $this->get('/plumbers');
        $this->assertResponseOk();
        $this->assertResponseContains('staffuser@example.com');
        $this->assertResponseContains('>Email<');
    }

    public function testAnOfficeAccountCannotTakeAnAddressACustomerHas(): void
    {
        $this->signInAsManager();
        $this->enableCsrfToken();

        foreach (['/admins/add', '/plumbers/add'] as $url) {
            $this->post($url, [
                'admin_username' => 'Lookalike',
                'admin_email' => 'Kash@Example.com',
                'admin_password' => AdminsFixture::PASSWORD,
                'confirm_password' => AdminsFixture::PASSWORD,
            ]);

            $this->assertResponseOk($url);
            $this->assertResponseContains('This email address is already in use.', $url);
            $this->assertSame(0, $this->accounts()->find()->where(['admin_username' => 'Lookalike'])->count());
        }
    }

    public function testACustomerCannotRegisterWithAnOfficeAddress(): void
    {
        $this->enableCsrfToken();
        $before = TableRegistry::getTableLocator()->get('Customers')->find()->count();

        $this->post('/customers/register', [
            'cust_email' => 'Hannah@Example.com',
            'cust_fname' => 'Copy',
            'cust_lname' => 'Cat',
            'cust_phone' => '0412 345 678',
            'cust_address' => '1 Test Street',
            'password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('This email address is already registered.');
        $this->assertSame($before, TableRegistry::getTableLocator()->get('Customers')->find()->count());
    }

    public function testOfficeEmailsMustBeOnTheSiteDomain(): void
    {
        Configure::write('Mail.officeDomain', 'u26s2248.iedev.org');
        $this->signInAsManager();
        $this->enableCsrfToken();

        $outside = [
            'someone@gmail.com',
            'someone@example.com',
            'someone@u26s2248.iedev.org.evil.com',
            'someone@evil.com?@u26s2248.iedev.org',
            'u26s2248.iedev.org',
        ];
        foreach (['/admins/add', '/plumbers/add'] as $url) {
            foreach ($outside as $email) {
                $this->post($url, [
                    'admin_username' => 'Outsider',
                    'admin_email' => $email,
                    'admin_password' => AdminsFixture::PASSWORD,
                    'confirm_password' => AdminsFixture::PASSWORD,
                ]);

                $this->assertResponseOk($url . ' ' . $email);
                $this->assertSame(0, $this->accounts()->find()->where(['admin_username' => 'Outsider'])->count(), $email);
            }
        }

        $this->post('/plumbers/add', [
            'admin_username' => 'Outsider',
            'admin_email' => 'someone@gmail.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);
        $this->assertResponseContains('Please use an email address at u26s2248.iedev.org');
    }

    public function testAnAddressOnTheSiteDomainIsAccepted(): void
    {
        Configure::write('Mail.officeDomain', 'u26s2248.iedev.org');
        $this->signInAsManager();
        $this->enableCsrfToken();

        $this->post('/plumbers/add', [
            'admin_username' => 'Insider',
            'admin_email' => ' New.Plumber@U26S2248.IEDEV.ORG ',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertRedirect('/plumbers');
        $this->assertSame(
            'new.plumber@u26s2248.iedev.org',
            $this->accounts()->find()->where(['admin_username' => 'Insider'])->firstOrFail()->admin_email,
        );
    }

    public function testTheFormsSuggestAnAddressOnTheSiteDomain(): void
    {
        Configure::write('Mail.officeDomain', 'u26s2248.iedev.org');
        $this->signInAsManager();

        foreach (['/admins/add', '/plumbers/add'] as $url) {
            $this->get($url);

            $this->assertResponseOk($url);
            $this->assertResponseContains('placeholder="name@u26s2248.iedev.org"', $url);
        }
    }
}
