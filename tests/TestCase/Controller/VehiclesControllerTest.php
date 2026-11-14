<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/** Fleet CRUD is admin-only, and deletion retains the vehicle record. */
class VehiclesControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins', 'app.Vehicles', 'app.AuditLogs', 'app.Customers',
        'app.JobRequests', 'app.JobAssignments',
    ];

    /** Use the database-backed office role checks. */
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

    public function testOnlyAdminCanSeeOrChangeFleet(): void
    {
        $this->signIn(1, 'admin');
        $this->get('/vehicles');
        $this->assertResponseOk();
        $this->assertResponseContains('Service van 1');
        $this->assertResponseContains('Vehicles');
        $this->get('/vehicles/add');
        $this->assertResponseOk();
        $this->assertResponseContains('Registration number');

        Configure::write('debug', false);
        $this->signIn(2, 'staff');
        $this->get('/vehicles');
        $this->assertResponseCode(403);
        $this->assertResponseNotContains('ABC 123');

        $this->signIn(3, 'manager');
        $this->get('/vehicles/add');
        $this->assertResponseCode(403);
        $this->enableCsrfToken();
        $this->post('/vehicles/deactivate/1');
        $this->assertResponseCode(403);
        $this->assertTrue((bool)TableRegistry::getTableLocator()->get('Vehicles')->get(1)->active);
    }

    public function testAdminCanCreateAndEditWithoutMassAssigningStatus(): void
    {
        $this->signIn(1, 'admin');
        $this->enableCsrfToken();
        $this->post('/vehicles/add', [
            'registration_number' => ' vic-456 ',
            'label' => '  Service van 2 ',
            'notes' => 'Spare van',
            'active' => false,
            'id' => 999,
        ]);
        $vehicles = TableRegistry::getTableLocator()->get('Vehicles');
        $new = $vehicles->find()->where(['registration_number' => 'VIC-456'])->firstOrFail();
        $this->assertRedirect('/vehicles/view/' . $new->id);
        $this->assertSame('Service van 2', $new->label);
        $this->assertTrue((bool)$new->active);
        $this->assertNotSame(999, (int)$new->id);

        $this->post('/vehicles/edit/' . $new->id, [
            'registration_number' => 'vic-456',
            'label' => 'Updated service van',
            'notes' => 'Updated notes',
            'active' => false,
        ]);
        $this->assertRedirect('/vehicles/view/' . $new->id);
        $updated = $vehicles->get($new->id);
        $this->assertSame('Updated service van', $updated->label);
        $this->assertTrue((bool)$updated->active);
        $this->assertSame(1, TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'vehicle.created'])->count());
        $this->assertSame(1, TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'vehicle.updated'])->count());
    }

    public function testDuplicateRegistrationStaysOnForm(): void
    {
        $this->signIn(1, 'admin');
        $this->enableCsrfToken();
        $this->post('/vehicles/add', [
            'registration_number' => 'abc 123',
            'label' => 'Duplicate van',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('This registration number is already in use.');
        $this->assertSame(1, TableRegistry::getTableLocator()->get('Vehicles')->find()->count());
    }

    public function testDeactivateAndReactivateRetainRecord(): void
    {
        $this->signIn(1, 'admin');
        $this->enableCsrfToken();
        $this->get('/vehicles/deactivate/1');
        $this->assertResponseCode(405);

        $this->post('/vehicles/deactivate/1');
        $vehicles = TableRegistry::getTableLocator()->get('Vehicles');
        $this->assertRedirect('/vehicles/view/1');
        $this->assertFalse((bool)$vehicles->get(1)->active);
        $this->assertSame(1, $vehicles->find()->count());
        $this->get('/vehicles/view/1');
        $this->assertResponseOk();
        $this->assertResponseContains('Unavailable');

        $this->post('/vehicles/reactivate/1');
        $this->assertTrue((bool)$vehicles->get(1)->active);
        $this->assertSame(1, TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'vehicle.deactivated'])->count());
        $this->assertSame(1, TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'vehicle.reactivated'])->count());
    }

    public function testFutureAssignedVehicleCannotBeDeactivated(): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get(1);
        $job->set('status', 'assigned');
        $jobs->saveOrFail($job);
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $start = new DateTimeImmutable('+2 days', new DateTimeZone('UTC'));
        $assignment = $assignments->newEntity([
            'job_request_id' => 1,
            'plumber_admin_id' => 2,
            'assigned_by' => 1,
            'vehicle_id' => 1,
            'scheduled_start' => $start->format('Y-m-d H:i:s'),
            'scheduled_end' => $start->modify('+1 hour')->format('Y-m-d H:i:s'),
        ]);
        $assignments->saveOrFail($assignment);

        $this->signIn(1, 'admin');
        $this->enableCsrfToken();
        $this->post('/vehicles/deactivate/1');
        $this->assertTrue((bool)TableRegistry::getTableLocator()->get('Vehicles')->get(1)->active);
        $this->assertSame(0, TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where(['action' => 'vehicle.deactivated'])->count());
    }

    public function testInProgressVehicleRemainsInUseAfterScheduledEnd(): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get(1);
        $job->set('status', 'in_progress');
        $jobs->saveOrFail($job);
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $end = new DateTimeImmutable('-1 hour', new DateTimeZone('UTC'));
        $assignments->saveOrFail($assignments->newEntity([
            'job_request_id' => 1,
            'plumber_admin_id' => 2,
            'assigned_by' => 1,
            'vehicle_id' => 1,
            'scheduled_start' => $end->modify('-2 hours')->format('Y-m-d H:i:s'),
            'scheduled_end' => $end->format('Y-m-d H:i:s'),
        ]));

        $this->signIn(1, 'admin');
        $this->get('/vehicles/view/1');
        $this->assertResponseContains('In Use');
        $this->assertResponseContains('staffuser');
        $this->enableCsrfToken();
        $this->post('/vehicles/deactivate/1');
        $this->assertTrue((bool)TableRegistry::getTableLocator()->get('Vehicles')->get(1)->active);
    }

    public function testFleetScheduleShowsWhoHasEachVehicleByDayAndWeek(): void
    {
        $this->putVehicleOnJobInProgress();
        $this->signIn(1, 'admin');
        $assignment = TableRegistry::getTableLocator()->get('JobAssignments')->find()->firstOrFail();
        $day = $assignment->scheduled_start->setTimezone(new DateTimeZone('Australia/Melbourne'))->format('Y-m-d');

        foreach (['day', 'week'] as $view) {
            $this->get('/vehicles/schedule?view=' . $view . '&date=' . $day);
            $this->assertResponseOk();
            $this->assertResponseContains('Vehicle availability');
            $this->assertResponseContains('Out on a job');
            $this->assertResponseContains('staffuser');
            $this->assertResponseContains('href="/job-requests/manage/1"');
        }

        $this->get('/vehicles/schedule?date=not-a-date');
        $this->assertResponseOk();
    }

    public function testFleetScheduleIsForAdminsOnly(): void
    {
        foreach ([[2, 'staff'], [3, 'manager']] as [$id, $role]) {
            $this->signIn($id, $role);
            $this->get('/vehicles/schedule');
            $this->assertResponseCode(403);
        }
    }

    /** Put vehicle 1 on job 1, which is in progress right now. */
    private function putVehicleOnJobInProgress(): void
    {
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get(1);
        $job->set('status', 'in_progress');
        $jobs->saveOrFail($job);
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $start = new DateTimeImmutable('-1 hour', new DateTimeZone('UTC'));
        $assignments->saveOrFail($assignments->newEntity([
            'job_request_id' => 1,
            'plumber_admin_id' => 2,
            'assigned_by' => 1,
            'vehicle_id' => 1,
            'scheduled_start' => $start->format('Y-m-d H:i:s'),
            'scheduled_end' => $start->modify('+3 hours')->format('Y-m-d H:i:s'),
        ]));
    }

    public function testListAndDetailShowTheJobReferenceOfAnInUseVehicle(): void
    {
        $this->putVehicleOnJobInProgress();
        $this->signIn(1, 'admin');

        foreach (['/vehicles', '/vehicles/view/1'] as $url) {
            $this->get($url);
            $this->assertResponseOk();
            $this->assertResponseContains('In Use');
            $this->assertResponseContains('REQ-000001');
            $this->assertResponseContains('staffuser');
            $this->assertResponseContains('href="/job-requests/manage/1"');
        }
    }

    public function testJobReferenceIsHiddenFromEveryoneExceptAdmins(): void
    {
        $this->putVehicleOnJobInProgress();
        $urls = ['/vehicles', '/vehicles/view/1'];

        foreach ($urls as $url) {
            $this->get($url);
            $this->assertRedirectContains('/login');
            $this->assertResponseNotContains('REQ-');
        }

        Configure::write('debug', false);
        $this->session(['CustomerAuth' => [
            'cust_id' => 1,
            'cust_email' => 'kash@example.com',
            'cust_fname' => 'Kash',
            'cust_lname' => 'Patel',
        ]]);
        foreach ($urls as $url) {
            $this->get($url);
            $this->assertRedirectContains('/login');
            $this->assertResponseNotContains('REQ-');
        }

        // The following staff/manager checks use fresh office sessions.
        unset($this->_session['CustomerAuth']);
        foreach ([[2, 'staff'], [3, 'manager']] as [$id, $role]) {
            $this->signIn($id, $role);
            foreach ($urls as $url) {
                $this->get($url);
                $this->assertResponseCode(403);
                $this->assertResponseNotContains('REQ-');
            }
        }
    }

    public function testAvailableAndUnavailableVehiclesShowNoJobReference(): void
    {
        $this->signIn(1, 'admin');
        foreach (['/vehicles', '/vehicles/view/1'] as $url) {
            $this->get($url);
            $this->assertResponseOk();
            $this->assertResponseContains('Available');
            $this->assertResponseNotContains('REQ-');
        }

        $this->enableCsrfToken();
        $this->post('/vehicles/deactivate/1');
        foreach (['/vehicles', '/vehicles/view/1'] as $url) {
            $this->get($url);
            $this->assertResponseContains('Unavailable');
            $this->assertResponseNotContains('REQ-');
        }
    }

    /**
     * Put vehicle 1 on a job.
     *
     * @param int $jobId The job request.
     * @param string $status The job's status.
     * @param string $from When the visit starts, as a strtotime() offset from now.
     * @param string $to When the visit ends, as a strtotime() offset from now.
     * @param bool $started Whether the plumber has started it.
     * @return void
     */
    private function bookVehicle(int $jobId, string $status, string $from, string $to, bool $started = false): void
    {
        $utc = new DateTimeZone('UTC');
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get($jobId);
        $job->set('status', $status);
        $jobs->saveOrFail($job);

        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $assignment = $assignments->newEmptyEntity();
        $assignment->set('job_request_id', $jobId);
        $assignment->set('plumber_admin_id', 2);
        $assignment->set('assigned_by', 1);
        $assignment->set('vehicle_id', 1);
        $assignment->set('scheduled_start', (new DateTimeImmutable($from, $utc))->format('Y-m-d H:i:s'));
        $assignment->set('scheduled_end', (new DateTimeImmutable($to, $utc))->format('Y-m-d H:i:s'));
        if ($started) {
            $assignment->set('started_at', (new DateTimeImmutable($from, $utc))->format('Y-m-d H:i:s'));
        }
        $assignments->saveOrFail($assignment);
    }

    public function testTheListShowsWhoHasTheVehicleBetweenWhatTimesAndWhatIsNext(): void
    {
        $this->bookVehicle(1, 'in_progress', '-1 hour', '+2 hours', true);
        $this->bookVehicle(2, 'assigned', '+1 day', '+1 day +90 minutes');
        $this->signIn(1, 'admin');

        $this->get('/vehicles');

        $this->assertResponseOk();
        $this->assertResponseContains('In Use');
        $this->assertResponseContains('REQ-000001');
        $this->assertResponseContains('staffuser');
        // A time range for the booking in use, and the next one after it.
        $this->assertResponseRegExp('/\d{1,2}:\d{2}[ap]m–\d{1,2}:\d{2}[ap]m/');
        $this->assertResponseContains('Next booking');
        $this->assertResponseContains('REQ-000002');
        $this->assertResponseContains('Started ');
    }

    public function testTheVehiclePageListsBookedJobsAndRecentUse(): void
    {
        $this->bookVehicle(1, 'completed', '-3 days', '-3 days +1 hour', true);
        $this->bookVehicle(2, 'assigned', '+2 days', '+2 days +1 hour');
        TableRegistry::getTableLocator()->get('JobAssignments')->updateAll(
            ['finished_at' => (new DateTimeImmutable('-3 days +1 hour', new DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s')],
            ['job_request_id' => 1],
        );
        $this->signIn(1, 'admin');

        $this->get('/vehicles/view/1');

        $this->assertResponseOk();
        $this->assertResponseContains('Available');
        $this->assertResponseContains('Booked jobs');
        $this->assertResponseContains('REQ-000002');
        $this->assertResponseContains('Pending plumber approval');
        $this->assertResponseContains('Recent use');
        $this->assertResponseContains('REQ-000001');
        $this->assertResponseContains('Actually used');
        // Office pages show references and plumbers, never the customer.
        $this->assertResponseNotContains('Leaking kitchen tap.');
    }

    public function testAVehicleIsFreeAgainTheMomentItsJobIsCompleted(): void
    {
        $this->bookVehicle(1, 'in_progress', '-1 hour', '+2 hours', true);

        $this->signIn(1, 'admin');
        $this->get('/vehicles');
        $this->assertResponseContains('In Use');

        // The plumber finishes the job, well before the scheduled end.
        $this->signIn(2, 'staff');
        $this->enableCsrfToken();
        $this->post('/plumbers/finish-job/1', ['outcome' => 'completed']);

        $this->signIn(1, 'admin');
        foreach (['/vehicles', '/vehicles/view/1'] as $url) {
            $this->get($url);
            $this->assertResponseOk();
            $this->assertResponseContains('Available');
            $this->assertResponseNotContains('In Use');
        }
        $this->get('/vehicles/view/1');
        $this->assertResponseContains('Recent use');
        $this->assertResponseContains('REQ-000001');

        // And the dispatch form offers it again for the same time.
        $start = new DateTimeImmutable('+1 hour', new DateTimeZone('Australia/Melbourne'));
        $conflict = TableRegistry::getTableLocator()->get('JobAssignments')->vehicleConflict(
            1,
            $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $start->modify('+1 hour')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
        $this->assertNull($conflict);
    }

    public function testAVehicleIsAlsoFreeAfterAJobCouldNotBeCompleted(): void
    {
        $this->bookVehicle(1, 'in_progress', '-1 hour', '+2 hours', true);

        $this->signIn(2, 'staff');
        $this->enableCsrfToken();
        $this->post('/plumbers/finish-job/1', [
            'outcome' => 'unable_to_complete',
            'unable_reason' => 'The part needed is not in stock.',
        ]);

        $this->signIn(1, 'admin');
        $this->get('/vehicles');
        $this->assertResponseContains('Available');
        $this->assertResponseNotContains('In Use');
    }
}
