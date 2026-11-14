<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller\Api;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * Api\SubmissionsController tests.
 *
 * I wrote the failure paths first. A suite that only proves the happy path
 * works tells me nothing about whether the access control does.
 *
 * Run with:  vendor/bin/phpunit tests/TestCase/Controller/Api/
 */
class SubmissionsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.ContactSubmissions',
        'app.AuditLogs',
        'app.Admins',
    ];

    /**
     * Simulates a signed-in admin.
     *
     * I shape this like an admins record, since that is the identity model
     * after the merge with main. It used to carry an extra 'id' key as well,
     * because that is what Authentication\Identity::getIdentifier() reads by
     * default, and the controllers were calling it. That was the wrong way
     * round: admins has no id column, so the fixture was propping up a method
     * that returned null on every real login. The controllers read admin_id
     * now, and this matches what the session actually holds.
     */
    protected function login(): void
    {
        $this->session([
            'AdminAuth' => [
                'admin_id' => 1,
                'admin_username' => 'hannah',
                // The API returns the same customer data as the dashboard, so
                // it enforces the same admin role. Without this key every
                // request here would be a 403.
                'role' => 'admin',
            ],
        ]);
    }

    // -----------------------------------------------------------------------
    // Access control
    // -----------------------------------------------------------------------

    /**
     * Renders errors the way production does.
     *
     * This caught me out. With debug on, CakePHP's error page dumps every
     * stack frame's arguments: templates/element/exception_stack_trace.php
     * passes 'args' => true to Debugger::formatTrace and then exportVars each
     * one four levels deep. That walk reaches the loaded fixture records, so
     * the page contains strings from my seed data even though the controller
     * never ran.
     *
     * It is a dev-tooling artefact rather than a leak, since production runs
     * debug off and returns the plain error template. Turning debug off here
     * means the two assertions below test the response a real anonymous caller
     * gets.
     *
     * Configure is snapshotted in TestCase::setUp and restored in tearDown, so
     * this does not bleed into my other tests.
     *
     * @return void
     */
    protected function renderErrorsAsProduction(): void
    {
        Configure::write('debug', false);
    }

    public function testIndexRequiresAuthentication(): void
    {
        $this->renderErrorsAsProduction();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions');

        // The assertion I care about is that this is NOT 200 with data. An
        // anonymous caller must never receive customer contact details.
        $this->assertResponseError();
        $this->assertResponseNotContains('marcus.bell@example.com');
    }

    public function testViewRequiresAuthentication(): void
    {
        $this->renderErrorsAsProduction();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions/1');

        $this->assertResponseError();
        $this->assertResponseNotContains('Water is pooling');
    }

    // -----------------------------------------------------------------------
    // Listing and filtering
    // -----------------------------------------------------------------------

    public function testIndexReturnsSubmissions(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions');

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);

        $this->assertTrue($body['ok']);
        $this->assertCount(5, $body['data']);
        $this->assertSame(5, $body['meta']['total']);
    }

    public function testIndexFiltersByStatus(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions?status=archived');

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);

        $this->assertCount(1, $body['data']);
        $this->assertSame('Tom Whitfield', $body['data'][0]['name']);
    }

    public function testIndexIgnoresUnknownStatus(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        // An injection attempt in the status parameter must not filter, error
        // or reach the query. My status check simply ignores it.
        $this->get("/api/submissions?status=' OR '1'='1");

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(5, $body['data']);
    }

    public function testIndexSortInjectionFallsBackToDefault(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions?sort=id;DROP TABLE contact_submissions--');

        // sortableFields rejects the field, so the request succeeds with my
        // default ordering rather than executing anything.
        $this->assertResponseOk();
    }

    public function testIndexEscapesLikeWildcards(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        // A literal % must not act as a wildcard matching every row.
        $this->get('/api/submissions?q=%25');

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(0, $body['data']);
    }

    public function testPerPageIsClamped(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions?per_page=99999');

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertLessThanOrEqual(100, $body['meta']['per_page']);
    }

    // -----------------------------------------------------------------------
    // Single record
    // -----------------------------------------------------------------------

    public function testViewReturnsFullMessage(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions/1');

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);

        $this->assertSame('Marcus Bell', $body['data']['name']);
        $this->assertStringContainsString('Water is pooling', $body['data']['message']);
    }

    public function testViewDoesNotLeakIpAddress(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions/1');

        $this->assertResponseOk();
        // The submitter's IP is personal data with no purpose in my UI.
        $this->assertResponseNotContains('203.0.113.10');
    }

    public function testViewRejectsNonNumericId(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions/abc');

        // My route's \d+ pattern means this never reaches the controller.
        $this->assertResponseError();
    }

    public function testViewMissingRecordReturns404(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions/9999');

        $this->assertResponseCode(404);
    }

    // -----------------------------------------------------------------------
    // Status changes
    // -----------------------------------------------------------------------

    public function testUpdateStatusRequiresCsrfToken(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        // Deliberately NOT calling enableCsrfToken().
        $this->patch('/api/submissions/1', ['status' => 'archived']);

        $this->assertResponseError();
    }

    public function testUpdateStatusChangesStatus(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->patch('/api/submissions/1', ['status' => 'archived']);

        $this->assertResponseOk();

        $submissions = $this->getTableLocator()->get('ContactSubmissions');
        $this->assertSame('archived', $submissions->get(1)->status);
    }

    public function testUpdateStatusStampsReadFields(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->patch('/api/submissions/1', ['status' => 'read']);

        $this->assertResponseOk();

        $submission = $this->getTableLocator()->get('ContactSubmissions')->get(1);
        $this->assertNotNull($submission->read_at);
        $this->assertSame(1, $submission->read_by);
    }

    public function testUpdateStatusDoesNotRewriteExistingReadAt(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);

        // Record 2 was already read at 10:00. Moving it must not overwrite
        // when it was first opened.
        $this->patch('/api/submissions/2', ['status' => 'archived']);

        $this->assertResponseOk();

        $submission = $this->getTableLocator()->get('ContactSubmissions')->get(2);
        $this->assertSame('2026-08-16 10:00:00', $submission->read_at->format('Y-m-d H:i:s'));
    }

    public function testUpdateStatusRejectsUnknownStatus(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->patch('/api/submissions/1', ['status' => 'deleted']);

        $this->assertResponseCode(422);
    }

    // -----------------------------------------------------------------------
    // Bulk
    // -----------------------------------------------------------------------

    public function testBulkStatusRejectsOversizedBatch(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->post('/api/submissions/bulk-status', [
            'ids' => range(1, 201),
            'status' => 'archived',
        ]);

        $this->assertResponseCode(422);
    }

    public function testBulkStatusRejectsEmptyIds(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->post('/api/submissions/bulk-status', ['ids' => [], 'status' => 'read']);

        $this->assertResponseCode(422);
    }

    public function testBulkStatusCountsOnlyActualChanges(): void
    {
        $this->login();
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);

        // Record 3 is already archived, so only 1 and 2 should change.
        $this->post('/api/submissions/bulk-status', [
            'ids' => [1, 2, 3],
            'status' => 'archived',
        ]);

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(2, $body['data']['changed']);
    }

    // -----------------------------------------------------------------------
    // Export
    // -----------------------------------------------------------------------

    public function testExportNeutralisesFormulas(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'text/csv']]);
        $this->get('/api/submissions/export');

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();

        // The tab prefix I add is what stops Excel evaluating the cell.
        $this->assertStringContainsString("\t=cmd", $body);
        $this->assertStringContainsString("\t@SUM", $body);
    }

    public function testExportIsAudited(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'text/csv']]);
        $this->get('/api/submissions/export');

        $this->assertResponseOk();

        $count = $this->getTableLocator()->get('AuditLogs')
            ->find()
            ->where(['action' => 'submission.export'])
            ->count();

        $this->assertSame(1, $count);
    }

    // -----------------------------------------------------------------------
    // Stats
    // -----------------------------------------------------------------------

    public function testStatsReturnsCounts(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions/stats');

        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);

        $this->assertSame(5, $body['data']['total']);
        $this->assertSame(3, $body['data']['new']);
        $this->assertSame(1, $body['data']['read']);
        $this->assertSame(1, $body['data']['archived']);
    }

    public function testStatsRouteIsNotParsedAsAnId(): void
    {
        $this->login();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->get('/api/submissions/stats');

        // A regression guard I added: if the {id} route is declared before
        // /stats, this returns 400 because "stats" fails my id check.
        $this->assertResponseOk();
        $this->assertResponseContains('total');
    }
}
