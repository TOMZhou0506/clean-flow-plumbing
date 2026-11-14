<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/** Customers see their own progress and visit details, not office-only data. */
class CustomerJobStatusTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins', 'app.Customers', 'app.JobRequests', 'app.JobAssignments',
    ];

    private function signIn(int $id): void
    {
        $this->session(['CustomerAuth' => [
            'cust_id' => $id,
            'cust_email' => $id === 1 ? 'kash@example.com' : 'other@example.com',
            'cust_fname' => $id === 1 ? 'Kash' : 'Other',
            'cust_lname' => $id === 1 ? 'Patel' : 'Customer',
        ]]);
    }

    private function assignOwnJob(string $status): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get(1);
        $job->set('status', $status);
        $jobs->saveOrFail($job);
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $assignments->saveOrFail($assignments->newEntity([
            'job_request_id' => 1,
            'plumber_admin_id' => 2,
            'assigned_by' => 1,
            'scheduled_start' => '2030-09-15 00:00:00',
            'scheduled_end' => '2030-09-15 02:00:00',
            'unable_reason' => 'Private internal site assessment.',
        ]));
    }

    public function testUnassignedCustomerSeesStatusButNoConfirmedVisit(): void
    {
        $this->signIn(1);
        $this->get('/job-requests/confirmation/1');
        $this->assertResponseOk();
        $this->assertResponseContains('Request received');
        $this->assertResponseContains('Preferred service time (not confirmed)');
        $this->assertResponseNotContains('Confirmed visit');
        $this->assertResponseNotContains('Your plumber');
    }

    public function testAssignedJobShowsConfirmedVisitAndPlumberWithoutInternalData(): void
    {
        $this->assignOwnJob('assigned');
        $this->signIn(1);
        $this->get('/job-requests');
        $this->assertResponseOk();
        $this->assertResponseContains('Appointment scheduled');
        $this->assertResponseContains('Confirmed visit:');
        $this->assertResponseNotContains('99 Private Street');

        $this->get('/job-requests/confirmation/1');
        $this->assertResponseOk();
        $this->assertResponseContains('15 Sep 2030, 10:00am');
        $this->assertResponseContains('15 Sep 2030, 12:00pm');
        // The plumber's login name is internal. The customer only hears that
        // someone has been assigned.
        $this->assertResponseContains('Assigned to your visit');
        $this->assertResponseNotContains('staffuser');
        $this->assertResponseNotContains('hannah');
        $this->assertResponseNotContains('Private internal site assessment.');
        $this->assertResponseNotContains('Please use the side gate.');
    }

    public function testTheStatusPagesRefreshThemselves(): void
    {
        $this->assignOwnJob('assigned');
        $this->signIn(1);

        $this->get('/job-requests/confirmation/1');
        $this->assertResponseContains('data-live-region="status"');
        $this->assertResponseContains('/js/live-status.js');

        $this->get('/job-requests');
        $this->assertResponseContains('data-live-region="requests"');
        $this->assertResponseContains('/js/live-status.js');
    }

    public function testRefreshShowsFinalOutcomeButNoInternalReason(): void
    {
        $this->assignOwnJob('in_progress');
        $this->signIn(1);
        $this->get('/job-requests/confirmation/1');
        $this->assertResponseContains('Your plumber has started this job.');

        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get(1);
        $job->set('status', 'unable_to_complete');
        $jobs->saveOrFail($job);
        $this->get('/job-requests/confirmation/1');
        $this->assertResponseContains('Unable to complete');
        $this->assertResponseContains('We will contact you about next steps.');
        $this->assertResponseNotContains('Private internal site assessment.');
    }

    public function testCustomerCannotSeeAnotherCustomersJob(): void
    {
        Configure::write('debug', false);
        $this->signIn(1);
        $this->get('/job-requests/confirmation/2');
        $this->assertResponseCode(404);
    }
}
