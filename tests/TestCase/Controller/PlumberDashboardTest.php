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
 * The plumber's landing page: headline numbers, what needs approving, what is
 * on today, what is coming up, and what has been finished.
 */
class PlumberDashboardTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins', 'app.Customers', 'app.JobRequests', 'app.JobAssignments',
        'app.PlumberUnavailabilities', 'app.ComplianceChecks', 'app.AuditLogs', 'app.Vehicles',
    ];

    private function asPlumber(): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => 2, 'admin_username' => 'staffuser', 'role' => 'staff',
        ]]);
    }

    /**
     * Gives a job request an assignment and a status, the way the office and the
     * plumber would have between them.
     *
     * @param int $jobId The job request.
     * @param int $plumberId Who it is assigned to.
     * @param string $status The job's status.
     * @param string $when A strtotime() expression, read in Melbourne time.
     * @param bool $finished Whether the plumber has finished it.
     * @return void
     */
    private function assign(int $jobId, int $plumberId, string $status, string $when, bool $finished = false): void
    {
        $melbourne = new DateTimeZone('Australia/Melbourne');
        $utc = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable($when, $melbourne))->setTimezone($utc);

        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $assignment = $assignments->newEmptyEntity();
        $assignment->set('job_request_id', $jobId);
        $assignment->set('plumber_admin_id', $plumberId);
        $assignment->set('assigned_by', 1);
        $assignment->set('vehicle_id', 1);
        $assignment->set('scheduled_start', $start->format('Y-m-d H:i:s'));
        $assignment->set('scheduled_end', $start->modify('+1 hour')->format('Y-m-d H:i:s'));
        if ($finished) {
            $assignment->set('finished_at', (new DateTimeImmutable('now', $utc))->format('Y-m-d H:i:s'));
        }
        $assignments->saveOrFail($assignment);

        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get($jobId);
        $job->set('status', $status);
        $jobs->saveOrFail($job);
    }

    public function testEmptyDashboardSaysThereIsNothingYet(): void
    {
        $this->asPlumber();
        $this->get('/plumbers/dashboard');

        $this->assertResponseOk();
        $this->assertResponseContains('Plumber dashboard');
        $this->assertResponseContains('staffuser');
        $this->assertResponseContains('Nothing scheduled for today.');
        $this->assertResponseContains('No upcoming jobs yet.');
        $this->assertResponseContains('Jobs you complete will appear here.');
        $this->assertResponseNotContains('waiting for your approval');
    }

    public function testDashboardShowsPendingTodayAndComingUpJobs(): void
    {
        // One job on today, awaiting approval; one in three days, also awaiting.
        $this->assign(1, 2, JobRequest::STATUS_ASSIGNED, 'today 15:00');
        $this->assign(2, 2, JobRequest::STATUS_ASSIGNED, '+3 days 09:00');

        $this->asPlumber();
        $this->get('/plumbers/dashboard');

        $this->assertResponseOk();
        $this->assertResponseContains('2 jobs are waiting for your approval.');
        $this->assertResponseContains('12 Clayton Road');
        $this->assertResponseContains('99 Private Street');
        $this->assertResponseNotContains('Nothing scheduled for today.');
        $this->assertResponseNotContains('No upcoming jobs yet.');
        $this->assertResponseContains('Pending approval');
        $this->assertResponseNotContains('Pending plumber approval');
        $this->assertResponseContains('/plumbers/job/1');
    }

    public function testDashboardShowsFinishedJobsAndCountsThem(): void
    {
        $this->assign(1, 2, JobRequest::STATUS_COMPLETED, '-2 days 10:00', true);

        $this->asPlumber();
        $this->get('/plumbers/dashboard');

        $this->assertResponseOk();
        $this->assertResponseContains('Recently finished');
        $this->assertResponseContains('12 Clayton Road');
        $this->assertResponseContains('Completed');
        $this->assertResponseNotContains('Jobs you complete will appear here.');
        $this->assertResponseNotContains('waiting for your approval');
    }

    public function testDashboardNeverShowsAnotherPlumbersJobs(): void
    {
        // Job 2 belongs to the admin account, not to this plumber.
        $this->assign(2, 1, JobRequest::STATUS_ASSIGNED, 'today 15:00');

        $this->asPlumber();
        $this->get('/plumbers/dashboard');

        $this->assertResponseOk();
        $this->assertResponseNotContains('99 Private Street');
        $this->assertResponseContains('Nothing scheduled for today.');
        $this->assertResponseNotContains('waiting for your approval');
    }
}
