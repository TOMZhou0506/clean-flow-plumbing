<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/** The office's plumber list links to jobs it can open; the manager's list is about accounts only. */
class PlumbersListJobLinksTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.Customers',
        'app.JobRequests',
        'app.JobAssignments',
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

    /** Plumber 2 is on job 1 right now and has job 2 booked for later. */
    private function giveThePlumberACurrentAndANextJob(): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ([[1, 'in_progress', '-1 hour', '+2 hours'], [2, 'assigned', '+2 days', '+2 days +2 hours']] as [$id, $status, $from, $to]) {
            $job = $jobs->get($id);
            $job->set('status', $status);
            $jobs->saveOrFail($job);
            $assignments->saveOrFail($assignments->newEntity([
                'job_request_id' => $id,
                'plumber_admin_id' => 2,
                'assigned_by' => 1,
                'scheduled_start' => $now->modify($from)->format('Y-m-d H:i:s'),
                'scheduled_end' => $now->modify($to)->format('Y-m-d H:i:s'),
            ]));
        }
    }

    public function testTheManagersListHasNoJobColumns(): void
    {
        $this->giveThePlumberACurrentAndANextJob();
        $this->signIn(3, 'manager');

        $this->get('/plumbers');

        // The manager oversees accounts, so what each plumber is doing is not shown.
        $this->assertResponseOk();
        $this->assertResponseContains('staffuser');
        $this->assertResponseContains('Customer rating');
        $this->assertResponseNotContains('Right now');
        $this->assertResponseNotContains('Next job');
        $this->assertResponseNotContains('Open jobs');
        $this->assertResponseNotContains('REQ-000001');
        $this->assertResponseNotContains('/job-requests/manage');
    }

    public function testTheOfficeStillLinksToTheFullJobPage(): void
    {
        $this->giveThePlumberACurrentAndANextJob();
        $this->signIn(1, 'admin');

        $this->get('/plumbers');

        $this->assertResponseOk();
        $this->assertResponseContains('href="/job-requests/manage/1"');
        $this->assertResponseContains('href="/job-requests/manage/2"');
        $this->assertResponseNotContains('/manager-jobs');
    }

    public function testEveryLinkTheManagerIsOfferedActuallyOpens(): void
    {
        $this->giveThePlumberACurrentAndANextJob();
        $this->signIn(3, 'manager');

        foreach ([1, 2] as $jobId) {
            $this->get('/manager-jobs/view/' . $jobId);
            $this->assertResponseOk('job ' . $jobId);
        }
    }
}
