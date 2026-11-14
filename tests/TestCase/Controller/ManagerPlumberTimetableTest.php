<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use DateTimeImmutable;
use DateTimeZone;

/** A manager may inspect a plumber's calendar, but never change availability. */
class ManagerPlumberTimetableTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.Admins',
        'app.Customers',
        'app.JobRequests',
        'app.JobAssignments',
        'app.PlumberUnavailabilities',
    ];

    private function signIn(int $id, string $role): void
    {
        $this->session(['AdminAuth' => [
            'admin_id' => $id,
            'admin_username' => [1 => 'hannah', 2 => 'staffuser', 3 => 'manageruser'][$id],
            'role' => $role,
        ]]);
    }

    public function testManagerCanOpenInactivePlumbersReadOnlyTimetable(): void
    {
        $accounts = TableRegistry::getTableLocator()->get('Admins');
        $plumber = $accounts->get(2);
        $plumber->set('active', false);
        $accounts->saveOrFail($plumber);

        $this->signIn(3, 'manager');
        $this->get('/plumbers');
        $this->assertResponseOk();
        $this->assertResponseContains('/plumbers/timetable/2');

        $this->get('/plumbers/timetable/2');
        $this->assertResponseOk();
        $this->assertResponseContains('Timetable: staffuser');
        $this->assertResponseContains('Previous week');
        $this->assertResponseContains('Next week');
        $this->assertResponseContains('Unavailable periods this week');
        $this->assertResponseNotContains('Mark unavailable time');
        $this->assertResponseNotContains('Save unavailable time');
        $this->assertResponseNotContains('Withdraw');
    }

    public function testManagerSeesSelectedPlumbersWorkAndTimeOffWithReadOnlyJobLink(): void
    {
        $timezone = new DateTimeZone('Australia/Melbourne');
        $monday = new DateTimeImmutable('next monday', $timezone);
        $week = $monday->format('Y-m-d');
        $job = TableRegistry::getTableLocator()->get('JobRequests')->get(1);
        $job->set('status', 'assigned');
        TableRegistry::getTableLocator()->get('JobRequests')->saveOrFail($job);
        $assignments = TableRegistry::getTableLocator()->get('JobAssignments');
        $assignments->saveOrFail($assignments->newEntity([
            'job_request_id' => 1,
            'plumber_admin_id' => 2,
            'assigned_by' => 1,
            'scheduled_start' => $monday->setTime(9, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'scheduled_end' => $monday->setTime(11, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]));
        $timeOff = TableRegistry::getTableLocator()->get('PlumberUnavailabilities');
        $block = $timeOff->newEmptyEntity();
        $block->set('admin_id', 2);
        $block->set('unavailable_date', $monday->modify('+1 day')->format('Y-m-d'));
        $block->set('start_minute', 780);
        $block->set('end_minute', 900);
        $timeOff->saveOrFail($block);

        $this->signIn(3, 'manager');
        $this->get('/plumbers/timetable/2?week=' . $week);
        $this->assertResponseOk();
        $this->assertResponseContains('09:00–11:00');
        $this->assertResponseContains('13:00–15:00');
        $this->assertResponseContains('/manager-jobs/view/1');
        $this->assertResponseNotContains('/plumbers/job/1');
        $this->assertResponseContains('/plumbers/timetable/2?week=');
        $this->assertResponseNotContains('Mark unavailable time');
        $this->assertResponseNotContains('Withdraw');
    }

    public function testOnlyManagerCanOpenAPlumbersTimetable(): void
    {
        Configure::write('debug', false);
        $this->signIn(1, 'admin');
        $this->get('/plumbers/timetable/2');
        $this->assertResponseCode(403);

        $this->signIn(2, 'staff');
        $this->get('/plumbers/timetable/2');
        $this->assertResponseCode(403);

        $this->signIn(3, 'manager');
        $this->get('/plumbers/timetable/1');
        $this->assertResponseCode(404);
    }
}
