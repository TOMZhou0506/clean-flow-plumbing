<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * US 3.2: the audit trail is checkable, and a non-admin cannot change
 * compliance status.
 *
 * Each test maps to one of the story's two remaining checks.
 */
class AuditLogsControllerTest extends TestCase
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
     * The audit trail belongs to the manager now, so the tests that read it
     * sign in as one. An admin is one of the people the trail records, which
     * is why they no longer get to read it.
     *
     * @return void
     */
    protected function loginAsManager(): void
    {
        $this->session([
            'AdminAuth' => [
                'admin_id' => 3,
                'admin_username' => 'manager',
                'role' => 'manager',
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
     * @return void
     */
    protected function productionErrors(): void
    {
        Configure::write('debug', false);
    }

    // -----------------------------------------------------------------------
    // Check 1: the status change is timestamped and attributed
    // -----------------------------------------------------------------------

    /**
     * The stored entry carries who and when, not just what.
     *
     * An audit row without an author is a record that something happened and
     * nobody is responsible, which is worse than no record at all because it
     * looks like accountability.
     *
     * @return void
     */
    public function testAComplianceChangeIsAttributedAndTimestamped(): void
    {
        $auditLogs = TableRegistry::getTableLocator()->get('AuditLogs');
        $before = $auditLogs->find()->count();

        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', [
            'requirement' => 'permit',
            'is_met' => '1',
            'notes' => 'Permit 998 issued.',
        ]);

        $entry = $auditLogs->find()
            ->where(['action' => 'compliance.record'])
            ->orderBy(['id' => 'DESC'])
            ->firstOrFail();

        $this->assertSame($before + 1, $auditLogs->find()->count());

        // Attributed to the admin who made it, taken from the session rather
        // than from anything the request could set.
        $this->assertSame(1, (int)$entry->user_id);

        // Timestamped.
        $this->assertNotNull($entry->created);

        // And tied to the record it changed, so the trail can be read per
        // enquiry rather than only as one long list.
        $this->assertSame('submission', $entry->entity_type);
        $this->assertSame('1', (string)$entry->entity_id);
        $this->assertStringContainsString('permit', (string)$entry->detail);
    }

    /**
     * The trail is visible on the compliance page, with the admin and time.
     *
     * @return void
     */
    public function testTheCompliancePageShowsWhoChangedWhatAndWhen(): void
    {
        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', [
            'requirement' => 'permit',
            'is_met' => '1',
        ]);

        $this->get('/compliance/view/1');

        $this->assertResponseOk();
        $this->assertResponseContains('Recent activity');
        $this->assertResponseContains('compliance.record');
        $this->assertResponseContains('Admin #1');
    }

    /**
     * @return void
     */
    public function testTheAuditTrailPageListsEntriesWithAdminAndTime(): void
    {
        // Two roles in one test, deliberately. The admin does the thing worth
        // recording, and the manager is the only one who can read the record
        // of it. That split is the point of the change.
        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', ['requirement' => 'permit', 'is_met' => '1']);

        $this->loginAsManager();
        $this->get('/audit-logs');

        $this->assertResponseOk();
        $this->assertResponseContains('Audit trail');
        $this->assertResponseContains('compliance.record');
        $this->assertResponseContains('Admin #1');
    }

    /**
     * Filtering by action narrows the list rather than being decorative.
     *
     * @return void
     */
    public function testTheAuditTrailCanBeFilteredByAction(): void
    {
        $this->loginAsAdmin();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', ['requirement' => 'permit', 'is_met' => '1']);

        // Opening an enquiry writes a submission.view entry, so there are two
        // different actions present to filter between.
        $this->get('/contact-submissions/view/1');
        $this->loginAsManager();
        $this->get('/audit-logs?action=compliance.record');

        $this->assertResponseOk();

        // I assert on the table cell, not the bare action name.
        //
        // My first version checked that 'submission.view' was absent from the
        // whole body and failed, correctly. The filter dropdown is populated
        // from knownActions(), so every action in the database appears on the
        // page as an <option> whether or not it matches the filter. The plain
        // string was always going to be there. Matching the cell markup tests
        // the rows, which is what the filter actually controls.
        $this->assertResponseContains('<td>compliance.record</td>');
        $this->assertResponseNotContains('<td>submission.view</td>');
    }

    /**
     * A sort field outside the allowlist is ignored rather than reaching
     * ORDER BY.
     *
     * @return void
     */
    public function testAnArbitrarySortFieldIsIgnored(): void
    {
        $this->loginAsManager();
        $this->get('/audit-logs?sort=ip_address&direction=asc');

        // The page still renders. If the field had reached the query builder
        // unchecked this would be an error instead.
        $this->assertResponseOk();
    }

    /**
     * The trail is read only. There is no route that edits or deletes an
     * entry, and there should never be one.
     *
     * @return void
     */
    public function testTheAuditTrailCannotBeEditedOrDeletedThroughTheApp(): void
    {
        $this->loginAsAdmin();
        $this->enableCsrfToken();

        // The fixture is deliberately empty, so I create a real entry to try
        // to tamper with rather than seeding one.
        $this->post('/compliance/record/1', ['requirement' => 'permit', 'is_met' => '1']);

        $auditLogs = TableRegistry::getTableLocator()->get('AuditLogs');
        $entry = $auditLogs->find()->orderBy(['id' => 'DESC'])->firstOrFail();
        $id = $entry->id;

        // Switch to the manager before trying to tamper. As an admin these
        // URLs would now be refused for the wrong reason, and the test would
        // pass while proving nothing about whether the actions exist.
        $this->loginAsManager();
        $this->productionErrors();

        foreach (["/audit-logs/edit/{$id}", "/audit-logs/delete/{$id}", '/audit-logs/add'] as $url) {
            $this->post($url, ['action' => 'tampered']);
            $this->assertResponseError(sprintf('%s should not exist.', $url));
        }

        $after = $auditLogs->get($id);
        $this->assertSame('compliance.record', $after->action, 'An audit entry was modified.');
        $this->assertSame(1, (int)$after->user_id);
    }

    // -----------------------------------------------------------------------
    // Check 2: a non-admin cannot update compliance status
    // -----------------------------------------------------------------------

    /**
     * @return void
     */
    public function testNonAdminCannotUpdateComplianceStatus(): void
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
            $checks->find()->where(['submission_id' => 1, 'requirement' => 'permit'])->count(),
            'A staff account changed a compliance status.',
        );
    }

    /**
     * A blocked attempt must not leave a half-written audit entry either. An
     * entry recording a change that never happened would make the trail
     * actively misleading.
     *
     * @return void
     */
    public function testABlockedAttemptDoesNotWriteAComplianceRecordEntry(): void
    {
        $auditLogs = TableRegistry::getTableLocator()->get('AuditLogs');
        $before = $auditLogs->find()->where(['action' => 'compliance.record'])->count();

        $this->productionErrors();
        $this->loginAsStaff();
        $this->enableCsrfToken();
        $this->post('/compliance/record/1', ['requirement' => 'permit', 'is_met' => '1']);

        $this->assertSame(
            $before,
            $auditLogs->find()->where(['action' => 'compliance.record'])->count(),
        );
    }

    /**
     * The trail itself is customer data plus a record of who read it, so
     * reading it is manager only.
     *
     * @return void
     */
    public function testNonAdminCannotReadTheAuditTrail(): void
    {
        $this->productionErrors();
        $this->loginAsStaff();
        $this->get('/audit-logs');

        $this->assertResponseCode(403);
        $this->assertResponseNotContains('compliance.record');
    }

    /**
     * An admin cannot read the audit trail either.
     *
     * This is the change the team asked for and the one most worth a test of
     * its own. An admin is one of the people the trail exists to record, so
     * letting them read it lets them check whether anyone would notice what
     * they did. The separation is the reason for keeping the log at all.
     *
     * @return void
     */
    public function testAnAdminCannotReadTheAuditTrail(): void
    {
        $this->productionErrors();
        $this->loginAsAdmin();
        $this->get('/audit-logs');

        $this->assertResponseCode(403);
        $this->assertResponseNotContains('compliance.record');
    }

    /**
     * And the nav does not offer them a link to it.
     *
     * A link that only ever produces a 403 teaches people to ignore refusals,
     * which is the opposite of what an access rule is for.
     *
     * @return void
     */
    public function testTheAuditLinkIsHiddenFromAdmins(): void
    {
        $this->loginAsAdmin();
        $this->get('/contact-submissions');

        $this->assertResponseOk();
        $this->assertResponseNotContains('/audit-logs');
        $this->assertResponseNotContains('Audit trail');
    }

    /**
     * @return void
     */
    public function testLoggedOutCannotReadTheAuditTrail(): void
    {
        $this->productionErrors();
        $this->get('/audit-logs');

        // The shared sign-in page now, not the admin-only one.
        $this->assertRedirectContains('/login');
        $this->assertResponseNotContains('compliance.record');
    }
}
