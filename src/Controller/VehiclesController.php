<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\JobRequest;
use App\Model\Entity\Vehicle;
use App\Model\Table\PlumberUnavailabilitiesTable;
use App\Validation\BusinessVisitTime;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use DateTimeImmutable;
use DateTimeZone;

/** Admin-only fleet register; taking a vehicle out of service never deletes it. */
/**
 * @property \App\Model\Table\VehiclesTable $Vehicles
 */
class VehiclesController extends AppController
{
    /** Manager and plumber accounts cannot manage the fleet. */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->requireAdmin();
    }

    /** List active and inactive vehicles so historical records remain visible. */
    public function index(): void
    {
        $vehicles = $this->paginate($this->Vehicles->find(), [
            'limit' => 25,
            'order' => ['Vehicles.active' => 'DESC', 'Vehicles.label' => 'ASC'],
        ]);
        $ids = [];
        foreach ($vehicles as $vehicle) {
            if ($vehicle->active) {
                $ids[] = (int)$vehicle->id;
            }
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $schedule = $this->fetchTable('JobAssignments')->vehicleSchedule($ids, $now->format('Y-m-d H:i:s'));
        $timezone = BusinessVisitTime::timezone();
        $this->set(compact('vehicles', 'schedule', 'timezone'));
    }

    /**
     * The fleet schedule: every active vehicle's bookings for one day or one
     * working week, and what each one is doing right now.
     *
     * Query: view=day|week (default day) and date=YYYY-MM-DD (default today).
     */
    public function schedule(): void
    {
        $this->request->allowMethod(['get']);
        $timezone = BusinessVisitTime::timezone();
        $utc = new DateTimeZone('UTC');
        $now = new DateTimeImmutable('now', $timezone);
        $today = $now->setTime(0, 0);

        $view = $this->request->getQuery('view') === 'week' ? 'week' : 'day';
        $vehicles = $this->Vehicles->find()
            ->where(['active' => true])
            ->orderBy(['label' => 'ASC'])->all()->toList();
        $inactiveCount = $this->Vehicles->find()->where(['active' => false])->count();
        $ids = array_map(static fn($vehicle): int => (int)$vehicle->id, $vehicles);

        // With no date chosen, open on a day that has bookings on it rather
        // than an empty weekend or quiet day.
        $date = $this->localDate($this->request->getQuery('date'), $timezone);
        $jumpedFromToday = false;
        if ($date === null) {
            $date = $this->fetchTable('JobAssignments')->nearestBookedDay('vehicle_id', $ids, $today) ?? $today;
            $jumpedFromToday = $date->format('Y-m-d') !== $today->format('Y-m-d');
        }
        if ($view === 'week') {
            $monday = $date->modify('-' . ((int)$date->format('N') - 1) . ' days');
            $days = [];
            for ($offset = 0; $offset < 7; $offset++) {
                $days[] = $monday->modify('+' . $offset . ' days');
            }
        } else {
            $days = [$date];
        }
        $rangeStart = $days[0];
        $rangeEnd = end($days);

        // Bookings in the visible range, keyed by vehicle then local date.
        $bookings = [];
        if ($ids !== []) {
            $assignments = $this->fetchTable('JobAssignments')->find()
                ->contain(['JobRequests', 'Plumbers'])
                ->innerJoinWith('JobRequests')
                ->where([
                    'JobAssignments.vehicle_id IN' => $ids,
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
                $bookings[(int)$assignment->vehicle_id][$start->format('Y-m-d')][] = [
                    'assignment' => $assignment,
                    'start' => (int)$start->format('G') * 60 + (int)$start->format('i'),
                    'end' => $start->format('Y-m-d') === $end->format('Y-m-d')
                        ? (int)$end->format('G') * 60 + (int)$end->format('i')
                        : 24 * 60,
                ];
            }
        }

        // Weekends are only shown when something is booked on them.
        $days = array_values(array_filter($days, static function ($day) use ($bookings): bool {
            if ((int)$day->format('N') <= 5) {
                return true;
            }
            foreach ($bookings as $byDay) {
                if (!empty($byDay[$day->format('Y-m-d')])) {
                    return true;
                }
            }

            return false;
        }));

        // What each vehicle is doing at this moment, from the same rule the
        // fleet list uses: in use while its job is under way.
        $minuteNow = (int)$now->format('G') * 60 + (int)$now->format('i');
        $inHours = (int)$now->format('N') <= 5
            && $minuteNow >= PlumberUnavailabilitiesTable::WORK_START_MINUTE
            && $minuteNow < PlumberUnavailabilitiesTable::WORK_END_MINUTE;
        $snapshot = $this->fetchTable('JobAssignments')
            ->vehicleSchedule($ids, $now->setTimezone($utc)->format('Y-m-d H:i:s'));
        $status = [];
        $summary = ['in_use' => 0, 'available' => 0, 'off_hours' => 0, 'bookings' => 0];
        foreach ($ids as $id) {
            $row = $snapshot[$id] ?? ['current' => null, 'upcoming' => []];
            $state = $row['current'] !== null ? 'in_use' : ($inHours ? 'available' : 'off_hours');
            $status[$id] = $row + ['state' => $state];
            $summary[$state]++;
            foreach ($bookings[$id] ?? [] as $dayBookings) {
                $summary['bookings'] += count($dayBookings);
            }
        }

        $this->set(compact(
            'vehicles',
            'inactiveCount',
            'view',
            'date',
            'days',
            'today',
            'now',
            'timezone',
            'bookings',
            'status',
            'summary',
            'jumpedFromToday',
        ));
    }

    /** Show one vehicle without exposing any job/customer information. */
    public function view(?string $id = null): void
    {
        $vehicle = $this->vehicle($id);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $assignments = $this->fetchTable('JobAssignments');
        $schedule = $assignments->vehicleSchedule([(int)$vehicle->id], $now->format('Y-m-d H:i:s'))[(int)$vehicle->id];
        $currentUse = $vehicle->active ? $schedule['current'] : null;
        $upcoming = $schedule['upcoming'];
        $history = $assignments->vehicleHistory((int)$vehicle->id);
        $timezone = BusinessVisitTime::timezone();
        $this->set(compact('vehicle', 'currentUse', 'upcoming', 'history', 'timezone'));
    }

    /** Vehicle availability for the exact interval selected on the dispatch form. */
    public function availability(): Response
    {
        $this->request->allowMethod(['get']);
        $start = BusinessVisitTime::parseForm($this->request->getQuery('start'));
        $end = BusinessVisitTime::endForDuration($start, $this->request->getQuery('duration'));
        if ($end === null) {
            return $this->response->withStatus(422)->withType('application/json')
                ->withStringBody(json_encode([
                    'error' => 'Choose a valid weekday visit ending by 17:00.',
                ], JSON_THROW_ON_ERROR));
        }

        $startUtc = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $endUtc = $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $assignments = $this->fetchTable('JobAssignments');
        $result = [];
        foreach ($this->Vehicles->find()->orderBy(['registration_number' => 'ASC']) as $vehicle) {
            $conflict = $vehicle->active ? $assignments->vehicleConflict((int)$vehicle->id, $startUtc, $endUtc) : null;
            $result[] = [
                'id' => (int)$vehicle->id,
                'label' => $vehicle->registration_number . ' — ' . $vehicle->label,
                'status' => !$vehicle->active ? 'Unavailable' : ($conflict ? 'In Use' : 'Available'),
                'plumber' => $conflict?->plumber?->admin_username,
            ];
        }

        return $this->response->withType('application/json')
            ->withStringBody(json_encode(['vehicles' => $result], JSON_THROW_ON_ERROR));
    }

    /** Add a uniquely registered vehicle. */
    public function add(): ?Response
    {
        $vehicle = $this->Vehicles->newEmptyEntity();
        if ($this->request->is('post')) {
            $vehicle = $this->Vehicles->patchEntity($vehicle, $this->formData());
            $vehicle->set('active', true);
            if ($this->Vehicles->save($vehicle)) {
                $this->recordChange('vehicle.created', (int)$vehicle->id);
                $this->Flash->success(__('Vehicle added.'));

                return $this->redirect(['action' => 'view', $vehicle->id]);
            }
            $this->Flash->error(__('The vehicle could not be saved. Check the fields below.'));
        }
        $this->set(compact('vehicle'));

        return null;
    }

    /** Edit descriptive information while preserving the vehicle ID and status. */
    public function edit(?string $id = null): ?Response
    {
        $vehicle = $this->vehicle($id);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $vehicle = $this->Vehicles->patchEntity($vehicle, $this->formData());
            if ($this->Vehicles->save($vehicle)) {
                $this->recordChange('vehicle.updated', (int)$vehicle->id);
                $this->Flash->success(__('Vehicle updated.'));

                return $this->redirect(['action' => 'view', $vehicle->id]);
            }
            $this->Flash->error(__('The vehicle could not be saved. Check the fields below.'));
        }
        $this->set(compact('vehicle'));

        return null;
    }

    /** "Delete" means mark unavailable, retaining the row for job history. */
    public function deactivate(?string $id = null): Response
    {
        return $this->setActive($id, false);
    }

    /** Return an inactive vehicle to the available fleet. */
    public function reactivate(?string $id = null): Response
    {
        return $this->setActive($id, true);
    }

    /** Normalise registration numbers for consistent uniqueness checks. */
    private function formData(): array
    {
        $data = $this->request->getData();
        unset($data['id'], $data['active'], $data['created'], $data['modified']);
        if (is_string($data['registration_number'] ?? null)) {
            $data['registration_number'] = strtoupper(trim(
                preg_replace('/\s+/', ' ', $data['registration_number']) ?? '',
            ));
        }
        if (is_string($data['label'] ?? null)) {
            $data['label'] = trim($data['label']);
        }
        if (is_string($data['notes'] ?? null)) {
            $data['notes'] = trim($data['notes']);
        }

        return $data;
    }

    /** Keep all vehicle status changes POST-only and audit the actual change. */
    private function setActive(?string $id, bool $active): Response
    {
        $this->request->allowMethod(['post']);
        $vehicle = $this->vehicle($id);
        $changed = $this->Vehicles->getConnection()->transactional(function () use ($vehicle, $active): bool {
            $locked = $this->Vehicles->locked((int)$vehicle->id);
            if (!$active && $this->fetchTable('JobAssignments')->hasOpenVehicleAssignment((int)$locked->id)) {
                return false;
            }
            if ((bool)$locked->active !== $active) {
                $locked->set('active', $active);
                $this->Vehicles->saveOrFail($locked);
                $this->recordChange($active ? 'vehicle.reactivated' : 'vehicle.deactivated', (int)$locked->id);
            }

            return true;
        });
        if (!$changed) {
            $this->Flash->error(__('This vehicle has an assigned or in-progress job. Complete those jobs first.'));

            return $this->redirect(['action' => 'view', $vehicle->id]);
        }
        $this->Flash->success($active ? __('Vehicle reactivated.') : __('Vehicle deactivated.'));

        return $this->redirect(['action' => 'view', $vehicle->id]);
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

    /** Reject malformed references before querying the fleet table. */
    private function vehicle(?string $id): Vehicle
    {
        if ($id === null || !ctype_digit($id)) {
            throw new NotFoundException('Vehicle not found.');
        }

        return $this->Vehicles->get((int)$id);
    }

    /** Write an append-only audit event for a fleet change. */
    private function recordChange(string $action, int $vehicleId): void
    {
        $this->fetchTable('AuditLogs')->record(
            $action,
            $this->currentAdminId(),
            'vehicle',
            (string)$vehicleId,
            [],
            $this->request->clientIp() ?? '0.0.0.0',
        );
    }
}
