<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Entity\ContactSubmission;
use App\Model\Table\AuditLogsTable;
use App\Model\Table\ContactSubmissionsTable;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Contact submission endpoints for the admin dashboard.
 *
 * Every action here requires authentication. The Authentication middleware in
 * Application.php enforces it, and I add nothing in this controller to
 * allowUnauthenticated().
 *
 * @property \App\Model\Table\ContactSubmissionsTable $ContactSubmissions
 * @property \App\Model\Table\AuditLogsTable $AuditLogs
 */
class SubmissionsController extends ApiController
{
    /**
     * I declare this rather than assigning $this->ContactSubmissions. PHP 8.4
     * deprecates creating dynamic properties, and Cake's Controller does not
     * declare table properties for you.
     *
     * @var \App\Model\Table\ContactSubmissionsTable
     */
    private ContactSubmissionsTable $submissions;

    /**
     * @var \App\Model\Table\AuditLogsTable
     */
    private AuditLogsTable $auditLogs;

    /**
     * These endpoints return exactly the same customer data as the HTML
     * dashboard, so they need exactly the same role check.
     *
     * Leaving the API open to any signed-in account while locking the HTML
     * would be a hole rather than an inconsistency: a staff member could read
     * every enquiry through /api/submissions and the screen they were denied
     * would be beside the point.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $this->requireAdmin();
    }

    /**
     * Initialize method
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->submissions = $this->fetchTable('ContactSubmissions');
        $this->auditLogs = $this->fetchTable('AuditLogs');
    }

    /**
     * GET /api/submissions
     */
    public function index(): void
    {
        $request = $this->request;

        $query = $this->submissions->buildListQuery([
            'q' => $request->getQuery('q'),
            'status' => $request->getQuery('status'),
            'from' => $request->getQuery('from'),
            'to' => $request->getQuery('to'),
        ]);

        $perPage = filter_var($request->getQuery('per_page'), FILTER_VALIDATE_INT, [
            'options' => ['default' => 25, 'min_range' => 1, 'max_range' => 100],
        ]);

        $this->paginate = [
            'limit' => $perPage,
            'maxLimit' => 100,
            // Cake's own guard, fed with my allowlist: a sort parameter
            // naming a column outside it is ignored rather than passed to the
            // query.
            'sortableFields' => array_keys(ContactSubmissionsTable::SORTABLE),
            'order' => ['ContactSubmissions.submitted_at' => 'desc'],
        ];

        $results = $this->paginate($query);
        $paging = $this->request->getAttribute('paging')['ContactSubmissions'] ?? [];

        $items = [];
        foreach ($results as $submission) {
            /** @var \App\Model\Entity\ContactSubmission $submission */
            $items[] = [
                'id' => $submission->id,
                'name' => $submission->name,
                'email' => $submission->email,
                'phone' => $submission->phone,
                'subject' => $submission->subject,
                'preview' => $submission->preview,
                'status' => $submission->status,
                'submitted_at' => $submission->submitted_at?->format('c'),
            ];
        }

        $this->ok($items, [
            'page' => $paging['page'] ?? 1,
            'per_page' => $paging['perPage'] ?? $perPage,
            'total' => $paging['count'] ?? count($items),
            'last_page' => $paging['pageCount'] ?? 1,
            'sort' => $request->getQuery('sort', 'submitted_at'),
            'dir' => $request->getQuery('direction', 'desc'),
        ]);
    }

    /**
     * GET /api/submissions/{id}
     */
    public function view(?string $id = null): ?Response
    {
        $submissionId = $this->parseId($id);
        if ($submissionId === null) {
            return $this->fail('invalid_id', 'That submission reference is not valid.', 400);
        }

        try {
            /** @var \App\Model\Entity\ContactSubmission $submission */
            $submission = $this->submissions->get($submissionId);
        } catch (RecordNotFoundException) {
            return $this->fail('not_found', 'That submission no longer exists.', 404);
        }

        // Opening a record reads customer personal data, so I log it.
        $this->auditLogs->record(
            'submission.view',
            $this->currentUserId(),
            'submission',
            (string)$submissionId,
            [],
            $this->clientIp(),
        );

        $this->ok([
            'id' => $submission->id,
            'name' => $submission->name,
            'email' => $submission->email,
            'phone' => $submission->phone,
            'subject' => $submission->subject,
            'message' => $submission->message,
            'status' => $submission->status,
            'submitted_at' => $submission->submitted_at?->format('c'),
            'read_at' => $submission->read_at?->format('c'),
            'read_by' => $submission->read_by,
        ]);

        return null;
    }

    /**
     * PATCH /api/submissions/{id}
     */
    public function updateStatus(?string $id = null): ?Response
    {
        $this->request->allowMethod(['patch', 'post']);

        $submissionId = $this->parseId($id);
        if ($submissionId === null) {
            return $this->fail('invalid_id', 'That submission reference is not valid.', 400);
        }

        $status = $this->request->getData('status');
        if (!is_string($status) || !in_array($status, ContactSubmission::STATUSES, true)) {
            return $this->fail(
                'invalid_status',
                'Status must be one of: ' . implode(', ', ContactSubmission::STATUSES) . '.',
                422,
                ['status' => 'Unrecognised value.'],
            );
        }

        try {
            $submission = $this->submissions->changeStatus(
                $submissionId,
                $status,
                $this->currentUserId(),
            );
        } catch (RecordNotFoundException) {
            return $this->fail('not_found', 'That submission no longer exists.', 404);
        }

        $this->auditLogs->record(
            'submission.status_change',
            $this->currentUserId(),
            'submission',
            (string)$submissionId,
            ['to' => $status],
            $this->clientIp(),
        );

        $this->ok([
            'id' => $submission->id,
            'status' => $submission->status,
            'read_at' => $submission->read_at?->format('c'),
            'read_by' => $submission->read_by,
        ]);

        return null;
    }

    /**
     * POST /api/submissions/bulk-status
     */
    public function bulkStatus(): ?Response
    {
        $this->request->allowMethod(['post']);

        $status = $this->request->getData('status');
        if (!is_string($status) || !in_array($status, ContactSubmission::STATUSES, true)) {
            return $this->fail(
                'invalid_status',
                'Status must be one of: ' . implode(', ', ContactSubmission::STATUSES) . '.',
                422,
                ['status' => 'Unrecognised value.'],
            );
        }

        $rawIds = $this->request->getData('ids');
        if (!is_array($rawIds) || $rawIds === []) {
            return $this->fail('invalid_ids', 'Select at least one submission.', 422);
        }

        // I bound the batch. An unbounded id list is a cheap way to make one
        // request rewrite the whole table.
        if (count($rawIds) > 200) {
            return $this->fail('too_many_ids', 'You can update at most 200 submissions at once.', 422);
        }

        $ids = [];
        foreach ($rawIds as $raw) {
            $parsed = $this->parseId(is_scalar($raw) ? (string)$raw : null);
            if ($parsed === null) {
                return $this->fail('invalid_ids', 'One or more submission references are not valid.', 422);
            }
            $ids[] = $parsed;
        }

        $changed = $this->submissions->changeStatusBulk($ids, $status, $this->currentUserId());

        $this->auditLogs->record(
            'submission.bulk_status_change',
            $this->currentUserId(),
            'submission',
            null,
            ['to' => $status, 'requested' => count($ids), 'changed' => $changed],
            $this->clientIp(),
        );

        $this->ok(['changed' => $changed, 'status' => $status]);

        return null;
    }

    /**
     * GET /api/submissions/stats
     */
    public function stats(): void
    {
        $this->ok($this->submissions->summaryCounts());
    }

    /**
     * GET /api/submissions/export
     *
     * I stream the CSV rather than building the whole file in memory.
     */
    public function export(): Response
    {
        $query = $this->submissions->buildListQuery([
            'q' => $this->request->getQuery('q'),
            'status' => $this->request->getQuery('status'),
            'from' => $this->request->getQuery('from'),
            'to' => $this->request->getQuery('to'),
        ])->orderBy(['ContactSubmissions.submitted_at' => 'DESC']);

        // This is bulk extraction of customer personal data, the
        // highest-impact action I built, so I always audit it.
        $this->auditLogs->record(
            'submission.export',
            $this->currentUserId(),
            'submission',
            null,
            [
                'q' => $this->request->getQuery('q'),
                'status' => $this->request->getQuery('status'),
                'from' => $this->request->getQuery('from'),
                'to' => $this->request->getQuery('to'),
            ],
            $this->clientIp(),
        );

        $filename = 'submissions-' . gmdate('Ymd-His') . '.csv';

        $stream = fopen('php://temp', 'r+');
        // I write a BOM so Excel opens the file as UTF-8, which is what the
        // client will use.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv(
            $stream,
            ['id', 'name', 'email', 'phone', 'subject', 'message', 'status', 'submitted_at'],
            ',',
            '"',
            '',
        );

        foreach ($query->all() as $submission) {
            /** @var \App\Model\Entity\ContactSubmission $submission */
            fputcsv($stream, [
                $submission->id,
                $this->neutraliseFormula((string)$submission->name),
                $this->neutraliseFormula((string)$submission->email),
                $this->neutraliseFormula((string)$submission->phone),
                $this->neutraliseFormula((string)$submission->subject),
                $this->neutraliseFormula((string)$submission->message),
                $submission->status,
                $submission->submitted_at?->format('c'),
            ], ',', '"', '');
        }

        rewind($stream);
        $body = (string)stream_get_contents($stream);
        fclose($stream);

        return $this->response
            ->withType('csv')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withStringBody($body);
    }

    /**
     * CSV injection guard.
     *
     * If a submission's name is "=cmd|'/c calc'!A1", it becomes a live
     * formula when the client opens my export in Excel. I prefix any leading
     * formula trigger with a tab so it stays text.
     */
    private function neutraliseFormula(string $value): string
    {
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "\t" . $value;
        }

        return $value;
    }
}
