<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\JobRequest;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The staged job page: details, then a tickable compliance checklist, then a
 * Proceed button, then assigning a plumber and vehicle, then the plumber's
 * approval and completion, with a progress bar throughout.
 */
class JobStagesTest extends TestCase
{
    use IntegrationTestTrait;
    use TicksComplianceTrait;

    protected array $fixtures = [
        'app.Admins', 'app.Customers', 'app.JobRequests', 'app.JobAssignments',
        'app.PlumberUnavailabilities', 'app.ComplianceChecks', 'app.AuditLogs', 'app.Vehicles',
    ];

    private function asOffice(int $id, string $role): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => $id, 'admin_username' => $id === 1 ? 'hannah' : 'staffuser', 'role' => $role,
        ]]);
        $this->enableCsrfToken();
    }

    private function jobStatus(int $id = 1): string
    {
        return TableRegistry::getTableLocator()->get('JobRequests')->get($id)->status;
    }

    /** @return array{0: string, 1: string} A weekday visit start and its date. */
    private function visitTimes(): array
    {
        $day = new DateTimeImmutable('+2 days', new DateTimeZone('Australia/Melbourne'));
        while ((int)$day->format('N') > 5) {
            $day = $day->modify('+1 day');
        }

        return [$day->format('Y-m-d') . 'T09:00', $day->format('Y-m-d')];
    }

    /** Moves job 1 to approved the way the office does: tick everything, proceed. */
    private function proceedJobOne(): void
    {
        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/1');
        $this->tickAllCompliance(1);
        $this->post('/job-requests/manage/proceed/1');
    }

    public function testListOffersOnlyOpenAndNoComplianceButton(): void
    {
        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage');

        $this->assertResponseOk();
        $this->assertResponseContains('href="/job-requests/manage/1"');
        // Compliance is ticked on the job page, so the list has no filter for it either.
        $this->assertResponseNotContains('name="compliance"');
        $this->assertResponseNotContains('/compliance/view/1/job_request');
        $this->assertResponseNotContains('dash__btn--compliance');
    }

    public function testJobPageShowsProgressThenDetailsThenChecklist(): void
    {
        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/1');
        $body = (string)$this->_response->getBody();

        $this->assertResponseOk();
        $this->assertResponseContains('role="progressbar"');
        $this->assertResponseContains('12 Clayton Road');

        $progress = strpos($body, 'job-progress');
        $details = strpos($body, 'Job description');
        $checklist = strpos($body, 'Compliance checklist');
        $this->assertNotFalse($progress);
        $this->assertNotFalse($details);
        $this->assertNotFalse($checklist);
        $this->assertLessThan($details, $progress, 'The progress bar comes first.');
        $this->assertLessThan($checklist, $details, 'The job details come before the checklist.');
        $this->assertResponseNotContains('/compliance/view/1/job_request');
    }

    public function testOpeningASubmittedRequestStartsTheComplianceCheck(): void
    {
        $this->assertSame(JobRequest::STATUS_SUBMITTED, $this->jobStatus());

        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/1');

        $this->assertSame(JobRequest::STATUS_REVIEWING, $this->jobStatus());
    }

    public function testChecklistRendersOneTickPerRequirementAndHidesProceedUntilAllTicked(): void
    {
        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/1');

        $this->assertResponseContains('Plumber licence verified');
        $this->assertResponseContains('Public liability insurance current');
        $this->assertResponseContains('type="checkbox"');
        $this->assertResponseNotContains('/job-requests/manage/proceed/1');
        $this->assertResponseContains('The Proceed button appears once every requirement is ticked.');

        $this->tickAllCompliance(1);
        $this->get('/job-requests/manage/1');

        $this->assertResponseContains('/job-requests/manage/proceed/1');
        $this->assertResponseNotContains('The Proceed button appears once');
    }

    public function testTickingFromTheJobPageReturnsToTheJobPage(): void
    {
        $this->asOffice(1, 'admin');
        $this->post('/compliance/record/1/job_request', [
            'requirement' => 'permit',
            'subject_type' => 'job_request',
            'is_met' => '1',
            'return_to' => 'job',
        ]);

        $this->assertRedirect('/job-requests/manage/1');
        $check = TableRegistry::getTableLocator()->get('ComplianceChecks')->find()
            ->where(['subject_type' => 'job_request', 'submission_id' => 1, 'requirement' => 'permit'])
            ->firstOrFail();
        $this->assertTrue($check->is_met);
    }

    public function testProceedIsRefusedUntilEveryRequirementIsTicked(): void
    {
        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/1');
        $this->post('/job-requests/manage/proceed/1');

        $this->assertRedirect('/job-requests/manage/1');
        $this->assertSame(JobRequest::STATUS_REVIEWING, $this->jobStatus());
    }

    public function testApprovalCannotBypassTheChecklistThroughTheStatusRoute(): void
    {
        $this->asOffice(1, 'admin');
        $this->post('/job-requests/manage/status/1', ['status' => JobRequest::STATUS_REVIEWING]);
        $this->post('/job-requests/manage/status/1', ['status' => JobRequest::STATUS_APPROVED]);

        $this->assertSame(JobRequest::STATUS_REVIEWING, $this->jobStatus());
    }

    public function testProceedMovesTheJobOnAndIsAudited(): void
    {
        $this->proceedJobOne();

        $this->assertSame(JobRequest::STATUS_APPROVED, $this->jobStatus());
        $audit = TableRegistry::getTableLocator()->get('AuditLogs');
        $this->assertSame(1, $audit->find()->where(['action' => 'job_request.proceed'])->count());
    }

    public function testProceedCannotBeUsedOnAGetOrByNonAdmins(): void
    {
        Configure::write('debug', false);
        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/proceed/1');
        $this->assertResponseCode(405);

        $this->asOffice(2, 'staff');
        $this->post('/job-requests/manage/proceed/1');
        $this->assertResponseCode(403);
    }

    public function testAssignmentStageOnlyAppearsOnceComplianceIsCleared(): void
    {
        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/1');
        $this->assertResponseNotContains('Assign a plumber and vehicle');

        $this->proceedJobOne();
        $this->get('/job-requests/manage/1');

        $this->assertResponseContains('Assign a plumber and vehicle');
        $this->assertResponseContains('data-availability-url');
        $this->assertResponseContains('/job-requests/manage/assign/1');
    }

    public function testEachStageShowsOnlyItsOwnInformation(): void
    {
        // Stage 1: details and checklist, no assignment yet.
        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/1');
        $this->assertResponseContains('Job description');
        $this->assertResponseContains('Compliance checklist');
        $this->assertResponseNotContains('Assign a plumber and vehicle');
        $this->assertResponseNotContains('Scheduled visit');

        // Stage 2: only the assignment form. The details and the checklist are
        // not repeated underneath, just one line saying which job it is.
        $this->tickAllCompliance(1);
        $this->post('/job-requests/manage/proceed/1');
        $this->get('/job-requests/manage/1');
        $this->assertResponseContains('Assign a plumber and vehicle');
        $this->assertResponseContains('12 Clayton Road');
        $this->assertResponseNotContains('Job description');
        $this->assertResponseNotContains('Compliance checklist');
        $this->assertResponseNotContains('Access instructions');

        // Stage 3: only the assignment. No form, no checklist, no details.
        [$start] = $this->visitTimes();
        $this->post('/job-requests/manage/assign/1', [
            'plumber_admin_id' => 2, 'vehicle_id' => 1, 'scheduled_start' => $start, 'duration_hours' => 1,
        ]);
        $this->get('/job-requests/manage/1');
        $this->assertResponseContains('Scheduled visit');
        $this->assertResponseContains('Waiting for staffuser to approve this job in their portal.');
        $this->assertResponseNotContains('Assign a plumber and vehicle');
        $this->assertResponseNotContains('Compliance checklist');
        $this->assertResponseNotContains('Job description');
    }

    public function testAvailabilityListsWhoIsFreeAndWhoIsBooked(): void
    {
        $this->proceedJobOne();
        [$start] = $this->visitTimes();

        $this->get('/job-requests/manage/availability?start=' . $start . '&duration=1');
        $this->assertResponseOk();
        $free = json_decode((string)$this->_response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('staffuser', $free['plumbers'][0]['label']);
        $this->assertSame('Available', $free['plumbers'][0]['status']);
        $this->assertSame('Available', $free['vehicles'][0]['status']);

        $this->post('/job-requests/manage/assign/1', [
            'plumber_admin_id' => 2, 'vehicle_id' => 1, 'scheduled_start' => $start, 'duration_hours' => 1,
        ]);
        $this->assertSame(JobRequest::STATUS_ASSIGNED, $this->jobStatus());

        $this->get('/job-requests/manage/availability?start=' . $start . '&duration=1');
        $busy = json_decode((string)$this->_response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Busy', $busy['plumbers'][0]['status']);
        $this->assertSame('In use', $busy['vehicles'][0]['status']);
        $this->assertStringContainsString('REQ-000001', $busy['plumbers'][0]['detail']);
    }

    public function testAvailabilityShowsAPlumberWhoMarkedTimeOff(): void
    {
        [$start, $day] = $this->visitTimes();
        $this->asOffice(2, 'staff');
        $this->post('/plumbers/availability', [
            'unavailable_date' => $day, 'start_time' => '09:00', 'end_time' => '10:00',
        ]);

        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/availability?start=' . $start . '&duration=1');
        $result = json_decode((string)$this->_response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Unavailable', $result['plumbers'][0]['status']);
    }

    public function testAvailabilityRejectsAnImpossibleVisit(): void
    {
        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/availability?start=not-a-date&duration=1');

        $this->assertResponseCode(422);
    }

    public function testAvailabilityIsOfficeOnly(): void
    {
        Configure::write('debug', false);
        $this->get('/job-requests/manage/availability?start=2030-01-01T09:00&duration=1');
        $this->assertRedirectContains('/login');

        $this->asOffice(2, 'staff');
        $this->get('/job-requests/manage/availability?start=2030-01-01T09:00&duration=1');
        $this->assertResponseCode(403);
    }

    public function testPlumberSeesTheAssignedJobAsPendingTheirApproval(): void
    {
        $this->proceedJobOne();
        [$start] = $this->visitTimes();
        $this->post('/job-requests/manage/assign/1', [
            'plumber_admin_id' => 2, 'vehicle_id' => 1, 'scheduled_start' => $start, 'duration_hours' => 1,
        ]);

        $this->asOffice(2, 'staff');
        $this->get('/plumbers/dashboard');
        $this->assertResponseContains('1 job is waiting for your approval.');

        $this->get('/plumbers/jobs');
        $this->assertResponseContains('Pending approval');
        // The status is a coloured badge here too, like everywhere else.
        $this->assertResponseContains('dash__badge dash__badge--assigned');
        $this->assertResponseNotContains('Pending plumber approval');

        $this->get('/plumbers/job/1');
        $this->assertResponseContains('This job is waiting for your approval.');
        $this->assertResponseContains('Approve job');
        // The progress bar is for the office. The plumber's page stays simple.
        $this->assertResponseNotContains('role="progressbar"');
    }

    public function testApprovalCompletionAndTheAdminSeeingItCompleted(): void
    {
        $this->proceedJobOne();
        [$start] = $this->visitTimes();
        $this->post('/job-requests/manage/assign/1', [
            'plumber_admin_id' => 2, 'vehicle_id' => 1, 'scheduled_start' => $start, 'duration_hours' => 1,
        ]);

        $this->get('/job-requests/manage/1');
        $this->assertResponseContains('Waiting for staffuser to approve this job in their portal.');
        $this->assertResponseContains('Pending plumber approval');

        $this->asOffice(2, 'staff');
        $this->post('/plumbers/start-job/1');
        $this->assertSame(JobRequest::STATUS_IN_PROGRESS, $this->jobStatus());
        $this->post('/plumbers/finish-job/1', ['outcome' => JobRequest::STATUS_COMPLETED]);
        $this->assertSame(JobRequest::STATUS_COMPLETED, $this->jobStatus());

        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/1');
        $this->assertResponseContains('marked this job as completed.');
        $this->assertResponseContains('aria-valuenow="100"');

        $this->get('/job-requests/manage?status=completed');
        $this->assertResponseContains('12 Clayton Road');
    }

    public function testDeclinedRequestStopsTheProgressBarAndHidesTheChecklist(): void
    {
        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/1');
        $this->post('/job-requests/manage/status/1', ['status' => JobRequest::STATUS_DECLINED]);

        $this->get('/job-requests/manage/1');
        $this->assertResponseContains('is-stopped');
        $this->assertResponseNotContains('Compliance checklist');
    }
}
