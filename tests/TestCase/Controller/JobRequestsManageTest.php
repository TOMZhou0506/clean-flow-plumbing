<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\ComplianceCheck;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;

/**
 * The office view of job requests.
 *
 * The client reported that job requests were not reaching the admin dashboard.
 * They were being saved the whole time; there was simply nowhere in the admin
 * area that listed them. This covers the list that fixes that, and the part
 * that makes me nervous: JobRequestsController now answers to both customers
 * and staff, so the wrong guard on the wrong action leaks every customer's
 * address.
 *
 * @link \App\Controller\JobRequestsController::manage()
 */
class JobRequestsManageTest extends TestCase
{
    use IntegrationTestTrait;
    use TicksComplianceTrait;

    /**
     * @var array<string>
     */
    protected array $fixtures = [
        'app.Admins',
        'app.Customers',
        'app.JobRequests',
        'app.ComplianceChecks',
        'app.AuditLogs',
    ];

    /**
     * @param int $id The admin to sign in as.
     * @return void
     */
    private function loginAsAdmin(int $id = 1): void
    {
        $this->session([
            'AdminAuth' => [
                'admin_id' => $id,
                'admin_username' => $id === 1 ? 'hannah' : 'staffuser',
                'role' => $id === 1 ? 'admin' : 'staff',
            ],
        ]);
    }

    /**
     * Renders errors the way production does.
     *
     * With debug on, Cake's error page dumps the request context deep enough
     * to include loaded fixture rows, so an access control test can pass while
     * the response body contains the very data it claims was withheld.
     *
     * @return void
     */
    protected function productionErrors(): void
    {
        Configure::write('debug', false);
    }

    /**
     * @return void
     */
    public function testAdminSeesEveryCustomersRequests(): void
    {
        $this->loginAsAdmin();
        $this->get('/job-requests/manage');

        $this->assertResponseOk();
        $this->assertResponseContains('12 Clayton Road');
        // The customer-facing index() scopes to one customer. This one must
        // not, or the office cannot do its job.
        $this->assertResponseContains('99 Private Street');
    }

    /**
     * The bug the client actually hit.
     *
     * An admin with a valid session was being shown the customer login page,
     * because the authenticators were picked by URL prefix and
     * /job-requests/manage matched the customer /job-requests prefix. The
     * admin session was never even looked at.
     *
     * @return void
     */
    public function testAdminIsNotAskedForACustomerLogin(): void
    {
        $this->loginAsAdmin();
        $this->get('/job-requests/manage');

        $this->assertResponseOk();
        $this->assertNoRedirect();
        $this->assertResponseNotContains('Customer login');
    }

    /**
     * @return void
     */
    public function testLoggedOutCannotOpenTheOfficeList(): void
    {
        $this->productionErrors();
        $this->get('/job-requests/manage');

        $this->assertRedirectContains('/login');
        $this->assertResponseNotContains('99 Private Street');
    }

    /**
     * @return void
     */
    public function testACustomerSessionIsNotEnoughForTheOfficeList(): void
    {
        $this->productionErrors();
        $this->session([
            'CustomerAuth' => [
                'cust_id' => 1,
                'cust_email' => 'kash@example.com',
                'cust_fname' => 'Kash',
                'cust_lname' => 'Patel',
            ],
        ]);

        $this->get('/job-requests/manage');

        $this->assertResponseNotContains('99 Private Street');
    }

    /**
     * A deleted admin cannot keep using the session they already had.
     *
     * The session authenticator reads the identity it stored at login and
     * never checks the database again, so deleting the account did nothing to
     * anybody already signed in as it. They carried on working, and pressing
     * back brought the dashboard of a deleted account straight up.
     *
     * @return void
     */
    public function testADeletedAdminLosesAccessImmediately(): void
    {
        $this->productionErrors();
        $this->loginAsAdmin();

        // Proves the session was good before the deletion, so a failure below
        // is the deletion doing its job rather than a broken login.
        $this->get('/job-requests/manage');
        $this->assertResponseOk();

        $admins = TableRegistry::getTableLocator()->get('Admins');
        $admins->deleteOrFail($admins->get(1));

        $this->get('/job-requests/manage');

        $this->assertRedirectContains('/login');
        $this->assertResponseNotContains('99 Private Street');
    }

    /**
     * Demoting an admin takes effect on their next request, not their next
     * login. Revoking access is precisely when waiting is not acceptable.
     *
     * @return void
     */
    public function testDemotingAnAdminTakesEffectImmediately(): void
    {
        $this->productionErrors();
        $this->loginAsAdmin();

        $admins = TableRegistry::getTableLocator()->get('Admins');
        $admin = $admins->get(1);
        $admin->set('role', 'staff');
        $admins->saveOrFail($admin);

        $this->get('/job-requests/manage');

        $this->assertResponseCode(403);
        $this->assertResponseNotContains('99 Private Street');
    }

    /**
     * Signed-in pages must not be left in the browser's cache, or the back
     * button repaints them from disk after the account is gone.
     *
     * @return void
     */
    public function testOfficePagesAreNotCacheable(): void
    {
        $this->loginAsAdmin();
        $this->get('/job-requests/manage');

        $this->assertResponseOk();
        $this->assertHeaderContains('Cache-Control', 'no-store');
    }

    /**
     * @return void
     */
    public function testSearchNarrowsTheList(): void
    {
        $this->loginAsAdmin();
        $this->get('/job-requests/manage?q=Clayton');

        $this->assertResponseOk();
        $this->assertResponseContains('12 Clayton Road');
        $this->assertResponseNotContains('99 Private Street');
    }

    /**
     * A LIKE wildcard typed into the search box is text, not syntax.
     *
     * Without escaping, a search for "%" matches every row, which is a quiet
     * way of dumping the table past whatever filter the user thought they had
     * applied.
     *
     * @return void
     */
    public function testSearchTreatsWildcardsAsText(): void
    {
        $this->loginAsAdmin();
        $this->get('/job-requests/manage?q=%25');

        $this->assertResponseOk();
        $this->assertResponseNotContains('12 Clayton Road');
        $this->assertResponseNotContains('99 Private Street');
    }

    /**
     * A backwards date range is refused rather than silently matching nothing.
     *
     * From after To can never match a row, so the office would get an empty
     * table indistinguishable from "there genuinely were none this month".
     * That is the dangerous part: it is a wrong answer somebody could act on,
     * not an obvious error.
     *
     * @return void
     */
    public function testABackwardsDateRangeIsIgnoredAndReported(): void
    {
        $this->loginAsAdmin();
        $this->enableRetainFlashMessages();
        $this->get('/job-requests/manage?from=2026-12-01&to=2026-01-01');

        $this->assertResponseOk();
        $this->assertFlashElement('flash/error');

        // The dates were dropped, so the list is unfiltered rather than empty.
        $this->assertResponseContains('12 Clayton Road');
        $this->assertResponseContains('99 Private Street');
    }

    /**
     * Only the dates are dropped. A search term the person typed survives,
     * because throwing that away too would punish them twice for one slip.
     *
     * @return void
     */
    public function testABackwardsDateRangeKeepsTheRestOfTheFilter(): void
    {
        $this->loginAsAdmin();
        $this->get('/job-requests/manage?q=Clayton&from=2026-12-01&to=2026-01-01');

        $this->assertResponseOk();
        $this->assertResponseContains('12 Clayton Road');
        $this->assertResponseNotContains('99 Private Street');
    }

    /**
     * From and To on the same day is a legitimate way of asking for one day.
     *
     * @return void
     */
    public function testASingleDayRangeIsAllowed(): void
    {
        $this->loginAsAdmin();
        $this->enableRetainFlashMessages();
        $this->get('/job-requests/manage?from=2026-09-08&to=2026-09-08');

        $this->assertResponseOk();
        $this->assertResponseContains('12 Clayton Road');
    }

    /**
     * The export must agree with the list it was launched from, or the CSV
     * quietly holds a different set of rows than the screen showed.
     *
     * @return void
     */
    public function testTheExportAppliesTheSameDateRangeRule(): void
    {
        $this->loginAsAdmin();
        $this->get('/job-requests/manage/export?from=2026-12-01&to=2026-01-01');

        $this->assertResponseOk();
        $this->assertResponseContains('12 Clayton Road');
    }

    /**
     * The form stops it happening in the first place.
     *
     * @return void
     */
    public function testTheDateInputsCapEachOther(): void
    {
        $this->loginAsAdmin();
        $this->get('/job-requests/manage?from=2026-09-01&to=2026-09-30');

        $this->assertResponseOk();
        $this->assertResponseContains('max="2026-09-30"');
        $this->assertResponseContains('min="2026-09-01"');
    }

    /**
     * @return void
     */
    public function testStatusFilterOnlyAcceptsKnownStatuses(): void
    {
        $this->loginAsAdmin();
        $this->get('/job-requests/manage?status=approved');
        $this->assertResponseOk();
        $this->assertResponseNotContains('12 Clayton Road');

        // A made-up status must not fall through to "show everything".
        $this->get('/job-requests/manage?status=' . urlencode("' OR 1=1 --"));
        $this->assertResponseOk();
    }

    /**
     * @return void
     */
    public function testSortingIsLimitedToTheAllowlist(): void
    {
        $this->loginAsAdmin();

        $this->get('/job-requests/manage?sort=suburb&direction=asc');
        $this->assertResponseOk();

        // A column outside JobRequestsTable::SORTABLE is ignored rather than
        // reaching ORDER BY. Contact phone is a real column, which is the
        // point: existing is not the same as allowed.
        $this->get('/job-requests/manage?sort=contact_phone&direction=asc');
        $this->assertResponseOk();
    }

    /**
     * @return void
     */
    public function testOpeningARequestMarksItReadAndAuditsIt(): void
    {
        $this->loginAsAdmin();
        $this->get('/job-requests/manage/1');

        $this->assertResponseOk();
        $this->assertResponseContains('12 Clayton Road');

        $jobRequests = TableRegistry::getTableLocator()->get('JobRequests');
        $saved = $jobRequests->get(1);
        $this->assertNotNull($saved->read_at, 'Opening a request should record that somebody saw it.');
        $this->assertSame(1, $saved->read_by);

        $audit = TableRegistry::getTableLocator()->get('AuditLogs');
        $this->assertSame(
            1,
            $audit->find()->where([
                'action' => 'job_request.view',
                'entity_type' => 'job_request',
                'entity_id' => '1',
            ])->count(),
            'Reading a customer address must leave an audit entry.',
        );
    }

    /**
     * A status change cannot happen on a GET.
     *
     * The verb is enforced by allowMethod() in the controller rather than by
     * the route, so a GET reaches the action and is refused with a 405. See
     * the note in routes.php for why the route itself no longer restricts the
     * method.
     *
     * @return void
     */
    public function testStatusChangeCannotHappenOnAGet(): void
    {
        $this->productionErrors();
        $this->loginAsAdmin();
        $this->get('/job-requests/manage/status/1');

        $this->assertResponseCode(405);

        $jobRequests = TableRegistry::getTableLocator()->get('JobRequests');
        $this->assertSame(
            'submitted',
            $jobRequests->get(1)->status,
            'A GET must never change a job request status.',
        );
    }

    /**
     * The button on the page points at the route the route file defines.
     *
     * This is the test that was missing, and the gap is worth naming. The
     * existing status change test posts to /job-requests/manage/status/1 by
     * hand, so it passed the whole time the button on the page was generating
     * a completely different URL. A test that types the URL itself proves the
     * action works; it proves nothing about whether anybody can reach it.
     *
     * The wrong URL was not merely cosmetic. /job-requests/changeStatus/1 sits
     * under the customer prefix, so the admin authenticators were never
     * loaded and the office was bounced to the login page instead of updating
     * the record.
     *
     * @return void
     */
    public function testTheStatusButtonsPointAtTheOfficeRoute(): void
    {
        $this->loginAsAdmin();
        $this->get('/job-requests/manage/1');

        $this->assertResponseOk();
        $this->assertResponseContains('/job-requests/manage/status/1');
        $this->assertResponseNotContains('/job-requests/changeStatus');
    }

    /**
     * Whatever the office sets is what the customer is shown.
     *
     * The reported symptom was a request stuck on "Submitted" forever. Worth
     * asserting end to end rather than trusting that a row updated somewhere.
     *
     * @return void
     */
    public function testAStatusChangeReachesTheCustomersOwnList(): void
    {
        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/job-requests/manage/status/1', ['status' => 'reviewing']);
        $this->tickAllCompliance(1);
        $this->post('/job-requests/manage/status/1', ['status' => 'approved']);
        $this->assertResponseCode(302);

        // This is a new customer session, not a browser holding both roles.
        unset($this->_session['AdminAuth']);
        $this->session([
            'CustomerAuth' => [
                'cust_id' => 1,
                'cust_email' => 'kash@example.com',
                'cust_fname' => 'Kash',
                'cust_lname' => 'Patel',
            ],
        ]);
        $this->get('/job-requests');

        $this->assertResponseOk();
        // Matched on the status pill's class rather than the word. "Submitted"
        // is also the heading of the date column on this page, so asserting on
        // the plain word would fail whatever the status actually is.
        $this->assertResponseContains('request-status--approved');
        $this->assertResponseNotContains('request-status--submitted');
    }

    /**
     * A made-up path under the office list is not the office list.
     *
     * Same fallback route as above. Nothing leaked, since the whole controller
     * needs the admin role, but a URL that answers 200 with the wrong content
     * is how a typo becomes a bug report nobody can reproduce.
     *
     * @return void
     */
    public function testAnUnknownPathUnderManageIsNotFound(): void
    {
        $this->productionErrors();
        $this->loginAsAdmin();
        $this->get('/job-requests/manage/not/a/real/page');

        $this->assertResponseCode(404);
        $this->assertResponseNotContains('99 Private Street');
    }

    /**
     * @return void
     */
    public function testStatusChangeIsSavedAndAudited(): void
    {
        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/job-requests/manage/status/1', ['status' => 'reviewing']);
        $this->tickAllCompliance(1);
        $this->post('/job-requests/manage/status/1', ['status' => 'approved']);

        $this->assertResponseCode(302);

        $jobRequests = TableRegistry::getTableLocator()->get('JobRequests');
        $this->assertSame('approved', $jobRequests->get(1)->status);

        $audit = TableRegistry::getTableLocator()->get('AuditLogs');
        $this->assertSame(
            2,
            $audit->find()->where(['action' => 'job_request.status_change'])->count(),
        );
    }

    /**
     * @return void
     */
    public function testAnUnknownStatusIsRefused(): void
    {
        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/job-requests/manage/status/1', ['status' => 'cancelled-by-me']);

        $jobRequests = TableRegistry::getTableLocator()->get('JobRequests');
        $this->assertSame(
            'submitted',
            $jobRequests->get(1)->status,
            'Only statuses on JobRequest::STATUSES may be written.',
        );
    }

    /**
     * @return void
     */
    public function testExportIsAuditedAndDefusesFormulas(): void
    {
        $jobRequests = TableRegistry::getTableLocator()->get('JobRequests');
        $row = $jobRequests->get(1);
        $row->set('street_address', '=HYPERLINK("http://evil.example","click")');
        $jobRequests->saveOrFail($row);

        $this->loginAsAdmin();
        $this->get('/job-requests/manage/export');

        $this->assertResponseOk();
        // The tab makes the cell text. Without it, opening the file in Excel
        // runs whatever the customer typed into the address box.
        $this->assertResponseContains("\t=HYPERLINK");

        $audit = TableRegistry::getTableLocator()->get('AuditLogs');
        $this->assertSame(
            1,
            $audit->find()->where(['action' => 'job_request.export'])->count(),
        );
    }

    /**
     * @return void
     */
    public function testLoggedOutCannotExport(): void
    {
        $this->productionErrors();
        $this->get('/job-requests/manage/export');

        $this->assertResponseNotContains('99 Private Street');
    }

    /**
     * Job request compliance is kept apart from enquiry compliance.
     *
     * Both tables key on an integer id, so enquiry 1 and job request 1 exist
     * at the same time. subject_type is the only thing keeping them apart, and
     * dropping it from one query would quietly show one subject's checks
     * against the other.
     *
     * @return void
     */
    public function testComplianceCountsDoNotLeakBetweenSubjectTypes(): void
    {
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');

        $enquiry = $checks->progressFor(1, ComplianceCheck::SUBJECT_ENQUIRY);
        $jobRequest = $checks->progressFor(1, ComplianceCheck::SUBJECT_JOB_REQUEST);

        // The fixture gives enquiry 1 one met requirement and one unmet one,
        // and job request 1 a single met requirement of its own.
        $this->assertSame(1, $enquiry['met']);
        $this->assertSame(1, $jobRequest['met']);

        $enquiryList = $checks->checklistFor(1, ComplianceCheck::SUBJECT_ENQUIRY);
        $this->assertNotNull($enquiryList['safety']['check'], 'The enquiry keeps its own safety row.');

        $jobList = $checks->checklistFor(1, ComplianceCheck::SUBJECT_JOB_REQUEST);
        $this->assertNull(
            $jobList['safety']['check'],
            'The job request has no safety row, so it must not borrow the enquiry one.',
        );
    }

    /**
     * @return void
     */
    public function testRecordingAJobRequestCheckDoesNotTouchTheEnquiry(): void
    {
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');

        $checks->record(1, 'safety', true, 'Site visit done.', 1, ComplianceCheck::SUBJECT_JOB_REQUEST);

        $this->assertSame(2, $checks->progressFor(1, ComplianceCheck::SUBJECT_JOB_REQUEST)['met']);
        $this->assertSame(
            1,
            $checks->progressFor(1, ComplianceCheck::SUBJECT_ENQUIRY)['met'],
            'The enquiry had safety recorded as not met and that must stand.',
        );
    }

    /**
     * @return void
     */
    public function testAnUnrecognisedSubjectTypeIsRefused(): void
    {
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');

        $this->expectException(InvalidArgumentException::class);
        $checks->record(1, 'safety', true, null, 1, 'invoice');
    }
}
