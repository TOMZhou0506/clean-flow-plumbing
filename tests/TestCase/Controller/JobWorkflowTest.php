<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Entity\JobRequest;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

class JobWorkflowTest extends TestCase
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

    private function approve(): void
    {
        $this->asOffice(1, 'admin');
        $this->post('/job-requests/manage/status/1', ['status' => JobRequest::STATUS_REVIEWING]);
        $this->tickAllCompliance(1);
        $this->post('/job-requests/manage/status/1', ['status' => JobRequest::STATUS_APPROVED]);
    }

    private function visitTimes(): array
    {
        $day = new DateTimeImmutable('+2 days', new DateTimeZone('Australia/Melbourne'));
        while ((int)$day->format('N') > 5) {
            $day = $day->modify('+1 day');
        }

        return [$day->format('Y-m-d') . 'T09:00', $day->format('Y-m-d') . 'T10:00'];
    }

    public function testAdminCannotSkipReviewOrSetPlumberOutcome(): void
    {
        $this->asOffice(1, 'admin');
        $this->post('/job-requests/manage/status/1', ['status' => JobRequest::STATUS_APPROVED]);
        $this->post('/job-requests/manage/status/1', ['status' => JobRequest::STATUS_COMPLETED]);
        $this->assertSame(JobRequest::STATUS_SUBMITTED, TableRegistry::getTableLocator()->get('JobRequests')->get(1)->status);
    }

    public function testAssignedPlumberCanCompleteButCannotSeeOthersJob(): void
    {
        $this->approve();
        [$start] = $this->visitTimes();
        $this->post('/job-requests/manage/assign/1', [
            'plumber_admin_id' => 2, 'vehicle_id' => 1, 'scheduled_start' => $start, 'duration_hours' => 1,
        ]);
        $this->assertSame(JobRequest::STATUS_ASSIGNED, TableRegistry::getTableLocator()->get('JobRequests')->get(1)->status);
        $assignment = TableRegistry::getTableLocator()->get('JobAssignments')->find()
            ->where(['job_request_id' => 1])->firstOrFail();
        $this->assertSame(
            $start,
            $assignment->scheduled_start->setTimezone(new DateTimeZone('Australia/Melbourne'))
                ->format('Y-m-d\TH:i'),
        );

        $this->asOffice(2, 'staff');
        $this->get('/plumbers/job/1');
        $this->assertResponseOk();
        $this->assertResponseContains('0412 345 678');
        $this->get('/plumbers/job/2');
        $this->assertResponseCode(404);

        $this->post('/plumbers/start-job/1');
        $this->assertSame(JobRequest::STATUS_IN_PROGRESS, TableRegistry::getTableLocator()->get('JobRequests')->get(1)->status);
        $this->post('/plumbers/finish-job/1', [
            'outcome' => JobRequest::STATUS_UNABLE_TO_COMPLETE, 'unable_reason' => 'short',
        ]);
        $this->assertSame(JobRequest::STATUS_IN_PROGRESS, TableRegistry::getTableLocator()->get('JobRequests')->get(1)->status);
        $this->post('/plumbers/finish-job/1', [
            'outcome' => JobRequest::STATUS_UNABLE_TO_COMPLETE,
            'unable_reason' => 'The site water supply was inaccessible.',
        ]);
        $this->assertSame(JobRequest::STATUS_UNABLE_TO_COMPLETE, TableRegistry::getTableLocator()->get('JobRequests')->get(1)->status);
        $this->assertNull(TableRegistry::getTableLocator()->get('JobAssignments')->vehicleConflict(
            1,
            $assignment->scheduled_start->format('Y-m-d H:i:s'),
            $assignment->scheduled_end->format('Y-m-d H:i:s'),
        ));

        $this->asOffice(1, 'admin');
        $this->get('/job-requests/manage?status=unable_to_complete');
        $this->assertResponseOk();
        $this->assertResponseContains('12 Clayton Road');
        $this->assertResponseNotContains('99 Private Street');
        $this->get('/job-requests/manage/1');
        $this->assertResponseContains('The site water supply was inaccessible.');

        unset($this->_session['AdminAuth']);
        $this->session(['CustomerAuth' => [
            'cust_id' => 1, 'cust_email' => 'kash@example.com',
            'cust_fname' => 'Kash', 'cust_lname' => 'Patel',
        ]]);
        $this->get('/job-requests');
        $this->assertResponseContains('request-status--unable_to_complete');
    }

    public function testUnavailableTimeAndDoubleBookingAreRejected(): void
    {
        $this->approve();
        [$start] = $this->visitTimes();
        $day = substr($start, 0, 10);

        $this->asOffice(2, 'staff');
        $this->post('/plumbers/availability', [
            'unavailable_date' => $day, 'start_time' => '09:00', 'end_time' => '10:00',
        ]);
        $this->asOffice(1, 'admin');
        $this->post('/job-requests/manage/assign/1', [
            'plumber_admin_id' => 2, 'vehicle_id' => 1, 'scheduled_start' => $start, 'duration_hours' => 1,
        ]);
        $this->assertSame(JobRequest::STATUS_APPROVED, TableRegistry::getTableLocator()->get('JobRequests')->get(1)->status);

        $this->post('/job-requests/manage/assign/1', [
            'plumber_admin_id' => 2, 'vehicle_id' => 1, 'scheduled_start' => $day . 'T10:00', 'duration_hours' => 1,
        ]);
        $this->assertSame(JobRequest::STATUS_ASSIGNED, TableRegistry::getTableLocator()->get('JobRequests')->get(1)->status);

        $this->post('/job-requests/manage/status/2', ['status' => JobRequest::STATUS_REVIEWING]);
        $this->tickAllCompliance(2);
        $this->post('/job-requests/manage/status/2', ['status' => JobRequest::STATUS_APPROVED]);
        $this->post('/job-requests/manage/assign/2', [
            'plumber_admin_id' => 2, 'vehicle_id' => 1, 'scheduled_start' => $day . 'T10:30', 'duration_hours' => 1,
        ]);
        $this->assertSame(JobRequest::STATUS_APPROVED, TableRegistry::getTableLocator()->get('JobRequests')->get(2)->status);
    }

    public function testAssignedJobCannotBeMarkedUnavailableOrPlumberDeactivated(): void
    {
        $this->approve();
        [$start] = $this->visitTimes();
        $this->post('/job-requests/manage/assign/1', [
            'plumber_admin_id' => 2, 'vehicle_id' => 1, 'scheduled_start' => $start, 'duration_hours' => 1,
        ]);

        $this->asOffice(2, 'staff');
        $this->post('/plumbers/availability', [
            'unavailable_date' => substr($start, 0, 10), 'start_time' => '09:00', 'end_time' => '10:00',
        ]);
        $this->assertSame(0, TableRegistry::getTableLocator()->get('PlumberUnavailabilities')->find()->count());

        $this->asOffice(1, 'admin');
        $this->post('/plumbers/deactivate/2');
        $this->assertTrue((bool)TableRegistry::getTableLocator()->get('Admins')->get(2)->active);
    }

    public function testVehicleCannotBeDoubleBookedButCanBeReusedLater(): void
    {
        $admins = TableRegistry::getTableLocator()->get('Admins');
        $anotherPlumber = $admins->get(3);
        $anotherPlumber->set('role', 'staff');
        $admins->saveOrFail($anotherPlumber);
        $this->approve();
        [$start] = $this->visitTimes();
        $day = substr($start, 0, 10);
        $this->post('/job-requests/manage/assign/1', [
            'plumber_admin_id' => 2, 'vehicle_id' => 1, 'scheduled_start' => $start, 'duration_hours' => 1,
        ]);
        $this->assertSame(JobRequest::STATUS_ASSIGNED, TableRegistry::getTableLocator()->get('JobRequests')->get(1)->status);

        $this->post('/job-requests/manage/status/2', ['status' => JobRequest::STATUS_REVIEWING]);
        $this->tickAllCompliance(2);
        $this->post('/job-requests/manage/status/2', ['status' => JobRequest::STATUS_APPROVED]);
        $this->post('/job-requests/manage/assign/2', [
            'plumber_admin_id' => 3, 'vehicle_id' => 1,
            'scheduled_start' => $day . 'T09:30', 'duration_hours' => 1,
        ]);
        $this->assertSame(JobRequest::STATUS_APPROVED, TableRegistry::getTableLocator()->get('JobRequests')->get(2)->status);

        $this->get('/vehicles/availability?start=' . $day . 'T09%3A30&duration=1');
        $this->assertResponseOk();
        $this->assertResponseContains('In Use');
        $this->assertResponseContains('staffuser');

        $this->post('/job-requests/manage/assign/2', [
            'plumber_admin_id' => 3, 'vehicle_id' => 1,
            'scheduled_start' => $day . 'T10:00', 'duration_hours' => 1,
        ]);
        $this->assertSame(JobRequest::STATUS_ASSIGNED, TableRegistry::getTableLocator()->get('JobRequests')->get(2)->status);
    }
}
