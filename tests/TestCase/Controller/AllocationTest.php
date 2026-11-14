<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\JobRequest;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Changing and removing a job's allocation, the warnings when a plumber or
 * vehicle is double booked, and the plumber list showing who is where.
 */
class AllocationTest extends TestCase
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

    /** @return string The date of a weekday a couple of days away. */
    private function visitDay(): string
    {
        $day = new DateTimeImmutable('+2 days', new DateTimeZone('Australia/Melbourne'));
        while ((int)$day->format('N') > 5) {
            $day = $day->modify('+1 day');
        }

        return $day->format('Y-m-d');
    }

    /** Takes a job from the compliance check to approved, as the office does. */
    private function approve(int $id): void
    {
        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/' . $id);
        $this->tickAllCompliance($id);
        $this->post('/job-requests/manage/proceed/' . $id);
    }

    private function assignJob(int $id, string $time, int $hours = 1): void
    {
        $this->post('/job-requests/manage/assign/' . $id, [
            'plumber_admin_id' => 2,
            'vehicle_id' => 1,
            'scheduled_start' => $this->visitDay() . 'T' . $time,
            'duration_hours' => $hours,
        ]);
    }

    private function assignmentCount(int $jobId = 1): int
    {
        return TableRegistry::getTableLocator()->get('JobAssignments')->find()
            ->where(['job_request_id' => $jobId])->count();
    }

    public function testAnAssignedJobOffersChangeAndRemoveButAStartedOneDoesNot(): void
    {
        $this->approve(1);
        $this->assignJob(1, '09:00');

        $this->get('/job-requests/manage/1');
        $this->assertResponseContains('Change allocation');
        $this->assertResponseContains('Remove allocation');
        $this->assertResponseContains('/job-requests/manage/unassign/1');
        // A visit time reads as one range, with no stray space after the dash.
        $this->assertResponseContains('9:00am–10:00am');

        $this->asOffice(2, 'staff');
        $this->post('/plumbers/start-job/1');

        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage/1');
        $this->assertResponseNotContains('Change allocation');
        $this->assertResponseNotContains('Remove allocation');
    }

    public function testTheChangeFormStartsOnTheCurrentAllocation(): void
    {
        $this->approve(1);
        $this->assignJob(1, '09:00', 2);

        $this->get('/job-requests/manage/assign/1');

        $this->assertResponseOk();
        $this->assertResponseContains('Change allocation for job #1');
        $this->assertResponseContains('value="' . $this->visitDay() . 'T09:00"');
        // Plumbers are radio cards and the duration is a slider; both start on the current choice.
        $this->assertResponseRegExp('/name="plumber_admin_id" value="2"\s+checked/');
        $this->assertResponseContains('staffuser');
        $this->assertResponseRegExp('/name="duration_hours"[^>]*value="2"/');
        $this->assertResponseContains('Save changes');
        $this->assertResponseContains('data-job-id="1"');
    }

    public function testAllocationCanBeChangedAndStaysOneBookingOnTheSameJob(): void
    {
        $this->approve(1);
        $this->assignJob(1, '09:00');

        // Moving to an overlapping time must not clash with its own old booking.
        $this->assignJob(1, '09:30', 2);

        $this->assertRedirect(['action' => 'manageView', 1]);
        $this->assertSame(JobRequest::STATUS_ASSIGNED, $this->jobStatus());
        $this->assertSame(1, $this->assignmentCount());
        $assignment = TableRegistry::getTableLocator()->get('JobAssignments')->find()
            ->where(['job_request_id' => 1])->firstOrFail();
        $this->assertSame(
            $this->visitDay() . 'T09:30',
            $assignment->scheduled_start->setTimezone(new DateTimeZone('Australia/Melbourne'))
                ->format('Y-m-d\TH:i'),
        );
        $audit = TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'job_request.reassigned'])->count();
        $this->assertSame(1, $audit);
    }

    public function testAllocationCannotBeChangedOnceThePlumberHasStarted(): void
    {
        $this->approve(1);
        $this->assignJob(1, '09:00');
        $this->asOffice(2, 'staff');
        $this->post('/plumbers/start-job/1');

        $this->asOffice(1, 'admin');
        $this->assignJob(1, '13:00');

        $this->assertResponseContains('can no longer be changed');
        $assignment = TableRegistry::getTableLocator()->get('JobAssignments')->find()
            ->where(['job_request_id' => 1])->firstOrFail();
        $this->assertSame(
            $this->visitDay() . 'T09:00',
            $assignment->scheduled_start->setTimezone(new DateTimeZone('Australia/Melbourne'))
                ->format('Y-m-d\TH:i'),
        );
    }

    public function testRemovingAnAllocationSendsTheJobBackToReadyToAssign(): void
    {
        $this->approve(1);
        $this->assignJob(1, '09:00');
        $this->assertSame(1, $this->assignmentCount());

        $this->post('/job-requests/manage/unassign/1');

        $this->assertRedirect(['action' => 'manageView', 1]);
        $this->assertSame(JobRequest::STATUS_APPROVED, $this->jobStatus());
        $this->assertSame(0, $this->assignmentCount());

        // The assignment form is back, and the plumber no longer has the job.
        $this->get('/job-requests/manage/1');
        $this->assertResponseContains('Assign a plumber and vehicle');
        $this->assertResponseNotContains('Remove allocation');
        $this->asOffice(2, 'staff');
        $this->get('/plumbers/jobs');
        $this->assertResponseNotContains('12 Clayton Road');

        $this->assertSame(1, TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'job_request.unassigned'])->count());
    }

    public function testRemovingIsRefusedOnceStartedAndOnlyByPost(): void
    {
        $this->approve(1);
        $this->assignJob(1, '09:00');

        $this->get('/job-requests/manage/unassign/1');
        $this->assertResponseCode(405);
        $this->assertSame(1, $this->assignmentCount());

        $this->asOffice(2, 'staff');
        $this->post('/plumbers/start-job/1');
        $this->asOffice(1, 'admin');
        $this->post('/job-requests/manage/unassign/1');

        $this->assertSame(JobRequest::STATUS_IN_PROGRESS, $this->jobStatus());
        $this->assertSame(1, $this->assignmentCount());
    }

    public function testAClashNamesTheJobAndWhenItIs(): void
    {
        $this->approve(1);
        $this->assignJob(1, '09:00');
        $this->approve(2);

        // Same plumber and vehicle, overlapping time.
        $this->assignJob(2, '09:30');

        $this->assertResponseContains('This vehicle is already on job REQ-000001');
        $this->assertResponseContains('9:00am–10:00am');
        $this->assertResponseContains('Choose a different time or another vehicle.');
        $this->assertSame(JobRequest::STATUS_APPROVED, $this->jobStatus(2));
    }

    public function testAvailabilityIgnoresTheJobsOwnBookingWhenItIsBeingChanged(): void
    {
        $this->approve(1);
        $this->assignJob(1, '09:00');

        $query = '?start=' . $this->visitDay() . 'T09:00&duration=1';

        $this->get('/job-requests/manage/availability' . $query);
        $busy = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Busy', $busy['plumbers'][0]['status']);
        $this->assertSame('Already on job REQ-000001', $busy['plumbers'][0]['detail']);

        $this->get('/job-requests/manage/availability' . $query . '&job=1');
        $own = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame('Available', $own['plumbers'][0]['status']);
        $this->assertSame('Available', $own['vehicles'][0]['status']);
    }

    public function testThePlumberListShowsWhoIsFreeAndWhatIsNext(): void
    {
        $this->asOffice(1, 'admin');
        $this->get('/plumbers');
        $this->assertResponseOk();
        $this->assertResponseContains('Available');
        $this->assertResponseContains('Nothing booked');

        // Allocating a job is reflected straight away on the list.
        $this->approve(1);
        $this->assignJob(1, '09:00');
        $this->get('/plumbers');

        $this->assertResponseContains('REQ-000001');
        $this->assertResponseContains('/job-requests/manage/1');
    }

    public function testThePlumberListShowsAPlumberWhoIsOnAJobRightNow(): void
    {
        $utc = new DateTimeZone('UTC');
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $assignment = $assignments->newEmptyEntity();
        $assignment->set('job_request_id', 1);
        $assignment->set('plumber_admin_id', 2);
        $assignment->set('assigned_by', 1);
        $assignment->set('vehicle_id', 1);
        $assignment->set('scheduled_start', (new DateTimeImmutable('-30 minutes', $utc))->format('Y-m-d H:i:s'));
        $assignment->set('scheduled_end', (new DateTimeImmutable('+30 minutes', $utc))->format('Y-m-d H:i:s'));
        $assignments->saveOrFail($assignment);
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get(1);
        $job->set('status', JobRequest::STATUS_ASSIGNED);
        $jobs->saveOrFail($job);

        $this->asOffice(1, 'admin');
        $this->get('/plumbers');

        $this->assertResponseContains('On a job');
        $this->assertResponseContains('REQ-000001');
        $this->assertResponseContains('until ');
    }
}
