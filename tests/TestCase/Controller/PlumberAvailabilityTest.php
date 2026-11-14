<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/** Default work week and plumber-owned, dated unavailable periods. */
class PlumberAvailabilityTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.AuditLogs',
        'app.PlumberUnavailabilities',
        'app.Customers',
        'app.JobRequests',
        'app.JobAssignments',
    ];

    private function signIn(int $id, string $role): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => $id,
            'admin_username' => $id === 2 ? 'staffuser' : 'hannah',
            'role' => $role,
        ]]);
    }

    private function futureMonday(): DateTimeImmutable
    {
        return new DateTimeImmutable('next monday', new DateTimeZone('Australia/Melbourne'));
    }

    public function testPlumberSeesStandardWeekAndCanAddPartialDay(): void
    {
        $date = $this->futureMonday()->format('Y-m-d');
        $this->signIn(2, 'staff');
        $this->get('/plumbers/availability?week=' . $date);
        $this->assertResponseOk();
        $this->assertResponseNotContains('Standard working hours');
        $this->assertResponseContains('Mark unavailable time');

        $this->enableCsrfToken();
        $this->post('/plumbers/availability', [
            'unavailable_date' => $date,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'admin_id' => 1,
        ]);
        $this->assertRedirect('/plumbers/availability?week=' . $date);

        $entry = TableRegistry::getTableLocator()->get('PlumberUnavailabilities')->find()->firstOrFail();
        $this->assertSame(2, (int)$entry->admin_id);
        $this->assertSame(540, (int)$entry->start_minute);
        $this->assertSame(720, (int)$entry->end_minute);

        $this->get('/plumbers/availability?week=' . $date);
        $this->assertResponseOk();
        $this->assertResponseContains('09:00–12:00');
    }

    public function testTimesWithSecondsFromTheBrowserAreAccepted(): void
    {
        $date = $this->futureMonday()->format('Y-m-d');
        $this->signIn(2, 'staff');
        $this->enableCsrfToken();
        $this->post('/plumbers/availability', [
            'unavailable_date' => $date,
            'start_time' => '09:00:00',
            'end_time' => '12:30:00',
        ]);
        $this->assertRedirect('/plumbers/availability?week=' . $date);

        $entry = TableRegistry::getTableLocator()->get('PlumberUnavailabilities')->find()->firstOrFail();
        $this->assertSame(540, (int)$entry->start_minute);
        $this->assertSame(750, (int)$entry->end_minute);
    }

    public function testWholeDayAndConflictingOrInvalidTimes(): void
    {
        $monday = $this->futureMonday();
        $date = $monday->format('Y-m-d');
        $this->signIn(2, 'staff');
        $this->enableCsrfToken();

        $this->post('/plumbers/availability', [
            'unavailable_date' => $date,
            'all_day' => '1',
        ]);
        $this->assertRedirect('/plumbers/availability?week=' . $date);
        $blocks = TableRegistry::getTableLocator()->get('PlumberUnavailabilities');
        $entry = $blocks->find()->firstOrFail();
        $this->assertSame(480, (int)$entry->start_minute);
        $this->assertSame(1020, (int)$entry->end_minute);

        $this->post('/plumbers/availability', [
            'unavailable_date' => $date,
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('overlaps an existing unavailable period');

        $this->post('/plumbers/availability', [
            'unavailable_date' => $monday->modify('+5 days')->format('Y-m-d'),
            'start_time' => '10:00',
            'end_time' => '11:00',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('Choose a weekday');

        $this->post('/plumbers/availability', [
            'unavailable_date' => $monday->modify('+1 day')->format('Y-m-d'),
            'start_time' => '07:00',
            'end_time' => '09:00',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('between 08:00 and 17:00');
        $this->assertSame(1, $blocks->find()->count());
    }

    public function testWithdrawalPreservesHistoryAndReleasesTime(): void
    {
        $date = $this->futureMonday()->format('Y-m-d');
        $this->signIn(2, 'staff');
        $this->enableCsrfToken();
        $data = [
            'unavailable_date' => $date,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ];
        $this->post('/plumbers/availability', $data);
        $this->assertRedirect();

        $blocks = TableRegistry::getTableLocator()->get('PlumberUnavailabilities');
        $first = $blocks->find()->firstOrFail();
        $this->post('/plumbers/cancel-unavailable/' . $first->id);
        $this->assertRedirect('/plumbers/availability?week=' . $date);
        $this->assertNotNull($blocks->get($first->id)->cancelled_at);

        $this->post('/plumbers/availability', $data);
        $this->assertRedirect();
        $this->assertSame(2, $blocks->find()->count());
        $this->assertSame(1, $blocks->find()->where(['cancelled_at IS' => null])->count());
    }

    public function testAdjacentIntervalsAreAllowedButPastDatesAreNot(): void
    {
        $date = $this->futureMonday()->format('Y-m-d');
        $this->signIn(2, 'staff');
        $this->enableCsrfToken();

        $this->post('/plumbers/availability', [
            'unavailable_date' => $date,
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);
        $this->assertRedirect();
        $this->post('/plumbers/availability', [
            'unavailable_date' => $date,
            'start_time' => '12:00',
            'end_time' => '13:00',
        ]);
        $this->assertRedirect();

        $this->post('/plumbers/availability', [
            'unavailable_date' => '2020-01-06',
            'start_time' => '09:00',
            'end_time' => '12:00',
        ]);
        $this->assertResponseOk();
        $this->assertResponseContains('Choose a weekday on or after today.');
        $this->assertSame(2, TableRegistry::getTableLocator()->get('PlumberUnavailabilities')->find()->count());
    }

    public function testOtherRolesAndOtherPeoplesPeriodsAreDenied(): void
    {
        $date = $this->futureMonday()->format('Y-m-d');
        Configure::write('debug', false);
        $this->signIn(1, 'admin');
        $this->get('/plumbers/availability');
        $this->assertResponseCode(403);
        $this->signIn(3, 'manager');
        $this->get('/plumbers/availability');
        $this->assertResponseCode(403);

        $blocks = TableRegistry::getTableLocator()->get('PlumberUnavailabilities');
        $entry = $blocks->newEmptyEntity();
        $entry->set('admin_id', 1);
        $entry->set('unavailable_date', $date);
        $entry->set('start_minute', 600);
        $entry->set('end_minute', 660);
        $blocks->saveOrFail($entry);

        $this->signIn(2, 'staff');
        $this->enableCsrfToken();
        $this->post('/plumbers/cancel-unavailable/' . $entry->id);
        $this->assertResponseCode(403);
        $this->assertNull($blocks->get($entry->id)->cancelled_at);
    }

    public function testTimetableShowsOnlyOwnJobsAndKeepsFinishedStatuses(): void
    {
        $monday = $this->futureMonday();
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $assigned = $jobs->get(1);
        $assigned->set('status', 'assigned');
        $jobs->saveOrFail($assigned);
        $finished = $jobs->get(2);
        $finished->set('status', 'completed');
        $jobs->saveOrFail($finished);

        foreach ([1 => [9, 2], 2 => [10, 2]] as $jobId => [$hour, $plumberId]) {
            $start = $monday->setTime($hour, 0)->setTimezone(new DateTimeZone('UTC'));
            $assignments->saveOrFail($assignments->newEntity([
                'job_request_id' => $jobId,
                'plumber_admin_id' => $plumberId,
                'assigned_by' => 1,
                'scheduled_start' => $start->format('Y-m-d H:i:s'),
                'scheduled_end' => $start->modify('+1 hour')->format('Y-m-d H:i:s'),
            ]));
        }

        $this->signIn(2, 'staff');
        $this->get('/plumbers/availability?week=' . $monday->format('Y-m-d'));
        $this->assertResponseOk();
        $this->assertResponseContains('My Timetable');
        $this->assertResponseContains('timetable-job--assigned');
        $this->assertResponseContains('timetable-job--completed');
        $this->assertResponseContains('12 Clayton Road');

        $finished->set('status', 'unable_to_complete');
        $jobs->saveOrFail($finished);
        $assigned->set('status', 'in_progress');
        $jobs->saveOrFail($assigned);
        $this->get('/plumbers/availability?week=' . $monday->format('Y-m-d'));
        $this->assertResponseContains('timetable-job--in-progress');
        $this->assertResponseContains('timetable-job--unable');

        $otherJob = $assignments->find()->where(['job_request_id' => 2])->firstOrFail();
        $otherJob->set('plumber_admin_id', 3);
        $assignments->saveOrFail($otherJob);
        $this->get('/plumbers/availability?week=' . $monday->format('Y-m-d'));
        $this->assertResponseNotContains('99 Private Street');
    }

    public function testOldOffHoursJobIsListedBelowTimetable(): void
    {
        $monday = $this->futureMonday();
        $jobs = TableRegistry::getTableLocator()->get('JobRequests');
        $job = $jobs->get(1);
        $job->set('status', 'completed');
        $jobs->saveOrFail($job);
        $start = $monday->setTime(4, 0)->setTimezone(new DateTimeZone('UTC'));
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $assignments->saveOrFail($assignments->newEntity([
            'job_request_id' => 1,
            'plumber_admin_id' => 2,
            'assigned_by' => 1,
            'scheduled_start' => $start->format('Y-m-d H:i:s'),
            'scheduled_end' => $start->modify('+1 hour')->format('Y-m-d H:i:s'),
        ]));

        $this->signIn(2, 'staff');
        $this->get('/plumbers/availability?week=' . $monday->format('Y-m-d'));
        $this->assertResponseOk();
        $this->assertResponseContains('Jobs outside displayed hours');
        $this->assertResponseContains('12 Clayton Road');
    }
}
