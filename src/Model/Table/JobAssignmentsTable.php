<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\JobRequest;
use Cake\ORM\Table;
use DateTimeImmutable;
use DateTimeZone;

/** One plumber and one scheduled visit for each approved request. */
class JobAssignmentsTable extends Table
{
    /** Set the request and plumber relationships used by scoped views. */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('job_assignments');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->belongsTo('JobRequests', ['foreignKey' => 'job_request_id']);
        $this->belongsTo('Plumbers', [
            'className' => 'Admins',
            'foreignKey' => 'plumber_admin_id',
            'bindingKey' => 'admin_id',
        ]);
        $this->belongsTo('AssignedBy', [
            'className' => 'Admins',
            'foreignKey' => 'assigned_by',
            'bindingKey' => 'admin_id',
            'propertyName' => 'assigning_admin',
        ]);
        $this->belongsTo('Vehicles', ['foreignKey' => 'vehicle_id']);
    }

    /**
     * An active booking that occupies a vehicle during a selected UTC interval.
     *
     * @param int|null $ignoreJobRequestId A job being changed, whose own current
     *   booking must not count as a clash with its replacement.
     */
    public function vehicleConflict(
        int $vehicleId,
        string $startUtc,
        string $endUtc,
        ?int $ignoreJobRequestId = null,
    ): ?object {
        $inProgress = $this->find()
            ->contain(['Plumbers', 'JobRequests'])
            ->innerJoinWith('JobRequests')
            ->where([
                'JobAssignments.vehicle_id' => $vehicleId,
                'JobRequests.status' => JobRequest::STATUS_IN_PROGRESS,
            ]);
        if ($ignoreJobRequestId !== null) {
            $inProgress->where(['JobAssignments.job_request_id !=' => $ignoreJobRequestId]);
        }
        $inProgress = $inProgress->first();
        if ($inProgress !== null) {
            return $inProgress;
        }

        $booked = $this->find()->contain(['Plumbers', 'JobRequests'])->innerJoinWith('JobRequests')
            ->where([
                'JobAssignments.vehicle_id' => $vehicleId,
                'JobAssignments.scheduled_start <' => $endUtc,
                'JobAssignments.scheduled_end >' => $startUtc,
                'JobRequests.status' => JobRequest::STATUS_ASSIGNED,
            ])
            ->orderBy(['JobAssignments.scheduled_start' => 'ASC']);
        if ($ignoreJobRequestId !== null) {
            $booked->where(['JobAssignments.job_request_id !=' => $ignoreJobRequestId]);
        }

        return $booked->first();
    }

    /**
     * An active booking that already occupies a plumber during a UTC interval.
     *
     * @param int|null $ignoreJobRequestId A job being changed, whose own current
     *   booking must not count as a clash with its replacement.
     */
    public function plumberConflict(
        int $plumberId,
        string $startUtc,
        string $endUtc,
        ?int $ignoreJobRequestId = null,
    ): ?object {
        $query = $this->find()->contain(['JobRequests'])->innerJoinWith('JobRequests')
            ->where([
                'JobAssignments.plumber_admin_id' => $plumberId,
                'JobAssignments.scheduled_start <' => $endUtc,
                'JobAssignments.scheduled_end >' => $startUtc,
                'JobRequests.status IN' => [JobRequest::STATUS_ASSIGNED, JobRequest::STATUS_IN_PROGRESS],
            ])
            ->orderBy(['JobAssignments.scheduled_start' => 'ASC']);
        if ($ignoreJobRequestId !== null) {
            $query->where(['JobAssignments.job_request_id !=' => $ignoreJobRequestId]);
        }

        return $query->first();
    }

    /**
     * What each vehicle is doing right now and what is booked for it, for the
     * office's fleet pages.
     *
     * Only assigned and in-progress jobs count. A job that has been completed
     * (or reported as not completable) drops out here the moment the plumber
     * finishes it, which is what frees the vehicle: nothing is stored on the
     * vehicle itself, so there is no flag to forget to clear.
     *
     * A vehicle is "in use" while its plumber has started the job, however long
     * that takes, or while the clock is inside a visit that has been assigned.
     *
     * @param list<int> $vehicleIds The vehicles to look up.
     * @param string $nowUtc The moment to measure from, as Y-m-d H:i:s in UTC.
     * @return array<int, array{
     *     current: \Cake\Datasource\EntityInterface|null,
     *     upcoming: list<\Cake\Datasource\EntityInterface>
     * }>
     */
    public function vehicleSchedule(array $vehicleIds, string $nowUtc): array
    {
        $schedule = [];
        foreach ($vehicleIds as $id) {
            $schedule[(int)$id] = ['current' => null, 'upcoming' => []];
        }
        if ($vehicleIds === []) {
            return $schedule;
        }

        $open = $this->find()
            ->contain(['JobRequests', 'Plumbers'])
            ->innerJoinWith('JobRequests')
            ->where([
                'JobAssignments.vehicle_id IN' => $vehicleIds,
                'JobRequests.status IN' => [JobRequest::STATUS_ASSIGNED, JobRequest::STATUS_IN_PROGRESS],
            ])
            ->orderBy(['JobAssignments.scheduled_start' => 'ASC']);

        foreach ($open as $assignment) {
            $vehicleId = (int)$assignment->vehicle_id;
            $started = $assignment->job_request->status === JobRequest::STATUS_IN_PROGRESS;
            $startsAt = $assignment->scheduled_start->format('Y-m-d H:i:s');
            $endsAt = $assignment->scheduled_end->format('Y-m-d H:i:s');
            $happeningNow = $started || ($startsAt <= $nowUtc && $endsAt > $nowUtc);

            if ($happeningNow && $schedule[$vehicleId]['current'] === null) {
                $schedule[$vehicleId]['current'] = $assignment;
            } elseif ($endsAt > $nowUtc) {
                $schedule[$vehicleId]['upcoming'][] = $assignment;
            }
        }

        return $schedule;
    }

    /**
     * The jobs a vehicle has finished, newest first, for its history.
     *
     * @param int $vehicleId The vehicle.
     * @param int $limit How many to return.
     * @return list<\Cake\Datasource\EntityInterface>
     */
    public function vehicleHistory(int $vehicleId, int $limit = 10): array
    {
        return $this->find()
            ->contain(['JobRequests', 'Plumbers'])
            ->innerJoinWith('JobRequests')
            ->where([
                'JobAssignments.vehicle_id' => $vehicleId,
                'JobRequests.status IN' => [
                    JobRequest::STATUS_COMPLETED,
                    JobRequest::STATUS_UNABLE_TO_COMPLETE,
                ],
            ])
            ->orderBy(['JobAssignments.scheduled_start' => 'DESC'])
            ->limit($limit)
            ->all()
            ->toList();
    }

    /**
     * Where each plumber is right now and what they have next, for the office's
     * plumber list.
     *
     * Only assigned and in-progress jobs count, so a finished job never shows
     * as somebody's next one. A job is "current" while the plumber has started
     * it, or while the clock is inside its scheduled visit.
     *
     * @param list<int> $plumberIds The plumbers (admin ids) on the page.
     * @param string $nowUtc The moment to measure from, as Y-m-d H:i:s in UTC.
     * @return array<int, array{
     *     current: \Cake\Datasource\EntityInterface|null,
     *     next: \Cake\Datasource\EntityInterface|null,
     *     open: int
     * }>
     */
    public function scheduleSnapshot(array $plumberIds, string $nowUtc): array
    {
        $snapshot = [];
        foreach ($plumberIds as $id) {
            $snapshot[(int)$id] = ['current' => null, 'next' => null, 'open' => 0];
        }
        if ($plumberIds === []) {
            return $snapshot;
        }

        $open = $this->find()
            ->contain(['JobRequests'])
            ->innerJoinWith('JobRequests')
            ->where([
                'JobAssignments.plumber_admin_id IN' => $plumberIds,
                'JobRequests.status IN' => [JobRequest::STATUS_ASSIGNED, JobRequest::STATUS_IN_PROGRESS],
            ])
            ->orderBy(['JobAssignments.scheduled_start' => 'ASC']);

        foreach ($open as $assignment) {
            $plumberId = (int)$assignment->plumber_admin_id;
            $snapshot[$plumberId]['open']++;

            $started = $assignment->job_request->status === JobRequest::STATUS_IN_PROGRESS;
            $startsAt = $assignment->scheduled_start->format('Y-m-d H:i:s');
            $endsAt = $assignment->scheduled_end->format('Y-m-d H:i:s');
            $happeningNow = $started || ($startsAt <= $nowUtc && $endsAt > $nowUtc);

            if ($happeningNow && $snapshot[$plumberId]['current'] === null) {
                $snapshot[$plumberId]['current'] = $assignment;
            } elseif (!$happeningNow && $startsAt > $nowUtc && $snapshot[$plumberId]['next'] === null) {
                $snapshot[$plumberId]['next'] = $assignment;
            }
        }

        return $snapshot;
    }

    /**
     * Each plumber's average customer rating and how many ratings it rests on.
     *
     * A rating belongs to the job, and a job has one plumber, so this joins the
     * two and groups by plumber. Jobs nobody has rated are left out of both
     * the average and the count. A plumber with no ratings at all still gets an
     * entry, with a null average and a count of zero, so a page can show "No
     * ratings yet" without checking whether the key exists.
     *
     * Only the manager is meant to see these figures, so callers decide who
     * gets them; nothing here is filtered by who is asking.
     *
     * @param list<int> $plumberIds The plumbers (admin ids) on the page.
     */
    public function ratingSummary(array $plumberIds): array
    {
        $summary = [];
        foreach ($plumberIds as $id) {
            $summary[(int)$id] = ['average' => null, 'count' => 0];
        }
        if ($plumberIds === []) {
            return $summary;
        }

        $query = $this->find()->innerJoinWith('JobRequests');
        $rows = $query
            ->select([
                'plumber_id' => 'JobAssignments.plumber_admin_id',
                'ratings' => $query->func()->count('*'),
                'average' => $query->func()->avg('JobRequests.customer_rating'),
            ])
            ->where([
                'JobAssignments.plumber_admin_id IN' => $plumberIds,
                'JobRequests.customer_rating IS NOT' => null,
            ])
            ->groupBy(['JobAssignments.plumber_admin_id'])
            ->disableHydration()
            ->all();

        foreach ($rows as $row) {
            $summary[(int)$row['plumber_id']] = [
                'average' => (float)$row['average'],
                'count' => (int)$row['ratings'],
            ];
        }

        return $summary;
    }

    /** Any unclosed assignment prevents an admin from retiring a vehicle. */
    public function hasOpenVehicleAssignment(int $vehicleId): bool
    {
        return $this->find()->innerJoinWith('JobRequests')
            ->where([
                'JobAssignments.vehicle_id' => $vehicleId,
                'JobRequests.status IN' => [JobRequest::STATUS_ASSIGNED, JobRequest::STATUS_IN_PROGRESS],
            ])->count() > 0;
    }

    /**
     * The day a schedule board should open on when no date was chosen: today
     * if anything is booked today, else the next day with a booking, else the
     * most recent one. Null when nothing has ever been booked.
     *
     * @param string $column plumber_admin_id or vehicle_id.
     * @param list<int> $ids The plumbers or vehicles on the board.
     * @param \DateTimeImmutable $today Local midnight today.
     */
    public function nearestBookedDay(string $column, array $ids, DateTimeImmutable $today): ?DateTimeImmutable
    {
        if ($ids === [] || !in_array($column, ['plumber_admin_id', 'vehicle_id'], true)) {
            return null;
        }
        $timezone = $today->getTimezone();
        $todayUtc = $today->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $booked = fn() => $this->find()
            ->innerJoinWith('JobRequests')
            ->where([
                'JobAssignments.' . $column . ' IN' => $ids,
                'JobRequests.status IN' => [
                    JobRequest::STATUS_ASSIGNED,
                    JobRequest::STATUS_IN_PROGRESS,
                    JobRequest::STATUS_COMPLETED,
                    JobRequest::STATUS_UNABLE_TO_COMPLETE,
                ],
            ]);

        $next = $booked()->where(['JobAssignments.scheduled_start >=' => $todayUtc])
            ->orderBy(['JobAssignments.scheduled_start' => 'ASC'])->first();
        $last = $next === null
            ? $booked()->where(['JobAssignments.scheduled_start <' => $todayUtc])
                ->orderBy(['JobAssignments.scheduled_start' => 'DESC'])->first()
            : null;
        $found = $next ?? $last;
        if ($found === null) {
            return null;
        }
        $day = $found->scheduled_start->setTimezone($timezone)->format('Y-m-d');

        return new DateTimeImmutable($day, $timezone);
    }
}
