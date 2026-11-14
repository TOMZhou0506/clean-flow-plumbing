<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Fixture\AdminsFixture;
use Authentication\IdentityInterface;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * The shared sign-in page.
 *
 * The client's feedback was that two login buttons was one too many, so there
 * is now a single page that asks which kind of account you have. These tests
 * cover the part that worries me most about that change: one form now reaches
 * two account tables, and the two must not bleed into each other.
 *
 * @link \App\Controller\LoginController
 */
class LoginControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'app.Admins',
        'app.Customers',
        'app.JobRequests',
    ];

    /**
     * Whoever the request ended up identifying, or null if nobody.
     *
     * I check the request attribute rather than reading the session back.
     * Reading it looks like the obvious thing to do and quietly does not work:
     * the authentication middleware writes the session after the controller
     * has returned, and by the time the test reads it the harness has already
     * closed and reset the CLI session, so every read comes back null whether
     * the login worked or not. An assertion that returns null on success and
     * null on failure proves nothing at all.
     *
     * The identity attribute is set on the request before the controller runs
     * and is still there afterwards, and it is what the rest of the app
     * actually reads, so it is the honest thing to assert on.
     *
     * @return \Authentication\IdentityInterface|null
     */
    private function signedInIdentity(): ?IdentityInterface
    {
        $identity = $this->_controller?->getRequest()->getAttribute('identity');

        return $identity instanceof IdentityInterface ? $identity : null;
    }

    /**
     * One form for all four roles, with no choice of role on it.
     *
     * @return void
     */
    public function testThePageHasNoRoleChoiceAndOnlyOneSetOfCredentials(): void
    {
        $this->get('/login');

        $this->assertResponseOk();
        $this->assertResponseNotContains('Admin / Staff');
        $this->assertResponseNotContains('I am signing in as');
        $this->assertResponseNotContains('name="role"');
        $this->assertResponseNotContains('type="radio"');
        // One email box and one password box for everyone. If this ever
        // grows a second pair we are back to the two-form problem the client
        // complained about, just on a single page.
        $this->assertSame(
            1,
            substr_count((string)$this->_response->getBody(), 'name="email"'),
        );
        $this->assertSame(
            1,
            substr_count((string)$this->_response->getBody(), 'name="password"'),
        );
    }

    /**
     * @return void
     */
    public function testAdminSignsInAndLandsOnTheOfficeDashboard(): void
    {
        $this->enableCsrfToken();
        $this->post('/login', [
            'email' => 'hannah@example.com',
            'password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertRedirect('/job-requests/manage');
        // admin_id, not getIdentifier(). That method reads get('id') and
        // admins has no id column, so it answers null for every real login.
        $this->assertSame(1, (int)$this->signedInIdentity()?->offsetGet('admin_id'));
    }

    public function testManagerLandsOnTheJobOverview(): void
    {
        $this->enableCsrfToken();
        $this->post('/login', [
            'email' => 'manageruser@example.com',
            'password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertRedirect('/manager-jobs');
        $this->assertSame(3, (int)$this->signedInIdentity()?->offsetGet('admin_id'));
    }

    public function testPlumberUsesOfficeLoginButLandsOnOwnDashboard(): void
    {
        $this->enableCsrfToken();
        $this->post('/login', [
            'email' => 'staffuser@example.com',
            'password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertRedirect('/plumbers/dashboard');
        $this->assertSame(2, (int)$this->signedInIdentity()?->offsetGet('admin_id'));
    }

    public function testPromotedManagerWithOldSessionIsSentToTheJobOverview(): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => 3,
            'admin_username' => 'manageruser',
            'role' => 'admin',
        ]]);
        $this->get('/login');

        $this->assertRedirect('/manager-jobs');
    }

    public function testDeactivatedAdminCannotSignIn(): void
    {
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $account = $admins->get(1);
        $account->set('active', false);
        $admins->saveOrFail($account);

        $this->enableCsrfToken();
        $this->post('/login', [
            'email' => 'hannah@example.com',
            'password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertResponseOk();
        $this->assertNull($this->signedInIdentity());
    }

    /**
     * @return void
     */
    public function testCustomerSignsInAndLandsOnTheRequestForm(): void
    {
        $this->enableCsrfToken();
        $this->post('/login', [
            'email' => 'kash@example.com',
            'password' => 'Customer#2026',
        ]);

        $this->assertRedirect('/job-requests/add');
        $this->assertSame(1, (int)$this->signedInIdentity()?->offsetGet('cust_id'));
    }

    public function testCustomerSignInClearsAnEarlierOfficeIdentity(): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => 3,
            'admin_username' => 'manageruser',
            'role' => 'manager',
        ]]);
        $this->enableCsrfToken();
        $this->post('/login', [
            'email' => 'kash@example.com',
            'password' => 'Customer#2026',
        ]);

        $this->assertRedirect('/job-requests/add');
        $this->assertFalse($this->_requestSession->check('AdminAuth'));

        // Carry the resulting browser session into the next request. The test
        // harness otherwise re-seeds the original AdminAuth on every request.
        $this->_session = (array)$this->_requestSession->read();
        $this->get('/manager-jobs');
        $this->assertRedirectContains('/login');
    }

    public function testOfficeSignInClearsAnEarlierCustomerIdentity(): void
    {
        $this->session(['CustomerAuth' => [
            'cust_id' => 1,
            'cust_email' => 'kash@example.com',
        ]]);
        $this->enableCsrfToken();
        $this->post('/login', [
            'email' => 'manageruser@example.com',
            'password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertRedirect('/manager-jobs');
        $this->assertFalse($this->_requestSession->check('CustomerAuth'));

        $this->_session = (array)$this->_requestSession->read();
        $this->get('/job-requests');
        $this->assertRedirectContains('/login');
    }

    public function testAnOldSessionContainingBothKindsOfAccountMustSignInAgain(): void
    {
        $this->session([
            'AdminAuth' => [
                'admin_id' => 3,
                'admin_username' => 'manageruser',
                'role' => 'manager',
            ],
            'CustomerAuth' => [
                'cust_id' => 1,
                'cust_email' => 'kash@example.com',
            ],
        ]);

        $this->get('/manager-jobs');

        $this->assertRedirectContains('/login');
        $this->assertFalse($this->_requestSession->check('AdminAuth'));
        $this->assertFalse($this->_requestSession->check('CustomerAuth'));
    }

    public function testAnOldCustomerAndAdminSessionCannotOpenTheAdminDashboard(): void
    {
        $this->session([
            'AdminAuth' => [
                'admin_id' => 1,
                'admin_username' => 'hannah',
                'role' => 'admin',
            ],
            'CustomerAuth' => [
                'cust_id' => 1,
                'cust_email' => 'kash@example.com',
            ],
        ]);

        $this->get('/job-requests/manage');

        $this->assertRedirectContains('/login');
        $this->assertFalse($this->_requestSession->check('AdminAuth'));
        $this->assertFalse($this->_requestSession->check('CustomerAuth'));
    }

    public function testCustomerIsNotSentBackToAnOfficeUrlAfterSignIn(): void
    {
        $this->enableCsrfToken();
        $this->post('/login?redirect=%2Fmanager-jobs', [
            'email' => 'kash@example.com',
            'password' => 'Customer#2026',
        ]);

        $this->assertRedirect('/job-requests/add');

        $this->session(['CustomerAuth' => [
            'cust_id' => 1,
            'cust_email' => 'kash@example.com',
        ]]);
        $this->get('/login?redirect=%2Fjob-requests%2Fmanage');
        $this->assertRedirect('/job-requests/add');
    }

    public function testOfficeAccountIsNotSentBackToACustomerUrlAfterSignIn(): void
    {
        $this->enableCsrfToken();
        $this->post('/login?redirect=%2Fjob-requests', [
            'email' => 'hannah@example.com',
            'password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertRedirect('/job-requests/manage');
    }

    /**
     * The account decides the role, whatever the form says.
     *
     * Old pages and crafted requests can still post a role. It is ignored: an
     * office address is checked against the office table and any other address
     * against the customer table, so a posted role can neither help nor hurt.
     *
     * @return void
     */
    public function testAPostedRoleIsIgnored(): void
    {
        foreach (['customer', 'admin', 'superuser'] as $role) {
            $this->cleanup();
            $this->enableCsrfToken();
            $this->post('/login', [
                'role' => $role,
                'email' => 'hannah@example.com',
                'password' => AdminsFixture::PASSWORD,
            ]);
            $this->assertRedirect('/job-requests/manage', $role);
            $this->assertSame(1, (int)$this->signedInIdentity()?->offsetGet('admin_id'), $role);

            $this->cleanup();
            $this->enableCsrfToken();
            $this->post('/login', [
                'role' => $role,
                'email' => 'kash@example.com',
                'password' => 'Customer#2026',
            ]);
            $this->assertRedirect('/job-requests/add', $role);
            $this->assertSame(1, (int)$this->signedInIdentity()?->offsetGet('cust_id'), $role);
        }
    }

    /**
     * The two tables must stay separate.
     *
     * An office address is only ever checked against the office table, and any
     * other address only against the customer table. A password from one side
     * is worthless with an address from the other.
     *
     * @return void
     */
    public function testAPasswordFromTheOtherKindOfAccountIsRefused(): void
    {
        $cases = [
            'office address, customer password' => ['hannah@example.com', 'Customer#2026'],
            'customer address, office password' => ['kash@example.com', AdminsFixture::PASSWORD],
        ];
        foreach ($cases as $label => [$email, $password]) {
            $this->cleanup();
            $this->enableCsrfToken();
            $this->post('/login', ['email' => $email, 'password' => $password]);

            $this->assertResponseOk($label);
            $this->assertNull($this->signedInIdentity(), $label);
            $this->assertResponseContains('Either email or password is incorrect.', $label);
        }
    }

    /**
     * An office address that is also a customer address goes to the office.
     *
     * Creating either kind of account refuses an address the other kind holds,
     * so this cannot normally happen. If a row got in some other way, the
     * office account wins and the customer's password must not open anything.
     *
     * @return void
     */
    public function testAnAddressInBothTablesIsAlwaysTheOfficeAccount(): void
    {
        $customers = TableRegistry::getTableLocator()->get('Customers');
        $customers->getConnection()->update('customer', ['cust_email' => 'hannah@example.com'], ['cust_id' => 1]);

        $this->enableCsrfToken();
        $this->post('/login', ['email' => 'hannah@example.com', 'password' => 'Customer#2026']);
        $this->assertNull($this->signedInIdentity());

        $this->cleanup();
        $this->enableCsrfToken();
        $this->post('/login', ['email' => 'hannah@example.com', 'password' => AdminsFixture::PASSWORD]);
        $this->assertSame(1, (int)$this->signedInIdentity()?->offsetGet('admin_id'));
    }

    /**
     * A deactivated office account is refused by the office table.
     *
     * It must not fall through to a customer account sharing its address.
     *
     * @return void
     */
    public function testADeactivatedOfficeAddressDoesNotFallThroughToACustomer(): void
    {
        $customers = TableRegistry::getTableLocator()->get('Customers');
        $customers->getConnection()->update('customer', ['cust_email' => 'hannah@example.com'], ['cust_id' => 1]);
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $admins->getConnection()->update('admins', ['active' => 0], ['admin_id' => 1]);

        $this->enableCsrfToken();
        $this->post('/login', ['email' => 'hannah@example.com', 'password' => 'Customer#2026']);

        $this->assertNull($this->signedInIdentity());
        $this->assertResponseOk();
    }

    /**
     * @return void
     */
    public function testWrongPasswordSaysNothingUseful(): void
    {
        $this->enableCsrfToken();
        $this->post('/login', [
            'email' => 'hannah@example.com',
            'password' => 'not-the-password',
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Either email or password is incorrect.');
        // The message must not reveal whether the account exists. Somebody who
        // can tell "no such user" from "wrong password" can enumerate staff
        // usernames without ever signing in.
        $this->assertResponseNotContains('No such');
        $this->assertResponseNotContains('Unknown user');
    }

    /**
     * Every kind of failure gets exactly the same words, and never a hint of which part was wrong.
     *
     * @return void
     */
    public function testEveryFailureGetsTheSameMessageAndNeverSaysWhichPartWasWrong(): void
    {
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $admins->getConnection()->update('admins', ['active' => 0], ['admin_id' => 2]);

        $cases = [
            'unknown email' => ['nobody-here@example.com', AdminsFixture::PASSWORD],
            'wrong password, office' => ['hannah@example.com', 'not-the-password'],
            'wrong password, customer' => ['kash@example.com', 'not-the-password'],
            'deactivated account' => ['staffuser@example.com', AdminsFixture::PASSWORD],
            'customer email, office password' => ['kash@example.com', AdminsFixture::PASSWORD],
            'office email, customer password' => ['hannah@example.com', 'Customer#2026'],
        ];

        $messages = [];
        foreach ($cases as $label => [$email, $password]) {
            $this->cleanup();
            $this->enableCsrfToken();
            $this->post('/login', ['email' => $email, 'password' => $password]);

            $this->assertResponseOk($label);
            $this->assertNull($this->signedInIdentity(), $label);
            $this->assertResponseContains('Either email or password is incorrect.', $label);
            foreach (['Those details did not match', 'No account', 'no such', 'not found', 'Wrong password', 'Unknown'] as $hint) {
                $this->assertResponseNotContains($hint, $label);
            }
            $messages[$label] = substr_count((string)$this->_response->getBody(), 'Either email or password is incorrect.');
        }

        $this->assertSame(['1'], array_values(array_unique(array_map('strval', $messages))));
    }

    /**
     * After a failed sign in the email stays in the box and the password does not.
     *
     * @return void
     */
    public function testAFailedSignInKeepsTheEmailAndClearsThePassword(): void
    {
        $this->enableCsrfToken();
        $this->post('/login', ['email' => 'hannah@example.com', 'password' => 'a-wrong-password-9']);

        $this->assertResponseOk();
        $this->assertResponseContains('value="hannah@example.com"');
        $this->assertResponseNotContains('a-wrong-password-9');
    }

    /**
     * @return void
     */
    public function testUnknownUsernameGivesTheSameMessage(): void
    {
        $this->enableCsrfToken();
        $this->post('/login', [
            'email' => 'nobody-here@example.com',
            'password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertResponseOk();
        $this->assertResponseContains('Either email or password is incorrect.');
    }

    /**
     * @return void
     */
    public function testSigningInReturnsYouToThePageYouAskedFor(): void
    {
        $this->enableCsrfToken();
        $this->post('/login?redirect=%2Faudit-logs', [
            'email' => 'hannah@example.com',
            'password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertRedirect('/audit-logs');
    }

    /**
     * Logging out lands on the landing page, same as the customer logout.
     *
     * It used to return to /admins/login, which nothing in the site links to
     * any more. Pointing it at /login instead broke the page, and the home
     * page is the better destination regardless: somebody who has just signed
     * out is finished, not looking to sign straight back in.
     *
     * @return void
     */
    public function testAdminLogoutReturnsToTheLandingPage(): void
    {
        $this->session([
            'AdminAuth' => [
                'admin_id' => 1,
                'admin_username' => 'hannah',
                'role' => 'admin',
            ],
        ]);

        $this->enableCsrfToken();
        $this->post('/admins/logout');

        $this->assertRedirect('/');
    }

    /**
     * Logging out is POST only.
     *
     * On a GET, any page could log an admin out with a hidden image tag, and a
     * prefetching browser could do it by accident.
     *
     * @return void
     */
    public function testAdminLogoutRejectsGet(): void
    {
        $this->session([
            'AdminAuth' => [
                'admin_id' => 1,
                'admin_username' => 'hannah',
                'role' => 'admin',
            ],
        ]);

        $this->get('/admins/logout');

        $this->assertResponseCode(405);
    }

    /**
     * Logging out while already logged out is a no-op, not an error.
     *
     * This is the loop that made logout look broken. logout used to require an
     * identity, so a second click bounced to /login?redirect=/admins/logout.
     * Signing in then honoured that redirect and logged the person straight
     * back out.
     *
     * @return void
     */
    public function testLoggingOutWhenAlreadyLoggedOutJustGoesHome(): void
    {
        $this->enableCsrfToken();
        $this->post('/admins/logout');

        $this->assertRedirect('/');
    }

    /**
     * A redirect back to a logout URL is refused after signing in.
     *
     * The other half of the same loop, guarded at the receiving end. The
     * parameter is visitor controlled, so this is not only reachable by
     * somebody double clicking.
     *
     * @return void
     */
    public function testSigningInIgnoresARedirectBackToLogout(): void
    {
        $this->enableCsrfToken();
        $this->post('/login?redirect=%2Fadmins%2Flogout', [
            'email' => 'hannah@example.com',
            'password' => AdminsFixture::PASSWORD,
        ]);

        $this->assertRedirect('/job-requests/manage');
    }

    // I started to add a second test here asserting that logging out really
    // ends the session, by logging out and then requesting an admin page. It
    // does not work and would have been misleading: IntegrationTestTrait
    // re-seeds whatever $this->session() was given onto every request in the
    // test, so the second request arrives holding AdminAuth again no matter
    // what the first one did. Clearing the identity is the authentication
    // plugin's own code and the customer logout path already covers it.

    /**
     * @return void
     */
    public function testAnAdminAlreadySignedInIsNotAskedAgain(): void
    {
        $this->session([
            'AdminAuth' => [
                'admin_id' => 1,
                'admin_username' => 'hannah',
                'role' => 'admin',
            ],
        ]);

        $this->get('/login');

        $this->assertRedirect('/job-requests/manage');
    }

    /**
     * @return void
     */
    public function testACustomerAlreadySignedInIsNotAskedAgain(): void
    {
        $this->session([
            'CustomerAuth' => [
                'cust_id' => 1,
                'cust_email' => 'kash@example.com',
                'cust_fname' => 'Kash',
                'cust_lname' => 'Patel',
            ],
        ]);

        $this->get('/login');

        // No role is posted on a GET, so both session authenticators are
        // loaded and the customer one matches. The admin default would send
        // them to /job-requests/manage, which they cannot open.
        $this->assertRedirect('/job-requests/add');
    }

    /**
     * Old bookmarks and cached links still reach the one sign-in page.
     *
     * @return void
     */
    public function testOldLoginAddressesStillLandOnTheSinglePage(): void
    {
        foreach (['/admins/login', '/customers/login'] as $old) {
            $this->get($old);

            $this->assertRedirect('/login', $old);
        }
    }

    /**
     * Every role is sent back to the page it asked for, not just to its own dashboard.
     *
     * @return void
     */
    public function testEveryRoleIsReturnedToTheProtectedPageItAskedFor(): void
    {
        $cases = [
            'manager' => ['manageruser@example.com', AdminsFixture::PASSWORD, '/audit-logs'],
            'admin' => ['hannah@example.com', AdminsFixture::PASSWORD, '/vehicles'],
            'plumber' => ['staffuser@example.com', AdminsFixture::PASSWORD, '/plumbers/availability'],
            'customer' => ['kash@example.com', 'Customer#2026', '/job-requests'],
        ];

        foreach ($cases as $who => [$email, $password, $page]) {
            $this->cleanup();
            $this->enableCsrfToken();
            $this->post('/login?redirect=' . rawurlencode($page), ['email' => $email, 'password' => $password]);

            $this->assertRedirect($page, $who);
        }
    }

    /**
     * Whoever signs out ends on the landing page and is not sent back into a dashboard.
     *
     * @return void
     */
    public function testEveryOfficeRoleSigningOutEndsOnTheLandingPage(): void
    {
        $sessions = [
            'manager' => ['admin_id' => 3, 'admin_username' => 'manageruser', 'role' => 'manager'],
            'plumber' => ['admin_id' => 2, 'admin_username' => 'staffuser', 'role' => 'staff'],
        ];

        foreach ($sessions as $who => $identity) {
            $this->cleanup();
            $this->session(['AdminAuth' => $identity]);
            $this->enableCsrfToken();
            $this->post('/admins/logout');

            $this->assertRedirect('/', $who);
        }
    }

    /**
     * A failed sign in takes about as long whether or not the address has an account.
     *
     * Without the extra hashing for unknown addresses it took roughly half as long, which let
     * somebody tell from outside which email addresses are registered. The check is lenient on
     * purpose, so a slow machine does not make it fail: it only asks that an unknown address is
     * not much quicker than a real account with a wrong password.
     *
     * @return void
     */
    public function testAnUnknownAddressIsNotMuchQuickerThanARealAccount(): void
    {
        $median = function (string $email): float {
            $times = [];
            for ($i = 0; $i < 3; $i++) {
                $this->cleanup();
                $this->enableCsrfToken();
                $start = microtime(true);
                $this->post('/login', ['email' => $email, 'password' => 'Not#The9Password']);
                $times[] = microtime(true) - $start;
            }
            sort($times);

            return $times[1];
        };

        $unknown = $median('nobody-at-all@example.com');
        $real = $median('hannah@example.com');

        $this->assertGreaterThan($real * 0.6, $unknown, sprintf('unknown %.3fs, real %.3fs', $unknown, $real));
    }
}
