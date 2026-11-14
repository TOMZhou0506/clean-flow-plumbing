<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Admin;
use App\Model\Entity\ComplianceCheck;
use App\Model\Entity\JobRequest;
use App\Model\Table\JobRequestsTable;
use App\Validation\BusinessVisitTime;
use Authentication\Authenticator\UnauthenticatedException;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Job requests, for customers and for the office.
 *
 * This controller serves two different audiences, which is unusual and worth
 * saying out loud. index, add and confirmation belong to the signed-in
 * customer and are scoped to their own rows by customerId(). manage and
 * manageView belong to the office and show every customer's requests.
 *
 * That is why the guard below lists the customer actions rather than the admin
 * ones. Anything added to this controller later requires the admin role until
 * someone deliberately says otherwise, so the mistake of forgetting a guard
 * fails closed rather than exposing every customer's address to whoever asks.
 */
class JobRequestsController extends AppController
{
    /**
     * Rows per page on the office list.
     *
     * @var int
     */
    private const PER_PAGE = 25;

    /**
     * Actions belonging to the signed-in customer. Everything else is office
     * only. See the note on the class.
     *
     * @var list<string>
     */
    private const CUSTOMER_ACTIONS = ['index', 'add', 'confirmation', 'rate'];

    /**
     * Requires the admin role for anything that is not a customer action.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        if (!in_array($this->request->getParam('action'), self::CUSTOMER_ACTIONS, true)) {
            $this->requireAdmin();
        }
    }

    /**
     * Every customer's job requests, for the office.
     *
     * Separate from index() on purpose. index() is the customer looking at
     * their own requests; this is Hannah looking at all of them. Same table,
     * different question, different permissions, so a different action rather
     * than a conditional inside one.
     *
     * @return void
     */
    public function manage(): void
    {
        // routes.php ends with fallbacks(), and /{controller}/{action}/* reads
        // anything trailing as passed arguments. So /job-requests/manage/x/y/z
        // and, more to the point, a GET to /job-requests/manage/status/1 that
        // misses the POST-only status route, both land here and quietly render
        // the list as though the URL were fine.
        //
        // manage() takes no arguments, so any passed argument means the URL was
        // not one we serve. 404 says so instead of pretending.
        if ($this->request->getParam('pass') !== []) {
            throw new NotFoundException('No such page.');
        }

        $request = $this->request;

        $filters = [
            'q' => $request->getQuery('q'),
            'status' => $request->getQuery('status'),
            'from' => $request->getQuery('from'),
            'to' => $request->getQuery('to'),
        ];

        $filters = $this->withUsableDateRange($filters);

        $query = $this->JobRequests->buildListQuery($filters);

        $this->paginate = [
            'limit' => self::PER_PAGE,
            'maxLimit' => 100,
            // Cake's own guard, fed with my allowlist. A sort parameter naming
            // a column outside it is ignored rather than reaching ORDER BY.
            'sortableFields' => array_keys(JobRequestsTable::SORTABLE),
            'order' => ['JobRequests.created' => 'desc'],
        ];

        $jobRequests = $this->paginate($query);
        $availabilityAlerts = $this->fetchTable('AvailabilityAlertDismissals')
            ->unreadForAdmin((int)$this->currentAdminId());

        $this->set([
            'jobRequests' => $jobRequests,
            'stats' => $this->JobRequests->summaryCounts(),
            'statuses' => JobRequest::STATUSES,
            'filters' => $filters,
            'availabilityAlerts' => $availabilityAlerts,
        ]);
    }

    /**
     * Moves one request to a new status.
     *
     * POST only, reached through Form->postLink, so a crawler or a prefetching
     * browser cannot change anything.
     *
     * @param string|null $id The request id.
     * @return \Cake\Http\Response|null
     */
    public function changeStatus(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post', 'put', 'patch']);

        $status = $this->request->getData('status');

        if ($id === null || !ctype_digit($id) || !is_string($status)) {
            $this->Flash->error(__('That request was not valid.'));

            return $this->redirect(['action' => 'manage']);
        }

        if (!in_array($status, JobRequest::STATUSES, true)) {
            $this->Flash->error(__('Unrecognised status.'));

            return $this->redirect(['action' => 'manage']);
        }

        try {
            $this->JobRequests->changeStatus((int)$id, $status, $this->currentAdminId());
        } catch (RecordNotFoundException) {
            $this->Flash->error(__('That job request no longer exists.'));

            return $this->redirect(['action' => 'manage']);
        } catch (InvalidArgumentException $exception) {
            $this->Flash->error(__($exception->getMessage()));

            return $this->redirect(['action' => 'manageView', $id]);
        }

        $this->fetchTable('AuditLogs')->record(
            'job_request.status_change',
            $this->currentAdminId(),
            'job_request',
            (string)$id,
            ['to' => $status],
            $this->request->clientIp() ?? '0.0.0.0',
        );

        $this->Flash->success(__('Job request marked as {0}.', h($status)));

        // $local = true, so a crafted Referer header cannot turn this into an
        // open redirect.
        return $this->redirect($this->referer(['action' => 'manage'], true));
    }

    /**
     * Downloads the filtered list as CSV.
     *
     * @return \Cake\Http\Response
     */
    public function exportCsv(): Response
    {
        $request = $this->request;

        $filters = [
            'q' => $request->getQuery('q'),
            'status' => $request->getQuery('status'),
            'from' => $request->getQuery('from'),
            'to' => $request->getQuery('to'),
        ];

        // The export has to agree with the list it was launched from, or the
        // CSV quietly contains a different set of rows than the screen shows.
        $filters = $this->withUsableDateRange($filters);

        $query = $this->JobRequests
            ->buildListQuery($filters)
            ->orderBy(['JobRequests.created' => 'DESC']);

        // Bulk extraction of customer addresses and phone numbers, so it is
        // always audited.
        $this->fetchTable('AuditLogs')->record(
            'job_request.export',
            $this->currentAdminId(),
            'job_request',
            null,
            $filters,
            $this->request->clientIp() ?? '0.0.0.0',
        );

        $stream = fopen('php://temp', 'r+');
        // BOM, so Excel opens the file as UTF-8.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [
            'id', 'received', 'customer', 'address', 'suburb', 'state',
            'postcode', 'preferred', 'phone', 'status', 'description',
        ], ',', '"', '');

        $tz = $this->JobRequests->businessTimezone();

        foreach ($query->all() as $jobRequest) {
            /** @var \App\Model\Entity\JobRequest $jobRequest */
            fputcsv($stream, [
                $jobRequest->id,
                $jobRequest->created?->setTimezone($tz)->format('c'),
                $this->neutraliseFormula((string)($jobRequest->customer->cust_email ?? '')),
                $this->neutraliseFormula((string)$jobRequest->street_address),
                $this->neutraliseFormula((string)$jobRequest->suburb),
                $this->neutraliseFormula((string)$jobRequest->state),
                $this->neutraliseFormula((string)$jobRequest->postcode),
                $jobRequest->preferred_datetime?->setTimezone($tz)->format('c'),
                $this->neutraliseFormula((string)$jobRequest->contact_phone),
                $jobRequest->status,
                $this->neutraliseFormula((string)$jobRequest->job_description),
            ], ',', '"', '');
        }

        rewind($stream);
        $csv = (string)stream_get_contents($stream);
        fclose($stream);

        return $this->response
            ->withType('csv')
            ->withDownload('job-requests-' . date('Y-m-d') . '.csv')
            ->withStringBody($csv);
    }

    /**
     * Defuses a cell that a spreadsheet would treat as a formula.
     *
     * Excel runs a cell beginning with =, +, - or @. A customer controls the
     * address and description fields, so without this an exported file could
     * execute something on the machine of whoever opens it. A leading tab
     * makes the cell text; the tab is not displayed.
     *
     * @param string $value The cell value.
     * @return string
     */
    private function neutraliseFormula(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@'], true) ? "\t" . $value : $value;
    }

    /**
     * One job request in full, for the office.
     *
     * @param string|null $id The request id.
     * @return \Cake\Http\Response|null
     */
    public function manageView(?string $id = null): ?Response
    {
        if ($id === null || !ctype_digit($id)) {
            $this->Flash->error(__('That job request reference is not valid.'));

            return $this->redirect(['action' => 'manage']);
        }

        try {
            $jobRequest = $this->JobRequests->get((int)$id, contain: [
                'Customers', 'JobAssignments.Plumbers', 'JobAssignments.Vehicles',
            ]);
        } catch (RecordNotFoundException) {
            $this->Flash->error(__('That job request no longer exists.'));

            return $this->redirect(['action' => 'manage']);
        }

        // Opening a request records that somebody has seen it, so the office
        // list can highlight the ones nobody has picked up. Only the first
        // time: read_at answers "when was this first seen".
        $this->JobRequests->markRead($jobRequest, $this->currentAdminId());
        if ($jobRequest->isDirty()) {
            $this->JobRequests->saveOrFail($jobRequest);
        }

        // A request nobody has picked up yet becomes "under review" the moment
        // the office opens it, which is the point the compliance checklist on
        // this page starts. The customer sees "Under review" from then on.
        if ($jobRequest->status === JobRequest::STATUS_SUBMITTED) {
            try {
                $this->JobRequests->changeStatus(
                    $jobRequest->id,
                    JobRequest::STATUS_REVIEWING,
                    $this->currentAdminId(),
                );
                $this->fetchTable('AuditLogs')->record(
                    'job_request.status_change',
                    $this->currentAdminId(),
                    'job_request',
                    (string)$jobRequest->id,
                    ['to' => JobRequest::STATUS_REVIEWING],
                    $this->request->clientIp() ?? '0.0.0.0',
                );
                $jobRequest->set('status', JobRequest::STATUS_REVIEWING);
                $jobRequest->setDirty('status', false);
            } catch (InvalidArgumentException) {
                // Someone else moved it in the meantime. Show it as it is.
            }
        }

        // Reading a job request reads a customer's address and phone number,
        // so it goes in the audit trail exactly as opening an enquiry does.
        $this->fetchTable('AuditLogs')->record(
            'job_request.view',
            $this->currentAdminId(),
            'job_request',
            (string)$jobRequest->id,
            [],
            $this->request->clientIp() ?? '0.0.0.0',
        );

        /** @var \App\Model\Table\ComplianceChecksTable $checks */
        $checks = $this->fetchTable('ComplianceChecks');

        $this->set([
            'jobRequest' => $jobRequest,
            'progress' => $checks->progressFor($jobRequest->id, ComplianceCheck::SUBJECT_JOB_REQUEST),
            'isCompliant' => $checks->isCompliant($jobRequest->id, ComplianceCheck::SUBJECT_JOB_REQUEST),
            'checklist' => $checks->checklistFor($jobRequest->id, ComplianceCheck::SUBJECT_JOB_REQUEST),
        ]);

        // The assignment form only appears once compliance is cleared.
        if (
            $jobRequest->status === JobRequest::STATUS_APPROVED &&
            $jobRequest->job_assignment === null
        ) {
            $this->set($this->dispatchOptions());
            $this->set('job', $jobRequest);
            $this->set('error', null);
        }

        return null;
    }

    /**
     * Moves a request on from the compliance check to assignment.
     *
     * POST only. The model refuses the move unless every compliance check is
     * ticked, so this stays safe even if the button is shown by mistake.
     *
     * @param string|null $id The request id.
     * @return \Cake\Http\Response|null
     */
    public function proceed(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post']);

        if ($id === null || !ctype_digit($id)) {
            $this->Flash->error(__('That request was not valid.'));

            return $this->redirect(['action' => 'manage']);
        }

        try {
            $this->JobRequests->changeStatus((int)$id, JobRequest::STATUS_APPROVED, $this->currentAdminId());
        } catch (RecordNotFoundException) {
            $this->Flash->error(__('That job request no longer exists.'));

            return $this->redirect(['action' => 'manage']);
        } catch (InvalidArgumentException $exception) {
            $this->Flash->error(__($exception->getMessage()));

            return $this->redirect(['action' => 'manageView', $id]);
        }

        $this->fetchTable('AuditLogs')->record(
            'job_request.proceed',
            $this->currentAdminId(),
            'job_request',
            (string)$id,
            ['to' => JobRequest::STATUS_APPROVED],
            $this->request->clientIp() ?? '0.0.0.0',
        );
        $this->Flash->success(__('Compliance complete. You can now assign a plumber and vehicle.'));

        return $this->redirect(['action' => 'manageView', $id]);
    }

    /**
     * Which plumbers and vehicles are free for one proposed visit.
     *
     * Read only. It answers the question the assignment form asks as soon as a
     * start time and duration are chosen, and assign() checks everything again
     * on submit, so this is guidance rather than the safeguard.
     *
     * @return \Cake\Http\Response
     */
    public function availability(): Response
    {
        $this->request->allowMethod(['get']);

        $start = BusinessVisitTime::parseForm($this->request->getQuery('start'));
        $end = BusinessVisitTime::endForDuration($start, $this->request->getQuery('duration'));
        if ($end === null) {
            return $this->response->withStatus(422)->withType('application/json')
                ->withStringBody(json_encode([
                    'error' => 'Choose a future weekday visit that ends by 17:00.',
                ], JSON_THROW_ON_ERROR));
        }

        $utc = new DateTimeZone('UTC');
        $startUtc = $start->setTimezone($utc)->format('Y-m-d H:i:s');
        $endUtc = $end->setTimezone($utc)->format('Y-m-d H:i:s');
        $startMinute = (int)$start->format('G') * 60 + (int)$start->format('i');
        $endMinute = (int)$end->format('G') * 60 + (int)$end->format('i');

        // When the office is changing a job's allocation, that job's own
        // current booking must not make its plumber or vehicle look busy.
        $jobParam = $this->request->getQuery('job');
        $ignoreId = is_string($jobParam) && ctype_digit($jobParam) ? (int)$jobParam : null;

        $assignments = $this->fetchTable('JobAssignments');
        $timeOff = $this->fetchTable('PlumberUnavailabilities');

        $plumbers = [];
        $accounts = $this->fetchTable('Admins')->find()
            ->where(['role' => Admin::ROLE_STAFF, 'active' => true])
            ->orderBy(['admin_username' => 'ASC']);
        foreach ($accounts as $account) {
            $plumberId = (int)$account->admin_id;
            $conflict = $assignments->plumberConflict($plumberId, $startUtc, $endUtc, $ignoreId);
            $unavailable = $timeOff->overlaps($plumberId, $start->format('Y-m-d'), $startMinute, $endMinute);
            $plumbers[] = [
                'id' => $plumberId,
                'label' => $account->admin_username,
                'status' => $unavailable ? 'Unavailable' : ($conflict ? 'Busy' : 'Available'),
                'detail' => $unavailable
                    ? 'Marked this time as unavailable'
                    : ($conflict ? 'Already on job ' . $conflict->job_request->reference() : null),
            ];
        }

        $vehicles = [];
        foreach ($this->fetchTable('Vehicles')->find()->orderBy(['registration_number' => 'ASC']) as $vehicle) {
            $conflict = $vehicle->active
                ? $assignments->vehicleConflict((int)$vehicle->id, $startUtc, $endUtc, $ignoreId)
                : null;
            $vehicles[] = [
                'id' => (int)$vehicle->id,
                'label' => $vehicle->registration_number . ' — ' . $vehicle->label,
                'status' => !$vehicle->active ? 'Unavailable' : ($conflict ? 'In use' : 'Available'),
                'detail' => !$vehicle->active
                    ? 'Out of service'
                    : ($conflict ? 'Already on job ' . $conflict->job_request->reference() : null),
            ];
        }

        return $this->response->withType('application/json')
            ->withStringBody(json_encode([
                'plumbers' => $plumbers,
                'vehicles' => $vehicles,
            ], JSON_THROW_ON_ERROR));
    }

    /**
     * The plumbers and vehicles the assignment form can choose from.
     *
     * @return array{plumbers: array<int, string>, vehicleOptions: array<int, string>}
     */
    private function dispatchOptions(): array
    {
        $plumbers = $this->fetchTable('Admins')
            ->find('list', keyField: 'admin_id', valueField: 'admin_username')
            ->where(['role' => Admin::ROLE_STAFF, 'active' => true])
            ->orderBy(['admin_username' => 'ASC'])
            ->toArray();

        $vehicleOptions = [];
        $vehicles = $this->fetchTable('Vehicles')->find('all')
            ->where(['active' => true])
            ->orderBy(['registration_number' => 'ASC'])
            ->all();
        foreach ($vehicles as $vehicle) {
            $vehicleOptions[$vehicle->id] = $vehicle->registration_number . ' — ' . $vehicle->label;
        }

        return ['plumbers' => $plumbers, 'vehicleOptions' => $vehicleOptions];
    }

    /**
     * Allocate a job to a plumber and vehicle, or change an existing allocation.
     *
     * An approved job with nobody on it is being assigned for the first time.
     * A job that is assigned but not yet approved by the plumber is being
     * changed: the same form, filled with the current choice, updates the
     * existing booking. Once the plumber has started the job it is theirs and
     * can no longer be moved from here.
     *
     * @param string|null $id The request id.
     * @return \Cake\Http\Response|null
     */
    public function assign(?string $id = null): ?Response
    {
        if ($id === null || !ctype_digit($id)) {
            throw new NotFoundException('Job request not found.');
        }
        $job = $this->JobRequests->get((int)$id, contain: ['JobAssignments']);
        $existing = $job->job_assignment;
        $changing = $existing !== null && $job->status === JobRequest::STATUS_ASSIGNED;
        $ignoreId = $changing ? (int)$job->id : null;
        ['plumbers' => $plumbers, 'vehicleOptions' => $vehicleOptions] = $this->dispatchOptions();
        $error = null;

        if ($this->request->is('post')) {
            $plumberId = filter_var($this->request->getData('plumber_admin_id'), FILTER_VALIDATE_INT);
            $vehicleId = filter_var($this->request->getData('vehicle_id'), FILTER_VALIDATE_INT);
            $start = BusinessVisitTime::parseForm($this->request->getData('scheduled_start'));
            $end = BusinessVisitTime::endForDuration($start, $this->request->getData('duration_hours'));
            $assignments = $this->fetchTable('JobAssignments');

            if (!$changing && ($job->status !== JobRequest::STATUS_APPROVED || $existing !== null)) {
                $error = $existing !== null
                    ? __('This job has been started by the plumber, so its allocation can no longer be changed.')
                    : __('Only an approved, unassigned request can be scheduled.');
            } elseif ($plumberId === false || !isset($plumbers[$plumberId])) {
                $error = __('Choose an active plumber.');
            } elseif ($vehicleId === false || !isset($vehicleOptions[$vehicleId])) {
                $error = __('Choose an available vehicle.');
            } elseif ($end === null) {
                $error = __('Choose a future weekday start and a 1–8 hour visit ending by 17:00.');
            } else {
                $startMinute = (int)$start->format('G') * 60 + (int)$start->format('i');
                $endMinute = (int)$end->format('G') * 60 + (int)$end->format('i');
                $startUtc = $start->setTimezone(new DateTimeZone('UTC'));
                $endUtc = $end->setTimezone(new DateTimeZone('UTC'));
                $startText = $startUtc->format('Y-m-d H:i:s');
                $endText = $endUtc->format('Y-m-d H:i:s');

                $vehicleConflict = $assignments->vehicleConflict($vehicleId, $startText, $endText, $ignoreId);
                if ($vehicleConflict !== null) {
                    $error = $this->conflictMessage(__('vehicle'), $vehicleConflict);
                }
                if (
                    $error === null &&
                    $this->fetchTable('PlumberUnavailabilities')->overlaps(
                        $plumberId,
                        $start->format('Y-m-d'),
                        $startMinute,
                        $endMinute,
                    )
                ) {
                    $error = __('This plumber marked that period unavailable.');
                }
                if ($error === null) {
                    $plumberConflict = $assignments->plumberConflict($plumberId, $startText, $endText, $ignoreId);
                    if ($plumberConflict !== null) {
                        $error = $this->conflictMessage(__('plumber'), $plumberConflict);
                    } else {
                        try {
                            $this->JobRequests->getConnection()->transactional(function () use (
                                $assignments,
                                $job,
                                $changing,
                                $ignoreId,
                                $plumberId,
                                $vehicleId,
                                $startUtc,
                                $endUtc,
                            ): void {
                                $fresh = $this->JobRequests->get($job->id);
                                $expected = $changing ? JobRequest::STATUS_ASSIGNED : JobRequest::STATUS_APPROVED;
                                if ($fresh->status !== $expected) {
                                    throw new InvalidArgumentException('This request has changed since you opened it.');
                                }
                                $lockedVehicle = $this->fetchTable('Vehicles')->locked($vehicleId);
                                if (
                                    !$lockedVehicle->active ||
                                    $assignments->vehicleConflict(
                                        $vehicleId,
                                        $startUtc->format('Y-m-d H:i:s'),
                                        $endUtc->format('Y-m-d H:i:s'),
                                        $ignoreId,
                                    ) !== null
                                ) {
                                    throw new InvalidArgumentException('This vehicle is no longer available.');
                                }
                                $assignment = $changing
                                    ? $assignments->find()->where(['job_request_id' => $job->id])->firstOrFail()
                                    : $assignments->newEmptyEntity();
                                $assignment->set('job_request_id', $job->id);
                                $assignment->set('plumber_admin_id', $plumberId);
                                $assignment->set('vehicle_id', $vehicleId);
                                $assignment->set('assigned_by', $this->currentAdminId());
                                $assignment->set('scheduled_start', $startUtc->format('Y-m-d H:i:s'));
                                $assignment->set('scheduled_end', $endUtc->format('Y-m-d H:i:s'));
                                $assignments->saveOrFail($assignment);
                                $fresh->set('status', JobRequest::STATUS_ASSIGNED);
                                $this->JobRequests->saveOrFail($fresh);
                            });
                            $this->fetchTable('AuditLogs')->record(
                                $changing ? 'job_request.reassigned' : 'job_request.assigned',
                                $this->currentAdminId(),
                                'job_request',
                                (string)$job->id,
                                ['plumber_admin_id' => $plumberId, 'vehicle_id' => $vehicleId],
                                $this->request->clientIp() ?? '0.0.0.0',
                            );
                            $this->Flash->success($changing ? __('Allocation updated.') : __('Job assigned.'));

                            return $this->redirect(['action' => 'manageView', $job->id]);
                        } catch (Throwable) {
                            $error = __('The job could not be saved. Please refresh and try again.');
                        }
                    }
                }
            }
        }

        $assignment = $changing ? $existing : null;
        $this->set(compact('job', 'plumbers', 'vehicleOptions', 'error', 'assignment'));

        return null;
    }

    /**
     * Takes the plumber and vehicle off a job that the plumber has not yet
     * approved, sending it back to "Ready to assign".
     *
     * POST only. Once the plumber has started the job it can no longer be
     * withdrawn from here, because the plumber is already doing the work.
     *
     * @param string|null $id The request id.
     * @return \Cake\Http\Response
     */
    public function unassign(?string $id = null): Response
    {
        $this->request->allowMethod(['post']);

        if ($id === null || !ctype_digit($id)) {
            $this->Flash->error(__('That request was not valid.'));

            return $this->redirect(['action' => 'manage']);
        }

        try {
            $job = $this->JobRequests->get((int)$id);
        } catch (RecordNotFoundException) {
            $this->Flash->error(__('That job request no longer exists.'));

            return $this->redirect(['action' => 'manage']);
        }

        $assignments = $this->fetchTable('JobAssignments');
        $removed = $this->JobRequests->getConnection()->transactional(
            function () use ($assignments, $job): bool {
                $fresh = $this->JobRequests->get($job->id);
                $assignment = $assignments->find()->where(['job_request_id' => $job->id])->first();
                if ($fresh->status !== JobRequest::STATUS_ASSIGNED || $assignment === null) {
                    return false;
                }
                $assignments->deleteOrFail($assignment);
                $fresh->set('status', JobRequest::STATUS_APPROVED);
                $this->JobRequests->saveOrFail($fresh);

                return true;
            },
        );

        if (!$removed) {
            $this->Flash->error(__('Only a job waiting for the plumber to approve it can be taken off them.'));

            return $this->redirect(['action' => 'manageView', $job->id]);
        }

        $this->fetchTable('AuditLogs')->record(
            'job_request.unassigned',
            $this->currentAdminId(),
            'job_request',
            (string)$job->id,
            [],
            $this->request->clientIp() ?? '0.0.0.0',
        );
        $this->Flash->success(__('Allocation removed. The job is ready to assign again.'));

        return $this->redirect(['action' => 'manageView', $job->id]);
    }

    /**
     * A warning that names the job causing a clash and when it is on.
     *
     * @param string $thing What is double booked, either "plumber" or "vehicle".
     * @param \Cake\Datasource\EntityInterface $conflict The clashing assignment, with its job request.
     * @return string
     */
    private function conflictMessage(string $thing, EntityInterface $conflict): string
    {
        $tz = BusinessVisitTime::timezone();

        return __(
            'This {0} is already on job {1} ({2}–{3}). Choose a different time or another {0}.',
            $thing,
            $conflict->job_request->reference(),
            $conflict->scheduled_start->setTimezone($tz)->format('D j M, g:ia'),
            $conflict->scheduled_end->setTimezone($tz)->format('g:ia'),
        );
    }

    // currentAdminId() moved to AppController. This copy read admin_id, which
    // is the correct column; the copies in ComplianceController and
    // ContactSubmissionsController read getIdentifier() and did not. One
    // shared method now, so the two cannot drift apart again.

    /**
     * List requests belonging to the authenticated customer.
     *
     * @return void
     */
    public function index(): void
    {
        $query = $this->JobRequests->find()
            ->contain(['JobAssignments'])
            ->where(['JobRequests.customer_id' => $this->customerId()]);
        $jobRequests = $this->paginate($query, [
            'limit' => 10,
            'order' => ['JobRequests.created' => 'DESC'],
        ]);

        $this->set(compact('jobRequests'));
    }

    /**
     * Display and process the customer job request form.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function add(): ?Response
    {
        $customerId = $this->customerId();
        $jobRequest = $this->JobRequests->newEmptyEntity();
        $preferredDate = '';
        $preferredTime = '';

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            if (array_key_exists('preferred_date', $data) || array_key_exists('preferred_time', $data)) {
                $preferredDate = is_string($data['preferred_date'] ?? null) ? $data['preferred_date'] : '';
                $preferredTime = is_string($data['preferred_time'] ?? null) ? $data['preferred_time'] : '';
                $data['preferred_datetime'] = $preferredDate . 'T' . $preferredTime;
            }
            $preferred = BusinessVisitTime::parseForm($data['preferred_datetime'] ?? null);
            if ($preferred !== null) {
                $data['preferred_datetime'] = $preferred;
            }
            $jobRequest = $this->JobRequests->patchEntity($jobRequest, $data);
            if ($preferred === null) {
                $jobRequest->setError('preferred_datetime', [
                    'businessHours' => 'Choose a future weekday between 08:00 and 16:00 (Melbourne time).',
                ]);
            }
            $jobRequest->set('customer_id', $customerId);
            $jobRequest->set('status', JobRequest::STATUS_SUBMITTED);

            if ($preferred !== null && $this->JobRequests->save($jobRequest)) {
                return $this->redirect(['action' => 'confirmation', $jobRequest->id]);
            }

            $this->Flash->error('Please correct the highlighted fields and try again.');
        }

        $customer = $this->fetchTable('Customers')->get($customerId);
        // Only a number in the format this form accepts. An older profile can
        // hold one that is not, and pre-filling it would just show an error.
        if (
            !$jobRequest->contact_phone
            && $customer->cust_phone
            && preg_match('/^(?:04\d{2} \d{3} \d{3}|03 \d{4} \d{4})$/', (string)$customer->cust_phone) === 1
        ) {
            $jobRequest->set('contact_phone', $customer->cust_phone);
        }

        $states = array_combine(
            $this->JobRequests::AUSTRALIAN_STATES,
            $this->JobRequests::AUSTRALIAN_STATES,
        );
        $preferredMin = (new DateTimeImmutable('now', BusinessVisitTime::timezone()))->format('Y-m-d\TH:i');
        $preferredTimeOptions = BusinessVisitTime::preferredStartSlots();
        $this->set(compact(
            'jobRequest',
            'states',
            'preferredMin',
            'preferredDate',
            'preferredTime',
            'preferredTimeOptions',
        ));

        return null;
    }

    /**
     * Show the submitted request to its owner.
     *
     * @param int $id Job request id.
     * @return void
     */
    public function confirmation(int $id): void
    {
        $jobRequest = $this->JobRequests->find()
            ->contain(['JobAssignments'])
            ->where([
                'JobRequests.id' => $id,
                'JobRequests.customer_id' => $this->customerId(),
            ])
            ->firstOrFail();

        $this->set(compact('jobRequest'));
    }

    /**
     * Save the signed-in customer's star rating of one of their completed jobs.
     *
     * The job is looked up by id and owner together, so asking for somebody
     * else's job is a 404, the same as it is for the details page. The rating
     * itself is written by one conditional update in the table, which is what
     * stops it being saved twice.
     *
     * @param int $id Job request id.
     * @return \Cake\Http\Response
     */
    public function rate(int $id): Response
    {
        $this->request->allowMethod(['post']);

        $jobRequest = $this->JobRequests->find()
            ->contain(['JobAssignments'])
            ->where([
                'JobRequests.id' => $id,
                'JobRequests.customer_id' => $this->customerId(),
            ])
            ->firstOrFail();

        $stars = $this->JobRequests->parseRating($this->request->getData('rating'));

        if ($stars === null) {
            $this->Flash->error('Please choose a rating from 1 to 5 stars.');
        } elseif ($jobRequest->isRated()) {
            $this->Flash->error('You have already rated this job.');
        } elseif (!$jobRequest->canBeRated()) {
            $this->Flash->error('You can only rate a job once it has been completed.');
        } elseif ($this->JobRequests->rateCompletedJob($jobRequest->id, $this->customerId(), $stars)) {
            $this->Flash->success('Thank you. Your rating has been saved.');
        } else {
            // The conditional update matched nothing: another click got there first.
            $this->Flash->error('You have already rated this job.');
        }

        return $this->redirect(['action' => 'confirmation', $jobRequest->id]);
    }

    /**
     * Return the authenticated customer's primary key.
     *
     * @return int
     */
    private function customerId(): int
    {
        $identity = $this->request->getAttribute('identity');
        $customerId = $identity?->get('cust_id');
        if (!is_int($customerId) && !ctype_digit((string)$customerId)) {
            $this->request->getSession()->delete('CustomerAuth');
            throw new UnauthenticatedException('A customer account is required to submit a job request.');
        }

        return (int)$customerId;
    }
}
