<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Admin;
use App\Model\Entity\JobRequest;
use App\Model\Table\ContactSubmissionsTable;
use App\Model\Table\PlumberUnavailabilitiesTable;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\I18n\DateTime as CakeDateTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Admin-managed plumber accounts and the plumber's own workspace.
 *
 * Authentication remains in the existing office accounts table. Job assignments
 * are stored separately and scoped to the signed-in plumber on every lookup.
 */
class PlumbersController extends AppController
{
    /**
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        if ($this->request->getParam('action') === 'timetable') {
            $this->requireManager();
        } elseif (
            in_array($this->request->getParam('action'), [
            'dashboard', 'availability', 'cancelUnavailable', 'jobs', 'job', 'startJob', 'finishJob',
            ], true)
        ) {
            $this->requirePlumber();
        } else {
            // The plumber list and its account actions are shared by the office
            // and the manager. The manager looks after staff accounts as well
            // as admin ones, so it needs the same list, new plumber form and
            // deactivate and reactivate buttons.
            $this->requireAdminOrManager();
        }
    }

    /**
     * List plumber accounts without exposing password hashes, along with where
     * each one is right now and what they have coming up, so the office can see
     * who is free and who is on a job without opening each one.
     */
    public function index(): void
    {
        $plumbers = $this->paginate($this->fetchTable('Admins')->find()
            ->select(['admin_id', 'admin_username', 'admin_email', 'active'])
            ->where(['role' => Admin::ROLE_STAFF]));

        $ids = [];
        foreach ($plumbers as $plumber) {
            $ids[] = (int)$plumber->admin_id;
        }

        $timezone = ContactSubmissionsTable::businessTimezone();

        // What each plumber is doing now is for the office, which dispatches the
        // work. The manager only oversees the accounts, so it is not worked out
        // for them and their list has no job columns.
        $schedule = null;
        if ($this->viewBuilder()->getVar('officeRole') !== Admin::ROLE_MANAGER) {
            $now = new DateTimeImmutable('now', $timezone);
            $nowUtc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $minute = (int)$now->format('G') * 60 + (int)$now->format('i');

            $schedule = $this->fetchTable('JobAssignments')->scheduleSnapshot($ids, $nowUtc);
            $timeOff = $this->fetchTable('PlumberUnavailabilities');
            foreach ($ids as $id) {
                $schedule[$id]['unavailable'] = $timeOff->overlaps($id, $now->format('Y-m-d'), $minute, $minute + 1);
            }
        }

        // Customer ratings are for the office: the manager and admins. This page
        // already turns plumbers away, and the check is kept here as well so the
        // figures are never worked out for anyone else.
        $ratings = null;
        if (in_array($this->viewBuilder()->getVar('officeRole'), [Admin::ROLE_MANAGER, Admin::ROLE_ADMIN], true)) {
            $ratings = $this->fetchTable('JobAssignments')->ratingSummary($ids);
        }

        $this->set(compact('plumbers', 'schedule', 'timezone', 'ratings'));
    }

    /** The manager can inspect any plumber's timetable without changing it. */
    public function timetable(?string $id = null): void
    {
        $this->request->allowMethod(['get']);
        if ($id === null || !ctype_digit($id)) {
            throw new NotFoundException('Plumber not found.');
        }

        $plumber = $this->fetchTable('Admins')->find()
            ->where(['admin_id' => (int)$id, 'role' => Admin::ROLE_STAFF])
            ->firstOrFail();
        $timezone = new DateTimeZone((string)Configure::read('App.businessTimezone'));
        $today = new DateTimeImmutable('today', $timezone);
        $weekFromQuery = $this->localDate($this->request->getQuery('week'), $timezone);
        $weekStart = $this->mondayOf($weekFromQuery ?? $today);

        $this->set($this->timetableWeek((int)$plumber->admin_id, $weekStart, $timezone));
        $this->set(compact('plumber', 'today', 'weekStart'));
        $this->set('readOnly', true);
        $this->viewBuilder()->setTemplate('availability');
    }

    /** Create a plumber's office login with a temporary password. */
    public function add(): ?Response
    {
        $accounts = $this->fetchTable('Admins');
        $plumber = $accounts->newEmptyEntity();
        if ($this->request->is('post')) {
            $plumber = $accounts->patchEntity($plumber, $this->request->getData(), [
                'validate' => 'plumberCreate',
            ]);
            // Never accept an account role or active status from the form.
            $plumber->set('role', Admin::ROLE_STAFF);
            $plumber->set('active', true);
            if ($accounts->save($plumber)) {
                $this->fetchTable('AuditLogs')->record(
                    'plumber.created',
                    $this->currentAdminId(),
                    'admin',
                    (string)$plumber->admin_id,
                    [],
                    $this->request->clientIp() ?? '0.0.0.0',
                );
                $this->Flash->success(__('Plumber account created. They sign in with {0}.', $plumber->admin_email));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The plumber account could not be saved. Please check the fields below.'));
        }
        $this->set(compact('plumber'));

        return null;
    }

    /** A plumber-only landing page for availability and assigned jobs. */
    public function dashboard(): void
    {
        $me = $this->currentAdminId();
        $plumber = $this->fetchTable('Admins')->get($me);
        $assignments = $this->fetchTable('JobAssignments');

        // "Today" means today where the business is, not in UTC, and the
        // boundaries are converted back to UTC because that is what is stored.
        $timezone = ContactSubmissionsTable::businessTimezone();
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $timezone);
        $todayStart = $now->setTime(0, 0);
        $tomorrowStart = $todayStart->modify('+1 day');
        $weekEnd = $todayStart->modify('+8 days');
        $asUtc = static fn(DateTimeImmutable $date): string => $date->setTimezone($utc)->format('Y-m-d H:i:s');

        // Only this plumber's own assignments, as on every other plumber page.
        $mine = static fn() => $assignments->find()
            ->contain(['JobRequests', 'Vehicles'])
            ->innerJoinWith('JobRequests')
            ->where(['JobAssignments.plumber_admin_id' => $me]);
        $open = [JobRequest::STATUS_ASSIGNED, JobRequest::STATUS_IN_PROGRESS];
        $finished = [JobRequest::STATUS_COMPLETED, JobRequest::STATUS_UNABLE_TO_COMPLETE];

        // Assigned by the office and waiting for this plumber to approve.
        $pending = $mine()
            ->where(['JobRequests.status' => JobRequest::STATUS_ASSIGNED])
            ->orderBy(['JobAssignments.scheduled_start' => 'ASC'])
            ->all()
            ->toList();

        $today = $mine()
            ->where([
                'JobAssignments.scheduled_start >=' => $asUtc($todayStart),
                'JobAssignments.scheduled_start <' => $asUtc($tomorrowStart),
            ])
            ->orderBy(['JobAssignments.scheduled_start' => 'ASC'])
            ->all()
            ->toList();

        $comingUp = $mine()
            ->where([
                'JobAssignments.scheduled_start >=' => $asUtc($tomorrowStart),
                'JobRequests.status IN' => $open,
            ])
            ->orderBy(['JobAssignments.scheduled_start' => 'ASC'])
            ->limit(5)
            ->all()
            ->toList();

        $recentlyFinished = $mine()
            ->where(['JobRequests.status IN' => $finished])
            ->orderBy(['JobAssignments.scheduled_start' => 'DESC'])
            ->limit(5)
            ->all()
            ->toList();

        $stats = [
            'pending' => count($pending),
            'today' => count($today),
            'nextSeven' => $mine()
                ->where([
                    'JobAssignments.scheduled_start >=' => $asUtc($todayStart),
                    'JobAssignments.scheduled_start <' => $asUtc($weekEnd),
                    'JobRequests.status IN' => $open,
                ])
                ->count(),
            'inProgress' => $mine()
                ->where(['JobRequests.status' => JobRequest::STATUS_IN_PROGRESS])
                ->count(),
            'completed' => $mine()
                ->where([
                    'JobRequests.status' => JobRequest::STATUS_COMPLETED,
                    'JobAssignments.finished_at >=' => $asUtc($now->modify('-30 days')),
                ])
                ->count(),
        ];

        $this->set([
            'plumber' => $plumber,
            'pendingApproval' => $stats['pending'],
            'pending' => $pending,
            'today' => $today,
            'comingUp' => $comingUp,
            'recentlyFinished' => $recentlyFinished,
            'stats' => $stats,
            'now' => $now,
            'timezone' => $timezone,
        ]);
    }

    /** Only assignments belonging to this plumber are listed. */
    public function jobs(): void
    {
        $assignments = $this->fetchTable('JobAssignments')->find()
            ->contain(['JobRequests', 'Vehicles'])
            ->where(['JobAssignments.plumber_admin_id' => $this->currentAdminId()])
            ->orderBy(['JobAssignments.scheduled_start' => 'ASC'])
            ->all();
        $this->set(compact('assignments'));
    }

    /** A scoped lookup also protects direct URLs to another plumber's job. */
    private function ownAssignment(?string $id): object
    {
        if ($id === null || !ctype_digit($id)) {
            throw new NotFoundException('Job not found.');
        }
        $assignment = $this->fetchTable('JobAssignments')->find()
            ->contain(['JobRequests', 'Vehicles'])
            ->where(['JobAssignments.job_request_id' => (int)$id,
                'JobAssignments.plumber_admin_id' => $this->currentAdminId()])
            ->first();
        if ($assignment === null) {
            throw new NotFoundException('Job not found.');
        }

        return $assignment;
    }

    /** Show details for one job assigned to the signed-in plumber. */
    public function job(?string $id = null): void
    {
        $assignment = $this->ownAssignment($id);
        $this->set(compact('assignment'));
    }

    /** Mark an assigned job as started. */
    public function startJob(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $assignment = $this->ownAssignment($id);
        $job = $assignment->job_request;
        if ($job->status !== JobRequest::STATUS_ASSIGNED) {
            $this->Flash->error(__('Only a job waiting for your approval can be approved.'));

            return $this->redirect(['action' => 'job', $job->id]);
        }
        $this->fetchTable('JobAssignments')->getConnection()->transactional(function () use ($assignment, $job): void {
            $assignment->set('started_at', CakeDateTime::now());
            $this->fetchTable('JobAssignments')->saveOrFail($assignment);
            $job->set('status', JobRequest::STATUS_IN_PROGRESS);
            $this->fetchTable('JobRequests')->saveOrFail($job);
        });
        $this->auditJobAction('job_request.started', $job->id);
        $this->Flash->success(__('Job approved. It is now in progress.'));

        return $this->redirect(['action' => 'job', $job->id]);
    }

    /** Save the plumber's on-site outcome. */
    public function finishJob(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        $assignment = $this->ownAssignment($id);
        $job = $assignment->job_request;
        $outcome = $this->request->getData('outcome');
        $rawReason = $this->request->getData('unable_reason');
        $reason = is_string($rawReason) ? trim($rawReason) : '';
        if (
            $job->status !== JobRequest::STATUS_IN_PROGRESS ||
            !in_array($outcome, [JobRequest::STATUS_COMPLETED, JobRequest::STATUS_UNABLE_TO_COMPLETE], true)
        ) {
            $this->Flash->error(__('Only an in-progress job can be finished.'));
        } elseif (
            $outcome === JobRequest::STATUS_UNABLE_TO_COMPLETE &&
            (mb_strlen($reason) < 10 || mb_strlen($reason) > 500)
        ) {
            $this->Flash->error(
                __('Please enter a brief reason (10–500 characters) why the job could not be completed.'),
            );
        } else {
            $this->fetchTable('JobAssignments')->getConnection()->transactional(function () use (
                $assignment,
                $job,
                $outcome,
                $reason,
            ): void {
                $assignment->set('finished_at', CakeDateTime::now());
                $assignment->set('unable_reason', $outcome === JobRequest::STATUS_UNABLE_TO_COMPLETE ? $reason : null);
                $this->fetchTable('JobAssignments')->saveOrFail($assignment);
                $job->set('status', $outcome);
                $this->fetchTable('JobRequests')->saveOrFail($job);
            });
            $this->auditJobAction('job_request.' . $outcome, $job->id);
            $this->Flash->success(__('Job status updated.'));
        }

        return $this->redirect(['action' => 'job', $job->id]);
    }

    /** Record a staff status change without duplicating the audit call. */
    private function auditJobAction(string $action, int $jobId): void
    {
        $this->fetchTable('AuditLogs')->record(
            $action,
            $this->currentAdminId(),
            'job_request',
            (string)$jobId,
            [],
            $this->request->clientIp() ?? '0.0.0.0',
        );
    }

    /**
     * Weekly timetable of owned jobs, standard hours, and dated exceptions.
     * A plumber may mark only their own future weekday working time unavailable.
     */
    public function availability(): ?Response
    {
        $timezone = new DateTimeZone((string)Configure::read('App.businessTimezone'));
        $today = new DateTimeImmutable('today', $timezone);
        $weekFromQuery = $this->localDate($this->request->getQuery('week'), $timezone);
        $weekStart = $this->mondayOf($weekFromQuery ?? $today);
        $blocks = $this->fetchTable('PlumberUnavailabilities');
        $error = null;

        if ($this->request->is('post')) {
            $date = $this->localDate($this->request->getData('unavailable_date'), $timezone);
            $allDay = (string)$this->request->getData('all_day') === '1';
            $start = $allDay
                ? PlumberUnavailabilitiesTable::WORK_START_MINUTE
                : $this->clockMinute($this->request->getData('start_time'));
            $end = $allDay
                ? PlumberUnavailabilitiesTable::WORK_END_MINUTE
                : $this->clockMinute($this->request->getData('end_time'));

            if ($date === null || $date < $today || (int)$date->format('N') > 5) {
                $error = __('Choose a weekday on or after today.');
            } elseif (
                $start === null || $end === null ||
                $start < PlumberUnavailabilitiesTable::WORK_START_MINUTE ||
                $end > PlumberUnavailabilitiesTable::WORK_END_MINUTE ||
                $start >= $end
            ) {
                $error = __('Choose a time between 08:00 and 17:00, with the end after the start.');
            } else {
                $now = new DateTimeImmutable('now', $timezone);
                $nowMinute = (int)$now->format('G') * 60 + (int)$now->format('i');
                if ($date->format('Y-m-d') === $now->format('Y-m-d') && $start < $nowMinute) {
                    $error = __('Unavailable time cannot begin in the past.');
                } elseif ($blocks->overlaps($this->currentAdminId(), $date->format('Y-m-d'), $start, $end)) {
                    $error = __('This time overlaps an existing unavailable period.');
                } elseif ($this->hasScheduledJob($date, $start, $end)) {
                    $error = __('This time already contains an assigned job. Contact an admin to reschedule it.');
                } else {
                    $block = $blocks->newEmptyEntity();
                    $block->set('admin_id', $this->currentAdminId());
                    $block->set('unavailable_date', $date->format('Y-m-d'));
                    $block->set('start_minute', $start);
                    $block->set('end_minute', $end);
                    $blocks->saveOrFail($block);
                    $this->fetchTable('AuditLogs')->record(
                        'plumber.unavailable_added',
                        $this->currentAdminId(),
                        'plumber_unavailability',
                        (string)$block->id,
                        [],
                        $this->request->clientIp() ?? '0.0.0.0',
                    );
                    $this->Flash->success(__('Unavailable time added.'));

                    return $this->redirect([
                        'action' => 'availability',
                        '?' => ['week' => $this->mondayOf($date)->format('Y-m-d')],
                    ]);
                }
            }

            if ($date !== null) {
                $weekStart = $this->mondayOf($date);
            }
        }

        $this->set($this->timetableWeek((int)$this->currentAdminId(), $weekStart, $timezone));
        $formDate = $this->request->getData('unavailable_date');
        $defaultStartTime = '08:00';
        if (!is_string($formDate) || $formDate === '') {
            $candidate = $weekStart > $today ? $weekStart : $today;
            $now = new DateTimeImmutable('now', $timezone);
            if ($candidate->format('Y-m-d') === $now->format('Y-m-d')) {
                $minute = (int)$now->format('G') * 60 + (int)$now->format('i');
                if ($minute > PlumberUnavailabilitiesTable::WORK_START_MINUTE) {
                    $rounded = (int)(ceil($minute / 15) * 15);
                    if ($rounded < PlumberUnavailabilitiesTable::WORK_END_MINUTE) {
                        $defaultStartTime = sprintf('%02d:%02d', intdiv($rounded, 60), $rounded % 60);
                    } else {
                        $candidate = $candidate->modify('+1 day');
                    }
                }
            }
            while ((int)$candidate->format('N') > 5) {
                $candidate = $candidate->modify('+1 day');
            }
            $formDate = $candidate->format('Y-m-d');
        }

        $this->set(compact('today', 'weekStart', 'formDate', 'defaultStartTime', 'error'));
        $this->set('readOnly', false);

        return null;
    }

    /** Build the same weekly calendar for the plumber and the manager's read-only view. */
    private function timetableWeek(int $plumberId, DateTimeImmutable $weekStart, DateTimeZone $timezone): array
    {
        $weekEnd = $weekStart->modify('+6 days');
        $entries = $this->fetchTable('PlumberUnavailabilities')->find()
            ->where([
                'admin_id' => $plumberId,
                'cancelled_at IS' => null,
                'unavailable_date >=' => $weekStart->format('Y-m-d'),
                'unavailable_date <=' => $weekEnd->format('Y-m-d'),
            ])
            ->orderBy(['unavailable_date' => 'ASC', 'start_minute' => 'ASC'])
            ->all()
            ->toArray();
        $byDate = [];
        foreach ($entries as $entry) {
            $byDate[$entry->unavailable_date->format('Y-m-d')][] = $entry;
        }
        $weekStartUtc = $weekStart->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));
        $nextWeekUtc = $weekStart->modify('+7 days')->setTime(0, 0)
            ->setTimezone(new DateTimeZone('UTC'));
        $jobAssignments = $this->fetchTable('JobAssignments')->find()
            ->contain(['JobRequests'])
            ->where([
                'JobAssignments.plumber_admin_id' => $plumberId,
                'JobAssignments.scheduled_start <' => $nextWeekUtc->format('Y-m-d H:i:s'),
                'JobAssignments.scheduled_end >' => $weekStartUtc->format('Y-m-d H:i:s'),
                'JobRequests.status IN' => [
                    JobRequest::STATUS_ASSIGNED,
                    JobRequest::STATUS_IN_PROGRESS,
                    JobRequest::STATUS_COMPLETED,
                    JobRequest::STATUS_UNABLE_TO_COMPLETE,
                ],
            ])
            ->innerJoinWith('JobRequests')
            ->orderBy(['JobAssignments.scheduled_start' => 'ASC'])
            ->all();
        $jobsByDate = [];
        $outsideHours = [];
        foreach ($jobAssignments as $assignment) {
            $startLocal = $assignment->scheduled_start->setTimezone($timezone);
            $endLocal = $assignment->scheduled_end->setTimezone($timezone);
            $day = $startLocal->format('Y-m-d');
            $startMinute = (int)$startLocal->format('G') * 60 + (int)$startLocal->format('i');
            $endMinute = (int)$endLocal->format('G') * 60 + (int)$endLocal->format('i');
            if (
                $startMinute < PlumberUnavailabilitiesTable::WORK_START_MINUTE ||
                $endMinute > PlumberUnavailabilitiesTable::WORK_END_MINUTE ||
                $startLocal->format('Y-m-d') !== $endLocal->format('Y-m-d')
            ) {
                $outsideHours[] = $assignment;
                continue;
            }
            $jobsByDate[$day][] = $assignment;
        }
        $weekDays = [];
        for ($day = 0; $day < 7; $day++) {
            $weekDays[] = $weekStart->modify('+' . $day . ' days');
        }

        return compact(
            'weekEnd',
            'weekDays',
            'entries',
            'byDate',
            'jobsByDate',
            'outsideHours',
        );
    }

    /** Availability cannot be blocked after an active job has been assigned. */
    private function hasScheduledJob(DateTimeImmutable $date, int $start, int $end): bool
    {
        $startLocal = $date->setTime(intdiv($start, 60), $start % 60);
        $endLocal = $date->setTime(intdiv($end, 60), $end % 60);

        return $this->fetchTable('JobAssignments')->find()->innerJoinWith('JobRequests')
            ->where([
                'JobAssignments.plumber_admin_id' => $this->currentAdminId(),
                'JobAssignments.scheduled_start <' => $endLocal->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s'),
                'JobAssignments.scheduled_end >' => $startLocal->setTimezone(new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s'),
                'JobRequests.status IN' => [JobRequest::STATUS_ASSIGNED, JobRequest::STATUS_IN_PROGRESS],
            ])->count() > 0;
    }

    /** Keep history while withdrawing an unassigned future exception. */
    public function cancelUnavailable(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);
        if ($id === null || !ctype_digit($id)) {
            throw new BadRequestException('Invalid unavailable period.');
        }

        $blocks = $this->fetchTable('PlumberUnavailabilities');
        $block = $blocks->get((int)$id);
        if ((int)$block->admin_id !== $this->currentAdminId()) {
            throw new ForbiddenException('This unavailable period belongs to another plumber.');
        }
        $timezone = new DateTimeZone((string)Configure::read('App.businessTimezone'));
        $today = new DateTimeImmutable('today', $timezone);
        $date = $this->localDate($block->unavailable_date->format('Y-m-d'), $timezone);
        if ($date === null || $date < $today) {
            throw new ForbiddenException('Past unavailable periods cannot be withdrawn.');
        }

        if ($block->cancelled_at === null) {
            $block->set('cancelled_at', CakeDateTime::now());
            $blocks->saveOrFail($block);
            $this->fetchTable('AuditLogs')->record(
                'plumber.unavailable_cancelled',
                $this->currentAdminId(),
                'plumber_unavailability',
                (string)$block->id,
                [],
                $this->request->clientIp() ?? '0.0.0.0',
            );
        }

        $this->Flash->success(__('Unavailable time withdrawn.'));

        return $this->redirect([
            'action' => 'availability',
            '?' => ['week' => $this->mondayOf($date)->format('Y-m-d')],
        ]);
    }

    /** Parse a local calendar date without allowing overflow such as 30 February. */
    private function localDate(mixed $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** Parse HH:MM as minutes after midnight. */
    private function clockMinute(mixed $value): ?int
    {
        // Browsers send "09:00:00" when a time input allows seconds, so a
        // trailing ":00" is accepted and the seconds are ignored.
        if (!is_string($value) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $matches)) {
            return null;
        }

        return (int)$matches[1] * 60 + (int)$matches[2];
    }

    /** Return the Monday at the start of this local calendar week. */
    private function mondayOf(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->modify('-' . ((int)$date->format('N') - 1) . ' days');
    }

    /** Admin-only deactivation; account and audit history are retained. */
    public function deactivate(?string $id = null): Response
    {
        return $this->setActive($id, false);
    }

    /** Admin-only reactivation of a plumber account. */
    public function reactivate(?string $id = null): Response
    {
        return $this->setActive($id, true);
    }

    /**
     * @param string|null $id The target plumber account ID.
     * @param bool $active Whether the account should be active.
     */
    private function setActive(?string $id, bool $active): Response
    {
        $this->request->allowMethod(['post']);
        $accounts = $this->fetchTable('Admins');
        $plumber = $accounts->get($id);
        if ($plumber->role !== Admin::ROLE_STAFF) {
            throw new ForbiddenException('Only plumber accounts can be changed here.');
        }

        if (
            !$active && $this->fetchTable('JobAssignments')->find()
            ->innerJoinWith('JobRequests')
            ->where([
                'JobAssignments.plumber_admin_id' => $plumber->admin_id,
                'JobRequests.status IN' => [JobRequest::STATUS_ASSIGNED, JobRequest::STATUS_IN_PROGRESS],
            ])->count() > 0
        ) {
            $this->Flash->error(
                __('This plumber still has assigned work. Resolve those jobs before deactivating the account.'),
            );

            return $this->redirect(['action' => 'index']);
        }

        if ((bool)$plumber->active !== $active) {
            $plumber->set('active', $active);
            $accounts->saveOrFail($plumber);
            $this->fetchTable('AuditLogs')->record(
                $active ? 'plumber.reactivated' : 'plumber.deactivated',
                $this->currentAdminId(),
                'admin',
                (string)$plumber->admin_id,
                [],
                $this->request->clientIp() ?? '0.0.0.0',
            );
        }

        $this->Flash->success($active ? __('Plumber reactivated.') : __('Plumber deactivated.'));

        return $this->redirect(['action' => 'index']);
    }
}
