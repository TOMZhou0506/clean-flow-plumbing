<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/** Manager job oversight is current, complete, and strictly read-only. */
class ManagerJobsControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins', 'app.Customers', 'app.JobRequests', 'app.JobAssignments',
        'app.Vehicles', 'app.AuditLogs',
    ];

    private function signIn(int $id, string $role): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => $id,
            'admin_username' => match ($id) {
                1 => 'hannah',
                2 => 'staffuser',
                default => 'manageruser',
            },
            'role' => $role,
        ]]);
    }

    private function makeAssignment(): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get(1);
        $job->set('status', 'unable_to_complete');
        $jobs->saveOrFail($job);
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $assignments->saveOrFail($assignments->newEntity([
            'job_request_id' => 1,
            'plumber_admin_id' => 2,
            'assigned_by' => 1,
            'vehicle_id' => 1,
            'scheduled_start' => '2030-09-15 00:00:00',
            'scheduled_end' => '2030-09-15 02:00:00',
            'started_at' => '2030-09-15 00:05:00',
            'finished_at' => '2030-09-15 01:30:00',
            'unable_reason' => 'The water supply could not be isolated safely.',
        ]));
        $audit = TableRegistry::getTableLocator()->get('AuditLogs');
        $audit->record('job_request.status_change', 1, 'job_request', '1', ['to' => 'approved']);
        $audit->record('job_request.assigned', 1, 'job_request', '1');
        $audit->record('job_request.unable_to_complete', 2, 'job_request', '1');
    }

    public function testManagerSeesAllJobsAndCurrentStatusAfterRefresh(): void
    {
        $this->signIn(3, 'manager');
        $this->get('/manager-jobs');
        $this->assertResponseOk();
        $this->assertResponseContains('Job overview');
        $this->assertResponseContains('12 Clayton Road');
        $this->assertResponseContains('99 Private Street');

        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get(1);
        $job->set('status', 'reviewing');
        $jobs->saveOrFail($job);
        $this->get('/manager-jobs?status=reviewing');
        $this->assertResponseOk();
        $this->assertResponseContains('12 Clayton Road');
        $this->assertResponseNotContains('99 Private Street');
        $this->assertResponseNotContains('Assign job');
    }

    public function testManagerSeesAssignmentOutcomeAndHistoryWithoutCustomerContact(): void
    {
        $this->makeAssignment();
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $assigningAdmin = $admins->get(1);
        $assigningAdmin->set('active', false);
        $admins->saveOrFail($assigningAdmin);
        $this->signIn(3, 'manager');
        $this->get('/manager-jobs/view/1');
        $this->assertResponseOk();
        $this->assertResponseContains('hannah');
        $this->assertResponseContains('staffuser');
        $this->assertResponseContains('ABC 123');
        $this->assertResponseContains('15 Sep 2030, 10:05am');
        $this->assertResponseContains('15 Sep 2030, 11:30am');
        $this->assertResponseContains('The water supply could not be isolated safely.');
        $this->assertResponseContains('Status history');
        $this->assertResponseContains('Approved');
        $this->assertResponseContains('Unable To Complete');
        $this->assertResponseNotContains('0412 345 678');
        $this->assertResponseNotContains('Please use the side gate.');
        $this->assertResponseNotContains('Start job');
    }

    public function testManagerPagesRejectOtherRolesAndManagerCannotDispatch(): void
    {
        Configure::write('debug', false);
        $this->signIn(1, 'admin');
        $this->get('/manager-jobs');
        $this->assertResponseCode(403);
        $this->signIn(2, 'staff');
        $this->get('/manager-jobs/view/1');
        $this->assertResponseCode(403);

        $this->signIn(3, 'manager');
        $this->enableCsrfToken();
        $this->get('/job-requests/manage/1');
        $this->assertResponseCode(403);
        $this->get('/job-requests/manage/assign/1');
        $this->assertResponseCode(403);
        $this->post('/job-requests/manage/status/1', ['status' => 'reviewing']);
        $this->assertResponseCode(403);
        $this->assertSame('submitted', TableRegistry::getTableLocator()->get('JobRequests')->get(1)->status);
        $this->post('/manager-jobs');
        $this->assertResponseCode(405);
    }
}
