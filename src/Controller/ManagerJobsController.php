<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\JobRequest;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;

/** Read-only job oversight for the manager; dispatch remains admin-only. */
class ManagerJobsController extends AppController
{
    /** Every action in this controller requires the current manager role. */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->requireManager();
    }

    /** All requests, including those not yet dispatched, with a status filter. */
    public function index(): void
    {
        $this->request->allowMethod(['get']);
        if ($this->request->getParam('pass') !== []) {
            throw new NotFoundException('Job overview not found.');
        }

        $status = $this->request->getQuery('status');
        $query = $this->fetchTable('JobRequests')->find()
            ->contain(['JobAssignments.Plumbers', 'JobAssignments.AssignedBy', 'JobAssignments.Vehicles']);
        if (is_string($status) && in_array($status, JobRequest::STATUSES, true)) {
            $query->where(['JobRequests.status' => $status]);
        } else {
            $status = null;
        }
        $jobs = $this->paginate($query, [
            'limit' => 25,
            'maxLimit' => 50,
            'sortableFields' => ['JobRequests.created', 'JobRequests.id'],
            'order' => ['JobRequests.created' => 'DESC', 'JobRequests.id' => 'DESC'],
        ]);
        $this->set(compact('jobs', 'status'));
    }

    /** One job's assignment and workflow history, without customer contact details. */
    public function view(?string $id = null): void
    {
        $this->request->allowMethod(['get']);
        if ($id === null || !ctype_digit($id)) {
            throw new NotFoundException('Job request not found.');
        }

        $job = $this->fetchTable('JobRequests')->get((int)$id, contain: [
            'JobAssignments.Plumbers',
            'JobAssignments.AssignedBy',
            'JobAssignments.Vehicles',
        ]);
        $logs = $this->fetchTable('AuditLogs')->find()
            ->where([
                'entity_type' => 'job_request',
                'entity_id' => (string)$job->id,
                'action IN' => [
                    'job_request.status_change',
                    'job_request.assigned',
                    'job_request.started',
                    'job_request.completed',
                    'job_request.unable_to_complete',
                ],
            ])
            ->orderBy(['created' => 'DESC', 'id' => 'DESC'])
            ->limit(50)
            ->all()
            ->toArray();

        $actorIds = array_values(array_unique(array_filter(array_map(
            static fn($log): ?int => $log->user_id === null ? null : (int)$log->user_id,
            $logs,
        ))));
        $actors = $actorIds === [] ? [] : $this->fetchTable('Admins')
            ->find('list', keyField: 'admin_id', valueField: 'admin_username')
            ->where(['admin_id IN' => $actorIds])
            ->toArray();
        $history = [];
        foreach (array_reverse($logs) as $log) {
            $status = match ($log->action) {
                'job_request.assigned' => JobRequest::STATUS_ASSIGNED,
                'job_request.started' => JobRequest::STATUS_IN_PROGRESS,
                'job_request.completed' => JobRequest::STATUS_COMPLETED,
                'job_request.unable_to_complete' => JobRequest::STATUS_UNABLE_TO_COMPLETE,
                default => $this->statusFromLog($log->detail),
            };
            if ($status === null) {
                continue;
            }
            $actorId = $log->user_id === null ? null : (int)$log->user_id;
            $history[] = [
                'status' => $status,
                'when' => $log->created,
                'actor' => $actorId === null ? 'Unknown' : ($actors[$actorId] ?? 'Account #' . $actorId),
            ];
        }

        $this->set(compact('job', 'history'));
    }

    /** Audit status-change details contain only the destination status. */
    private function statusFromLog(?string $detail): ?string
    {
        $data = is_string($detail) ? json_decode($detail, true) : null;
        $status = is_array($data) ? ($data['to'] ?? null) : null;

        return is_string($status) && in_array($status, JobRequest::STATUSES, true) ? $status : null;
    }
}
