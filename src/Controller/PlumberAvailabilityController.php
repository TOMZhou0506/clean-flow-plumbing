<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Admin;
use App\Model\Entity\JobRequest;
use App\Model\Table\ContactSubmissionsTable;
use App\Model\Table\PlumberUnavailabilitiesTable;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use DateTimeImmutable;
use DateTimeZone;

/** The office's team schedule: every plumber's jobs and time off, plus change notifications. */
class PlumberAvailabilityController extends AppController
{
    /** Require an active admin for every action on this controller. */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->requireAdmin();
    }

    /**
     * Show the team schedule for one day or one working week.
     *
     * Query: view=day|week (default day) and date=YYYY-MM-DD (default today).
     * The board shows active plumbers only; upcoming time off is listed below
     * it for every plumber so the alert links have somewhere to land.
     */
    public function index(): void
    {
        $this->request->allowMethod(['get']);
        $timezone = ContactSubmissionsTable::businessTimezone();
        $now = new DateTimeImmutable('now', $timezone);
        $today = $now->setTime(0, 0);

        $view = $this->request->getQuery('view') === 'week' ? 'week' : 'day';
        $plumbers = $this->fetchTable('Admins')->find()
            ->where(['role' => Admin::ROLE_STAFF])
            ->orderBy(['active' => 'DESC', 'admin_username' => 'ASC'])->all()->toList();
        $activePlumbers = array_values(array_filter($plumbers, static fn($person): bool => (bool)$person->active));
        $ids = array_map(static fn($person): int => (int)$person->admin_id, $plumbers);
        $activeIds = array_map(static fn($person): int => (int)$person->admin_id, $activePlumbers);

        // With no date chosen, open on a day that has jobs on it rather than
        // an empty weekend or quiet day.
        $date = $this->localDate($this->request->getQuery('date'), $timezone);
        $jumpedFromToday = false;
        if ($date === null) {
            $date = $this->fetchTable('JobAssignments')
                ->nearestBookedDay('plumber_admin_id', $activeIds, $today) ?? $today;
            $jumpedFromToday = $date->format('Y-m-d') !== $today->format('Y-m-d');
        }
        if ($view === 'week') {
            $rangeStart = $date->modify('-' . ((int)$date->format('N') - 1) . ' days');
            $days = [];
            for ($offset = 0; $offset < 7; $offset++) {
                $days[] = $rangeStart->modify('+' . $offset . ' days');
            }
        } else {
            $rangeStart = $date;
            $days = [$date];
        }
        $rangeEnd = end($days);

        // Time off and jobs for the visible range, keyed by plumber then date.
        $timeOff = [];
        $jobs = [];
        $byPlumber = [];
        if ($ids !== []) {
            $periods = $this->fetchTable('PlumberUnavailabilities')->find()
                ->where([
                    'admin_id IN' => $ids,
                    'cancelled_at IS' => null,
                    'unavailable_date >=' => min($rangeStart, $today)->format('Y-m-d'),
                ])
                ->orderBy(['unavailable_date' => 'ASC', 'start_minute' => 'ASC'])->all();
            foreach ($periods as $period) {
                $day = $period->unavailable_date->format('Y-m-d');
                if ($day >= $rangeStart->format('Y-m-d') && $day <= $rangeEnd->format('Y-m-d')) {
                    $timeOff[(int)$period->admin_id][$day][] = $period;
                }
                if ($day >= $today->format('Y-m-d')) {
                    $byPlumber[(int)$period->admin_id][] = $period;
                }
            }

            $utc = new DateTimeZone('UTC');
            $assignments = $this->fetchTable('JobAssignments')->find()
                ->contain(['JobRequests'])
                ->innerJoinWith('JobRequests')
                ->where([
                    'JobAssignments.plumber_admin_id IN' => $ids,
                    'JobAssignments.scheduled_start <' => $rangeEnd->modify('+1 day')->setTimezone($utc)
                        ->format('Y-m-d H:i:s'),
                    'JobAssignments.scheduled_end >' => $rangeStart->setTimezone($utc)->format('Y-m-d H:i:s'),
                    'JobRequests.status IN' => [
                        JobRequest::STATUS_ASSIGNED,
                        JobRequest::STATUS_IN_PROGRESS,
                        JobRequest::STATUS_COMPLETED,
                        JobRequest::STATUS_UNABLE_TO_COMPLETE,
                    ],
                ])
                ->orderBy(['JobAssignments.scheduled_start' => 'ASC'])->all();
            foreach ($assignments as $assignment) {
                $start = $assignment->scheduled_start->setTimezone($timezone);
                $end = $assignment->scheduled_end->setTimezone($timezone);
                $jobs[(int)$assignment->plumber_admin_id][$start->format('Y-m-d')][] = [
                    'assignment' => $assignment,
                    'start' => (int)$start->format('G') * 60 + (int)$start->format('i'),
                    'end' => $start->format('Y-m-d') === $end->format('Y-m-d')
                        ? (int)$end->format('G') * 60 + (int)$end->format('i')
                        : 24 * 60,
                ];
            }
        }

        // Weekends are only shown when a job or time off falls on them.
        $days = array_values(array_filter($days, static function ($day) use ($jobs, $timeOff): bool {
            if ((int)$day->format('N') <= 5) {
                return true;
            }
            foreach ([$jobs, $timeOff] as $byPlumber) {
                foreach ($byPlumber as $byDay) {
                    if (!empty($byDay[$day->format('Y-m-d')])) {
                        return true;
                    }
                }
            }

            return false;
        }));

        // What each active plumber is doing at this moment.
        $minuteNow = (int)$now->format('G') * 60 + (int)$now->format('i');
        $inHours = (int)$now->format('N') <= 5
            && $minuteNow >= PlumberUnavailabilitiesTable::WORK_START_MINUTE
            && $minuteNow < PlumberUnavailabilitiesTable::WORK_END_MINUTE;
        $snapshot = $this->fetchTable('JobAssignments')->scheduleSnapshot(
            $activeIds,
            $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        );
        $unavailability = $this->fetchTable('PlumberUnavailabilities');
        $status = [];
        $summary = ['on_job' => 0, 'available' => 0, 'unavailable' => 0, 'off_hours' => 0, 'jobs' => 0];
        foreach ($activeIds as $id) {
            $row = $snapshot[$id] ?? ['current' => null, 'next' => null, 'open' => 0];
            if ($row['current'] !== null) {
                $state = 'on_job';
            } elseif ($unavailability->overlaps($id, $now->format('Y-m-d'), $minuteNow, $minuteNow + 1)) {
                $state = 'unavailable';
            } elseif (!$inHours) {
                $state = 'off_hours';
            } else {
                $state = 'available';
            }
            $status[$id] = $row + ['state' => $state];
            $summary[$state]++;
            foreach ($jobs[$id] ?? [] as $dayJobs) {
                $summary['jobs'] += count($dayJobs);
            }
        }

        $ratings = $this->fetchTable('JobAssignments')->ratingSummary($activeIds);
        $alerts = $this->fetchTable('AvailabilityAlertDismissals')->unreadForAdmin((int)$this->currentAdminId());
        $this->set(compact(
            'plumbers',
            'activePlumbers',
            'byPlumber',
            'alerts',
            'view',
            'date',
            'days',
            'today',
            'now',
            'timezone',
            'timeOff',
            'jobs',
            'status',
            'summary',
            'jumpedFromToday',
            'ratings',
        ));
    }

    /** Acknowledge one immutable event for the signed-in admin only. */
    public function dismiss(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        if ($id === null || !ctype_digit($id)) {
            throw new NotFoundException('Availability update not found.');
        }
        $adminId = (int)$this->currentAdminId();
        $table = $this->fetchTable('AvailabilityAlertDismissals');
        foreach ($table->unreadForAdmin($adminId) as $alert) {
            if ((int)$alert['event']->id !== (int)$id) {
                continue;
            }
            $dismissal = $table->newEmptyEntity();
            $dismissal->set('admin_id', $adminId);
            $dismissal->set('audit_log_id', (int)$id);
            $table->saveOrFail($dismissal);

            return $this->redirect(['action' => 'index', '#' => 'alerts']);
        }

        throw new NotFoundException('Availability update not found.');
    }

    /** A valid YYYY-MM-DD from the query string as a local midnight, or null. */
    private function localDate(mixed $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
