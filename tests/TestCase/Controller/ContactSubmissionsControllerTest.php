<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\Fixture\AdminsFixture;
use Cake\Core\Configure;
use Cake\Http\Session;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * US 2.4: as Hannah, I want to view newly submitted job requests.
 *
 * Every test below maps to one of the acceptance test cases or security
 * checks written on the story, and the method names say which. I wrote them
 * that way so the mapping can be read off a failing run rather than looked up
 * in a document.
 */
class ContactSubmissionsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.ContactSubmissions',
        'app.AuditLogs',
    ];

    /**
     * Signs in as an account with the admin role.
     *
     * The session shape has to match what the Authentication plugin writes on
     * a real login, including `role`, because that is the field
     * AppController::requireAdmin() reads.
     *
     * @return void
     */
    protected function loginAsAdmin(): void
    {
        $this->session([
            // No 'id' key. Admins has admin_id and no id column, so a real
            // login never produces one. See AppController::currentAdminId().
            'AdminAuth' => [
                'admin_id' => 1,
                'admin_username' => 'hannah',
                'role' => 'admin',
            ],
        ]);
    }

    /**
     * Signs in as an account without the admin role.
     *
     * @return void
     */
    protected function loginAsStaff(): void
    {
        $this->session([
            'AdminAuth' => [
                'admin_id' => 2,
                'admin_username' => 'staffuser',
                'role' => 'staff',
            ],
        ]);
    }

    /**
     * Renders errors the way production does.
     *
     * With debug on, CakePHP's error page dumps every stack frame's arguments
     * four levels deep, which reaches the loaded fixture records. An access
     * control test would then "pass" while the response body contained the
     * customer data the test is supposed to prove was withheld.
     *
     * @return void
     */
    protected function productionErrors(): void
    {
        // Turning debug off is the whole job. I originally also called
        // useHttpServer() and disableErrorHandlerMiddleware() here, which was
        // wrong twice over: useHttpServer() was removed in CakePHP 5, and
        // disabling the error handler middleware would have let the exception
        // escape as a PHP error instead of becoming the 403 response these
        // tests are asserting on.
        Configure::write('debug', false);
    }

    // -----------------------------------------------------------------------
    // Test case 1: all submitted requests are visible, newest first
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testAdminSeesAllRequestsNewestFirst(): void
    {
        $this->loginAsAdmin();
        $this->get('/contact-submissions');

        $this->assertResponseOk();

        // Fixture submitted_at values, newest to oldest, are Marcus
        // (16 Aug 08:12), Priya (16 Aug 09:47) then Tom (15 Aug 14:03).
        // Ordering by name would put Marcus first too, so I assert on the
        // actual pair that distinguishes date ordering from alphabetical.
        $body = (string)$this->_response->getBody();
        $priya = strpos($body, 'Priya Raghavan');
        $marcus = strpos($body, 'Marcus Bell');
        $tom = strpos($body, 'Tom Whitfield');

        $this->assertNotFalse($priya, 'Priya should be listed.');
        $this->assertNotFalse($marcus, 'Marcus should be listed.');
        $this->assertNotFalse($tom, 'Tom should be listed.');

        $this->assertLessThan(
            $marcus,
            $priya,
            'Priya was submitted later than Marcus, so she should appear first.',
        );
        $this->assertLessThan(
            $tom,
            $marcus,
            'Tom is the oldest submission and should appear last.',
        );
    }

    // -----------------------------------------------------------------------
    // Test case 2: clicking into a request shows full details
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testAdminSeesFullDetailOfOneRequest(): void
    {
        $this->loginAsAdmin();
        $this->get('/contact-submissions/view/1');

        $this->assertResponseOk();
        $this->assertResponseContains('Marcus Bell');
        $this->assertResponseContains('marcus.bell@example.com');
        $this->assertResponseContains('0412 555 013');
        $this->assertResponseContains('Burst pipe under kitchen sink');
        // The list page only shows a truncated preview, so asserting on the
        // tail of the message proves this is the full record.
        $this->assertResponseContains('the shutoff valve is seized');
    }

    // -----------------------------------------------------------------------
    // Test case 3 and security check 1: logged out is sent to the login page
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testLoggedOutIsRedirectedToLoginAndSeesNoCustomerData(): void
    {
        $this->productionErrors();
        $this->get('/contact-submissions');

        // The shared sign-in page. Everything funnels to /login now, which
        // asks whether you are office staff or a customer.
        $this->assertRedirectContains('/login');

        // The redirect is only half the check. What actually matters is that
        // no customer data left the building on the way out.
        $this->assertResponseNotContains('Marcus Bell');
        $this->assertResponseNotContains('marcus.bell@example.com');
    }

    /**
     * The detail page is a separate route, so it needs its own check. A guard
     * on the list that someone forgot to put on the detail view would leak
     * one record at a time to anyone guessing an id.
     *
     * @return void
     */
    public function testLoggedOutCannotOpenARequestDirectly(): void
    {
        $this->productionErrors();
        $this->get('/contact-submissions/view/1');

        $this->assertRedirectContains('/login');
        $this->assertResponseNotContains('Marcus Bell');
    }

    // -----------------------------------------------------------------------
    // Test case 4 and security check 2: a non-admin account is denied
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testNonAdminAccountIsDeniedTheRequestsList(): void
    {
        $this->productionErrors();
        $this->loginAsStaff();
        $this->get('/contact-submissions');

        $this->assertResponseCode(403);
        $this->assertResponseNotContains('Marcus Bell');
        $this->assertResponseNotContains('marcus.bell@example.com');
    }

    /**
     * @return void
     */
    public function testNonAdminAccountIsDeniedASingleRequest(): void
    {
        $this->productionErrors();
        $this->loginAsStaff();
        $this->get('/contact-submissions/view/1');

        $this->assertResponseCode(403);
        $this->assertResponseNotContains('Marcus Bell');
    }

    /**
     * The role check has to be a server-side decision, not a UI one. Hiding
     * the link is not access control: this proves a staff account cannot
     * change data by posting straight to the action.
     *
     * @return void
     */
    public function testNonAdminCannotChangeStatusByPostingDirectly(): void
    {
        $this->productionErrors();
        $this->loginAsStaff();
        $this->enableCsrfToken();
        $this->post('/contact-submissions/change-status/1', ['status' => 'archived']);

        $this->assertResponseCode(403);

        $submissions = TableRegistry::getTableLocator()->get('ContactSubmissions');
        $this->assertSame(
            'new',
            $submissions->get(1)->status,
            'A staff account must not be able to change a request.',
        );
    }

    /**
     * Same reasoning for the CSV export, which is the bulk extraction path and
     * would hand over every record at once.
     *
     * @return void
     */
    public function testNonAdminCannotExportEveryRequest(): void
    {
        $this->productionErrors();
        $this->loginAsStaff();
        $this->get('/contact-submissions/export');

        $this->assertResponseCode(403);
        $this->assertResponseNotContains('marcus.bell@example.com');
    }

    // -----------------------------------------------------------------------
    // Test case 5 and security check 3: the session actually expires
    // -----------------------------------------------------------------------

    /**
     * Confirms the timeout is configured and finite.
     *
     * The full idle-expiry behaviour belongs to CakePHP's Session class,
     * which has its own tests upstream; what I need to know is that we have
     * given it a value, because the default is null and null never expires.
     *
     * @return void
     */
    public function testSessionTimeoutIsConfigured(): void
    {
        $timeout = Configure::read('Session.timeout');

        $this->assertNotNull(
            $timeout,
            'Session.timeout is unset, so an idle admin session never expires.',
        );
        $this->assertGreaterThan(0, (int)$timeout);
        $this->assertLessThanOrEqual(
            60,
            (int)$timeout,
            'An idle window longer than an hour defeats the point of having one.',
        );
    }

    /**
     * The configured timeout reaches the session layer.
     *
     * I first wrote this as an end-to-end test: plant an old Config.time in
     * the session, request the dashboard, assert the customer data is not
     * returned. It failed, and the reason is worth writing down rather than
     * quietly deleting.
     *
     * Cake\Http\Session::start() returns early when PHP_SAPI is 'cli'
     * (Session.php line 348) and so never reaches the _timedOut() check
     * sixteen lines below it. PHPUnit runs as CLI. Idle expiry therefore
     * cannot fire in an integration test no matter how the session is
     * arranged, and a test that appeared to prove it would only be proving
     * something else.
     *
     * So this asserts the part that is genuinely verifiable here: our
     * configured timeout is picked up and turned into a finite session
     * lifetime. The expiry behaviour itself belongs to CakePHP and is covered
     * upstream.
     *
     * The story's own wording, "leave the admin session idle past the timeout
     * period, then try an action", describes a manual check. It is recorded
     * as one in docs/ACCEPTANCE-CRITERIA.md and has to be run against the
     * real server, not asserted here.
     *
     * @return void
     */
    public function testConfiguredTimeoutReachesTheSessionLayer(): void
    {
        $previous = (int)ini_get('session.gc_maxlifetime');

        try {
            new Session((array)Configure::read('Session'));

            $this->assertSame(
                (int)Configure::read('Session.timeout') * 60,
                (int)ini_get('session.gc_maxlifetime'),
                'The configured timeout did not reach the session lifetime.',
            );
        } finally {
            // Restore it, so building a session here does not change the
            // lifetime seen by every test that runs after this one.
            ini_set('session.gc_maxlifetime', (string)$previous);
        }
    }

    /**
     * The session cookie is not readable from JavaScript.
     *
     * This one is checkable in CLI, because it is configuration rather than
     * runtime behaviour. It matters because an XSS bug anywhere on the site
     * would otherwise be a route to stealing a live admin session, which
     * makes the idle timeout beside the point.
     *
     * @return void
     */
    public function testSessionCookieIsNotReadableFromJavaScript(): void
    {
        // I read the ini array once and index it directly, rather than asking
        // Configure for 'Session.ini.session.cookie_httponly'. Configure
        // splits its path on every dot, so that string is read as four levels
        // (Session, ini, session, cookie_httponly) while the real key is the
        // single literal 'session.cookie_httponly' with dots inside it. My
        // first version of this test did exactly that, got null back, and
        // reported the cookie as readable when the setting was correct all
        // along.
        $ini = (array)Configure::read('Session.ini');

        $this->assertTrue(
            (bool)($ini['session.cookie_httponly'] ?? false),
            'The session cookie can be read by JavaScript.',
        );
        $this->assertSame(
            'Lax',
            $ini['session.cookie_samesite'] ?? null,
        );
        $this->assertSame(
            1,
            $ini['session.use_only_cookies'] ?? null,
            'PHP will accept a session id from the URL, so a pasted link '
            . 'would hand over the session with it.',
        );
    }

    // -----------------------------------------------------------------------
    // Security check 4: passwords are stored hashed
    // -----------------------------------------------------------------------

    /**
     * The point of this test is the storage method, not the password.
     *
     * This is the check that was failing before this branch.
     * Admin::_setPassword() never ran, because CakePHP builds the mutator
     * name from the field and the column is admin_password, so every account
     * held the password exactly as typed.
     *
     * @return void
     */
    public function testAdminPasswordIsStoredHashedNotPlaintext(): void
    {
        $admins = TableRegistry::getTableLocator()->get('Admins');

        $admin = $admins->newEmptyEntity();
        $admin = $admins->patchEntity($admin, [
            'admin_username' => 'newstarter',
            'admin_email' => 'newstarter@example.com',
            'admin_password' => AdminsFixture::PASSWORD,
            'confirm_password' => AdminsFixture::PASSWORD,
        ]);
        $admins->saveOrFail($admin);

        $stored = $admins->get($admin->admin_id)->admin_password;

        $this->assertNotSame(
            AdminsFixture::PASSWORD,
            $stored,
            'The password was written to the database as plaintext.',
        );
        $this->assertMatchesRegularExpression(
            '/^\$2[aby]\$\d{2}\$/',
            $stored,
            'The stored value is not a bcrypt hash.',
        );
        $this->assertTrue(
            password_verify(AdminsFixture::PASSWORD, $stored),
            'The stored hash does not verify against the original password.',
        );
    }

    /**
     * The password form cannot be used to rename the account it belongs to.
     *
     * Run as staff rather than admin. Admins lost this page altogether when
     * account records moved to the manager, but plumbers sign in on the staff
     * role and still use it, so the rule still needs proving.
     *
     * @return void
     */
    public function testEditingAnAccountCannotChangeUsername(): void
    {
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $before = $admins->get(2)->admin_password;

        $this->loginAsStaff();
        $this->enableCsrfToken();
        $this->put('/admins/edit/2', [
            'admin_username' => 'staffuser.renamed',
            'current_password' => AdminsFixture::PASSWORD,
            'admin_password' => 'ADifferentPassword#99',
            'confirm_password' => 'ADifferentPassword#99',
        ]);

        $after = $admins->get(2);

        $this->assertSame('staffuser', $after->admin_username);
        $this->assertNotSame($before, $after->admin_password);
    }

    /**
     * Setting a new password on an existing account rehashes it.
     *
     * This is the route out of a seeded account whose password nobody knows,
     * and it also normalises a hash written by something other than PHP.
     *
     * @return void
     */
    public function testSettingANewPasswordRehashesTheAccount(): void
    {
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $before = $admins->get(2)->admin_password;

        $this->loginAsStaff();
        $this->enableCsrfToken();
        $this->put('/admins/edit/2', [
            'current_password' => AdminsFixture::PASSWORD,
            'admin_password' => 'ADifferentPassword#99',
            'confirm_password' => 'ADifferentPassword#99',
        ]);

        $after = $admins->get(2)->admin_password;

        $this->assertNotSame($before, $after);
        $this->assertMatchesRegularExpression('/^\$2[aby]\$\d{2}\$/', $after);
        $this->assertTrue(password_verify('ADifferentPassword#99', $after));
    }

    /**
     * A hash that never leaves the server cannot be leaked by a template.
     *
     * @return void
     */
    public function testPasswordHashIsNotRenderedOnTheAccountsPage(): void
    {
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $storedHash = $admins->get(1)->admin_password;

        $this->session(['AdminAuth' => [
            'admin_id' => 3,
            'admin_username' => 'manageruser',
            'role' => 'manager',
        ]]);
        $this->get('/admins');

        $this->assertResponseOk();
        $this->assertResponseNotContains(
            $storedHash,
            'The admin list is printing password hashes into the page.',
        );
    }

    // -----------------------------------------------------------------------
    // Acceptance criterion: submitted requests are saved
    // -----------------------------------------------------------------------

    /**
     * Closes the loop between the public form and this dashboard. The form
     * used to write to a `contacts` table that did not exist, so a request
     * could never have arrived here.
     *
     * @return void
     */
    public function testARequestSubmittedByThePublicAppearsInTheDashboard(): void
    {
        $submissions = TableRegistry::getTableLocator()->get('ContactSubmissions');

        $submission = $submissions->newEmptyEntity();
        $submission = $submissions->patchEntity($submission, [
            'name' => 'Wilhelmina Fry',
            'email' => 'w.fry@example.com',
            'subject' => 'Blocked shower drain',
            'message' => 'Water is not draining in the upstairs shower.',
        ]);
        $submissions->saveOrFail($submission);

        $this->loginAsAdmin();
        $this->get('/contact-submissions');

        $this->assertResponseOk();
        $this->assertResponseContains('Wilhelmina Fry');
    }

    /**
     * Both spellings of the contact form URL work, without a login.
     *
     * The landing page links to /contact from the header and footer and to
     * /contacts/add from the hero. /contact was not a route at all, so two of
     * the three calls to action on the site 404ed. This pins both.
     *
     * @return void
     */
    public function testBothContactFormUrlsAreReachableByThePublic(): void
    {
        foreach (['/contact', '/contacts/add'] as $url) {
            $this->get($url);

            $this->assertResponseOk(sprintf('%s did not load for an anonymous visitor.', $url));
            $this->assertResponseContains('Contact Us');
        }
    }

    /**
     * A request arriving from the public form must not be able to set its own
     * status, or a spammer could post requests pre-marked as archived and
     * keep them out of Hannah's view entirely.
     *
     * @return void
     */
    public function testAPublicRequestCannotChooseItsOwnStatus(): void
    {
        $submissions = TableRegistry::getTableLocator()->get('ContactSubmissions');

        $submission = $submissions->newEmptyEntity();
        $submission = $submissions->patchEntity($submission, [
            'name' => 'Sneaky Sam',
            'email' => 'sam@example.com',
            'message' => 'Trying to arrive pre-archived.',
            'status' => 'archived',
            'read_by' => 1,
        ]);
        $submissions->saveOrFail($submission);

        $saved = $submissions->get($submission->id);

        $this->assertSame('new', $saved->status);
        $this->assertNull($saved->read_by);
    }

    /**
     * Enquiries are just messages to answer, so there is no compliance on the
     * list or the page, and the status buttons only appear once one is open.
     *
     * @return void
     */
    public function testEnquiriesHaveNoComplianceAndStatusButtonsLiveOnTheOpenedEnquiry(): void
    {
        $this->loginAsAdmin();

        $this->get('/contact-submissions');
        $this->assertResponseOk();
        $this->assertResponseNotContains('Compliance');
        $this->assertResponseNotContains('Mark read');
        $this->assertResponseNotContains('Mark unread');
        $this->assertResponseNotContains('change-status');
        $this->assertResponseNotContains('Archive the enquiry');
        $this->assertResponseContains('Open');

        $this->get('/contact-submissions/view/1');
        $this->assertResponseOk();
        $this->assertResponseNotContains('Compliance');
        $this->assertResponseContains('Mark as');
        $this->assertResponseContains('change-status');
        // Worded as actions, not as "Mark as Archived" or "Mark as New".
        $this->assertResponseContains('>Archive<');
        $this->assertResponseNotContains('Mark as Archived');
        $this->assertResponseNotContains('Mark as New');
        // Replying happens in the person's own mailbox, not from this page.
        $this->assertResponseNotContains('Reply by email');
        $this->assertResponseNotContains('mailto:');
    }

    /**
     * The list has a single Status column reading New, Read, Replied or
     * Archived, and no Stage column.
     *
     * @return void
     */
    public function testStatusColumnReadsNewReadRepliedArchivedAndThereIsNoStage(): void
    {
        $this->loginAsAdmin();
        $this->get('/contact-submissions');

        $this->assertResponseOk();
        $this->assertResponseNotContains('Stage');
        $this->assertResponseContains('>Replied<');
        $this->assertResponseContains('<option value="replied">Replied</option>');
    }

    /**
     * @return void
     */
    public function testAnEnquiryCanBeMarkedRepliedFromItsOwnPage(): void
    {
        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/contact-submissions/change-status/1', ['status' => 'replied']);
        $this->assertRedirect();

        $saved = TableRegistry::getTableLocator()->get('ContactSubmissions')->get(1);
        $this->assertSame('replied', $saved->status);
        // Replying implies it was read.
        $this->assertNotNull($saved->read_at);

        $this->get('/contact-submissions/view/1');
        $this->assertResponseContains('Replied');
    }
}
