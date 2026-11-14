<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\ComplianceCheck;
use App\Test\Fixture\ComplianceChecksFixture;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * US 3.1: as Hannah, I want to confirm compliance requirements, so that I can
 * determine if the job can proceed.
 *
 * Each test maps to one of the story's test cases or security checks, and the
 * method names say which.
 */
class ComplianceControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.ContactSubmissions',
        'app.ComplianceChecks',
        'app.AuditLogs',
    ];

    /**
     * @return void
     */
    protected function loginAsAdmin(): void
    {
        // No 'id' key. A real login stores an Admins row, which has admin_id
        // and no id at all, so putting one here made the session look like
        // something the application never produces and hid a bug where the
        // audit trail recorded a null user. See AppController::currentAdminId().
        $this->session([
            'AdminAuth' => [
                'admin_id' => 1,
                'admin_username' => 'hannah',
                'role' => 'admin',
            ],
        ]);
    }

    /**
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
     * Renders errors as production does, so a debug stack trace cannot put
     * fixture data into a response an access control test is checking.
     *
     * @return void
     */
    protected function productionErrors(): void
    {
        Configure::write('debug', false);
    }

    /**
     * Conditions matching one compliance row belonging to enquiry 1.
     *
     * subject_type has to be in here. Compliance now covers job requests as
     * well as enquiries, and both key on an integer id, so enquiry 1 and job
     * request 1 both exist. A lookup by submission_id alone quietly returns
     * both, and the assertion it feeds stops meaning what it says. That is not
     * hypothetical: these tests started failing the moment a job request row
     * landed in the fixture next to the enquiry one.
     *
     * @param string $requirement One of ComplianceCheck::REQUIREMENTS.
     * @return array<string, mixed>
     */
    private function enquiryCheck(string $requirement): array
    {
        return [
            'subject_type' => ComplianceCheck::SUBJECT_ENQUIRY,
            'submission_id' => 1,
            'requirement' => $requirement,
        ];
    }

    // -----------------------------------------------------------------------
    // Test case 1: the checklist displays correctly
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testChecklistShowsEveryRequirement(): void
    {
        $this->loginAsAdmin();
        $this->get('/compliance/view/1');

        $this->assertResponseOk();

        // Every requirement appears, including the two nobody has reviewed.
        // A checklist that only lists what has been touched would hide the
        // work still outstanding, which is the opposite of its purpose.
        foreach (ComplianceCheck::REQUIREMENTS as $label) {
            $this->assertResponseContains($label);
        }

        $this->assertResponseContains('Licence 12345 verified');
        $this->assertResponseContains('Not yet reviewed');
    }

    /**
     * @return void
     */
    public function testChecklistShowsBlockedWhenRequirementsAreOutstanding(): void
    {
        $this->loginAsAdmin();
        $this->get('/compliance/view/1');

        $this->assertResponseOk();
        $this->assertResponseContains('Blocked');
        $this->assertResponseContains('1 of 4 requirements met.');
    }

    /**
     * An enquiry nobody has reviewed must read as blocked, not as compliant.
     *
     * Treating "unknown" as "cleared" would be the worst possible failure for
     * a compliance feature: a job would appear ready to proceed precisely
     * because nobody had checked it.
     *
     * @return void
     */
    public function testUnreviewedEnquiryIsBlockedNotCompliant(): void
    {
        $this->loginAsAdmin();
        $this->get('/compliance/view/3');

        $this->assertResponseOk();
        $this->assertResponseContains('Blocked');
        $this->assertResponseContains('0 of 4 requirements met.');
    }

    /**
     * @return void
     */
    public function testChecklistShowsCanProceedOnceEveryRequirementIsMet(): void
    {
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');
        foreach (array_keys(ComplianceCheck::REQUIREMENTS) as $requirement) {
            $checks->record(3, $requirement, true, null, 1);
        }

        $this->loginAsAdmin();
        $this->get('/compliance/view/3');

        $this->assertResponseOk();
        $this->assertResponseContains('Can proceed');
        $this->assertResponseContains('4 of 4 requirements met.');
    }

    // -----------------------------------------------------------------------
    // Test case 2: each requirement saves independently
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testMarkingOneRequirementLeavesTheOthersAlone(): void
    {
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');

        $before = $checks->find()
            ->where($this->enquiryCheck('licence'))
            ->firstOrFail();

        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', [
            'requirement' => 'permit',
            'is_met' => '1',
            'notes' => 'Permit 998 issued by South East Water.',
        ]);

        $this->assertRedirectContains('/compliance/view/1');

        $permit = $checks->find()
            ->where($this->enquiryCheck('permit'))
            ->firstOrFail();
        $this->assertTrue((bool)$permit->is_met);

        // The licence row must be untouched, including its timestamp.
        $after = $checks->find()
            ->where($this->enquiryCheck('licence'))
            ->firstOrFail();
        $this->assertSame($before->notes, $after->notes);
        $this->assertTrue((bool)$after->is_met);
        $this->assertEquals($before->checked_at, $after->checked_at);

        // And the requirement that was already not met stays not met.
        $safety = $checks->find()
            ->where($this->enquiryCheck('safety'))
            ->firstOrFail();
        $this->assertFalse((bool)$safety->is_met);
    }

    /**
     * Re-recording a requirement corrects the existing row rather than adding
     * a second, contradictory one.
     *
     * @return void
     */
    public function testRecordingTheSameRequirementTwiceUpdatesOneRow(): void
    {
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');

        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', [
            'requirement' => 'licence',
            'is_met' => '0',
            'notes' => 'Licence has lapsed.',
        ]);

        $rows = $checks->find()
            ->where($this->enquiryCheck('licence'))
            ->all()
            ->toArray();

        $this->assertCount(1, $rows, 'A second row was created for the same requirement.');
        $this->assertFalse((bool)$rows[0]->is_met);
        $this->assertSame('Licence has lapsed.', $rows[0]->notes);
    }

    /**
     * @return void
     */
    public function testAnUnrecognisedRequirementIsRejected(): void
    {
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');
        $before = $checks->find()->count();

        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', [
            'requirement' => 'bribe_paid',
            'is_met' => '1',
        ]);

        $this->assertSame($before, $checks->find()->count());
    }

    /**
     * A GET must not be able to change a compliance decision. Otherwise a
     * crawler or a prefetching browser could clear a requirement.
     *
     * @return void
     */
    public function testRecordingRequiresPost(): void
    {
        $this->productionErrors();
        $this->loginAsAdmin();
        $this->get('/compliance/record/1?requirement=permit&is_met=1');

        $this->assertResponseError();

        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');
        $this->assertSame(
            0,
            $checks->find()->where($this->enquiryCheck('permit'))->count(),
        );
    }

    // -----------------------------------------------------------------------
    // Test case 3 and security check 1: access control
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testNonAdminIsDeniedTheComplianceSection(): void
    {
        $this->productionErrors();
        $this->loginAsStaff();
        $this->get('/compliance/view/1');

        $this->assertResponseCode(403);
        $this->assertResponseNotContains('Licence 12345');
        $this->assertResponseNotContains('Marcus Bell');
    }

    /**
     * Hiding the page is not access control. A staff account must not be able
     * to record a decision by posting straight to the action.
     *
     * @return void
     */
    public function testNonAdminCannotRecordADecisionByPostingDirectly(): void
    {
        $this->productionErrors();
        $this->loginAsStaff();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', [
            'requirement' => 'permit',
            'is_met' => '1',
        ]);

        $this->assertResponseCode(403);

        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');
        $this->assertSame(
            0,
            $checks->find()->where($this->enquiryCheck('permit'))->count(),
            'A staff account recorded a compliance decision.',
        );
    }

    /**
     * @return void
     */
    public function testLoggedOutIsRedirectedToLoginAndSeesNoComplianceData(): void
    {
        $this->productionErrors();
        $this->get('/compliance/view/1');

        // The shared sign-in page. Admin pages used to send people to
        // /admins/login and customer pages to /customers/login; both of those
        // separate login pages are gone now, per the client's request to
        // remove the double login.
        $this->assertRedirectContains('/login');
        $this->assertResponseNotContains('Licence 12345');
    }

    /**
     * @return void
     */
    public function testLoggedOutCannotRecordADecision(): void
    {
        $this->productionErrors();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', [
            'requirement' => 'permit',
            'is_met' => '1',
        ]);

        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');
        $this->assertSame(
            0,
            $checks->find()->where($this->enquiryCheck('permit'))->count(),
        );
    }

    // -----------------------------------------------------------------------
    // Test case 4 and security check 2: injection payloads in the notes field
    // -----------------------------------------------------------------------

    /**
     * A script tag stored in a note renders as visible text.
     *
     * This is the stored XSS case, and it is the dangerous one: the payload is
     * written by one admin and executes in the browser of every colleague who
     * later opens the page. The fixture already holds the payload, so this
     * checks the reading half.
     *
     * @return void
     */
    public function testStoredScriptTagInANoteIsEscapedOnOutput(): void
    {
        $this->loginAsAdmin();
        $this->get('/compliance/view/2');

        $this->assertResponseOk();

        // The raw tag must not reach the page.
        $this->assertResponseNotContains(
            ComplianceChecksFixture::XSS_NOTE,
            'An admin note was rendered as live HTML.',
        );

        // It should be there, escaped, so the note is still readable.
        $this->assertResponseContains('&lt;script&gt;');
    }

    /**
     * The same payload submitted through the form comes back as text.
     *
     * @return void
     */
    public function testSubmittedScriptTagIsStoredVerbatimAndEscapedOnOutput(): void
    {
        $payload = '<img src=x onerror=alert(1)>';

        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', [
            'requirement' => 'insurance',
            'is_met' => '1',
            'notes' => $payload,
        ]);

        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');
        $stored = $checks->find()
            ->where($this->enquiryCheck('insurance'))
            ->firstOrFail();

        // Stored exactly as typed. I escape at output, not on input: stripping
        // tags on the way in corrupts legitimate text and gives false comfort,
        // because any other write path would bypass it.
        $this->assertSame($payload, $stored->notes);

        $this->get('/compliance/view/1');
        $this->assertResponseNotContains($payload);
        $this->assertResponseContains('&lt;img');
    }

    /**
     * A SQL payload in a note is stored as text and breaks nothing.
     *
     * Everything goes through the ORM's parameter binding, so this is really
     * a regression guard against someone later hand-writing a query here.
     *
     * @return void
     */
    public function testSqlPayloadInANoteIsStoredAsTextAndNotExecuted(): void
    {
        $payload = "'; DROP TABLE compliance_checks; --";

        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', [
            'requirement' => 'insurance',
            'is_met' => '1',
            'notes' => $payload,
        ]);

        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');

        $stored = $checks->find()
            ->where($this->enquiryCheck('insurance'))
            ->firstOrFail();

        $this->assertSame($payload, $stored->notes);

        // The table still exists and the other rows are intact.
        $this->assertGreaterThan(0, $checks->find()->count());

        $this->get('/compliance/view/1');
        $this->assertResponseOk();
    }

    /**
     * An over-long note is refused rather than silently truncated.
     *
     * @return void
     */
    public function testAnOverLongNoteIsRejected(): void
    {
        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', [
            'requirement' => 'insurance',
            'is_met' => '1',
            'notes' => str_repeat('a', ComplianceCheck::NOTES_MAX + 1),
        ]);

        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');
        $this->assertSame(
            0,
            $checks->find()->where($this->enquiryCheck('insurance'))->count(),
        );
    }

    // -----------------------------------------------------------------------
    // Audit trail
    // -----------------------------------------------------------------------

    /**
     * Every compliance decision is attributable.
     *
     * @return void
     */
    public function testRecordingADecisionIsAudited(): void
    {
        $auditLogs = TableRegistry::getTableLocator()->get('AuditLogs');
        $before = $auditLogs->find()->where(['action' => 'compliance.record'])->count();

        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', [
            'requirement' => 'permit',
            'is_met' => '1',
        ]);

        $entries = $auditLogs->find()
            ->where(['action' => 'compliance.record'])
            ->all()
            ->toArray();

        $this->assertCount($before + 1, $entries);
        $this->assertSame(1, (int)end($entries)->user_id);
    }

    // -----------------------------------------------------------------------
    // US 3.4: the compliance gate blocks progressing a non-compliant job
    // -----------------------------------------------------------------------

    /**
     * Attempt to move a non-compliant job to the next stage via the UI:
     * action is blocked with a clear message.
     *
     * Submission 1 has only 1 of 4 requirements met (see
     * ComplianceChecksFixture), so this is the "still outstanding" case.
     *
     * @return void
     */
    public function testAdvancingANonCompliantJobIsBlockedWithAClearMessage(): void
    {
        $submissions = TableRegistry::getTableLocator()->get('ContactSubmissions');
        $before = $submissions->get(1)->job_stage;

        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/advance/1');

        $this->assertRedirectContains('/compliance/view/1');

        // The message has to say why, not just that it failed, or the admin
        // is left guessing which requirement to go fix.
        $this->assertFlashMessage(
            'This job cannot proceed to Approved: 1 of 4 compliance requirements are met, 3 still outstanding.',
        );

        $after = $submissions->get(1)->job_stage;
        $this->assertSame($before, $after, 'A refused advance changed the stage anyway.');
    }

    /**
     * Update the job to compliant, then attempt to proceed: action is now
     * allowed.
     *
     * @return void
     */
    public function testAdvancingIsAllowedOnceTheJobIsCompliant(): void
    {
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');
        foreach (array_keys(ComplianceCheck::REQUIREMENTS) as $requirement) {
            $checks->record(1, $requirement, true, null, 1);
        }

        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/advance/1');

        $this->assertRedirectContains('/compliance/view/1');
        $this->assertFlashMessage('Moved to Approved.');

        $submissions = TableRegistry::getTableLocator()->get('ContactSubmissions');
        $this->assertSame('approved', $submissions->get(1)->job_stage);
    }

    /**
     * A GET must not be able to move the job forward, for the same reason a
     * GET must not change status or record a compliance decision.
     *
     * @return void
     */
    public function testAdvancingRequiresPost(): void
    {
        $this->productionErrors();
        $this->loginAsAdmin();
        $this->get('/compliance/advance/1');

        $this->assertResponseError();

        $submissions = TableRegistry::getTableLocator()->get('ContactSubmissions');
        $this->assertNotSame('approved', $submissions->get(1)->job_stage);
    }

    /**
     * Same access control story as recording a decision: a staff account
     * must not be able to move the workflow forward either.
     *
     * @return void
     */
    public function testNonAdminCannotAdvanceTheStage(): void
    {
        $this->productionErrors();
        $this->loginAsStaff();
        $this->enableCsrfToken();
        $this->post('/compliance/advance/1');

        $this->assertResponseCode(403);

        $submissions = TableRegistry::getTableLocator()->get('ContactSubmissions');
        $this->assertNotSame('approved', $submissions->get(1)->job_stage);
    }

    /**
     * A successful stage change is attributable, the same as every other
     * decision on this page.
     *
     * @return void
     */
    public function testAdvancingTheStageIsAudited(): void
    {
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');
        foreach (array_keys(ComplianceCheck::REQUIREMENTS) as $requirement) {
            $checks->record(1, $requirement, true, null, 1);
        }

        $auditLogs = TableRegistry::getTableLocator()->get('AuditLogs');
        $before = $auditLogs->find()->where(['action' => 'submission.stage_change'])->count();

        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/advance/1');

        $entries = $auditLogs->find()
            ->where(['action' => 'submission.stage_change'])
            ->all()
            ->toArray();

        $this->assertCount($before + 1, $entries);
        $this->assertSame(1, (int)end($entries)->user_id);
    }
}
