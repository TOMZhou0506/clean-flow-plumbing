<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\ContactSubmission;
use App\Model\Table\ContactSubmissionsTable;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * ContactSubmissions Controller
 *
 * The admin dashboard for enquiries sent through the public contact form.
 *
 * I server render this with CakePHP templates so it merges with the rest of
 * the site. I first built this screen as a React app served from
 * webroot/admin, then removed it once we agreed the whole site should be
 * templates. I kept the JSON API under src/Controller/Api, because I wrote
 * tests against it and it is still useful if we ever want a separate client.
 *
 * I deliberately do not opt out of authentication anywhere here. AppController
 * loads the Authentication component for every controller, so an anonymous
 * visitor is redirected to /login before any of my actions run.
 *
 * @property \App\Model\Table\ContactSubmissionsTable $ContactSubmissions
 */
class ContactSubmissionsController extends AppController
{
    /**
     * Rows per page. I matched the default I used in the API.
     *
     * @var int
     */
    private const PER_PAGE = 25;

    /**
     * Every action here reads customer personal data, so every action needs
     * the admin role, not merely a session.
     *
     * I put this in beforeFilter rather than repeating a check at the top of
     * each action. Adding an action to this controller and forgetting the
     * check is exactly the mistake I want to be impossible, and a per-action
     * check makes it easy.
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
     * Dashboard list.
     *
     * @return void
     */
    public function index(): void
    {
        $request = $this->request;

        $filters = [
            'q' => $request->getQuery('q'),
            'status' => $request->getQuery('status'),
            'from' => $request->getQuery('from'),
            'to' => $request->getQuery('to'),
        ];

        $filters = $this->withUsableDateRange($filters);

        // I escape LIKE metacharacters inside buildListQuery, and I ignore a
        // status that is not one of my three known values, so a crafted query
        // string cannot widen the result set or reach the SQL.
        $query = $this->ContactSubmissions->buildListQuery($filters);

        $this->paginate = [
            'limit' => self::PER_PAGE,
            'maxLimit' => 100,
            // Cake's own guard, which I feed with my allowlist. A sort
            // parameter naming a column outside it is ignored rather than
            // passed into ORDER BY.
            'sortableFields' => array_keys(ContactSubmissionsTable::SORTABLE),
            'order' => ['ContactSubmissions.submitted_at' => 'desc'],
        ];

        $submissions = $this->paginate($query);

        $stats = $this->ContactSubmissions->summaryCounts();
        $statuses = ContactSubmission::STATUSES;

        $this->set(compact(
            'submissions',
            'stats',
            'filters',
            'statuses',
        ));
    }

    /**
     * One enquiry, with the full message.
     *
     * @param string|null $id Submission id.
     * @return \Cake\Http\Response|null
     */
    public function view(?string $id = null): ?Response
    {
        $submissionId = $this->parseId($id);

        if ($submissionId === null) {
            $this->Flash->error(__('That enquiry reference is not valid.'));

            return $this->redirect(['action' => 'index']);
        }

        try {
            $submission = $this->ContactSubmissions->get($submissionId);
        } catch (RecordNotFoundException) {
            $this->Flash->error(__('That enquiry no longer exists.'));

            return $this->redirect(['action' => 'index']);
        }

        // Opening a record reads customer personal data, so I log it. I
        // explain the audit trail in AuditLogsTable.
        $this->fetchTable('AuditLogs')->record(
            'submission.view',
            $this->currentAdminId(),
            'submission',
            (string)$submission->id,
            [],
            $this->clientIp(),
        );

        $statuses = ContactSubmission::STATUSES;
        $this->set(compact('submission', 'statuses'));

        return null;
    }

    /**
     * Moves one enquiry to a new status.
     *
     * I made this POST only, reached through Form->postLink, so a GET from a
     * crawler or a prefetching browser cannot change anything.
     *
     * @param string|null $id Submission id.
     * @return \Cake\Http\Response|null
     */
    public function changeStatus(?string $id = null): ?Response
    {
        $this->request->allowMethod(['post', 'put', 'patch']);

        $submissionId = $this->parseId($id);
        $status = $this->request->getData('status');

        if ($submissionId === null || !is_string($status)) {
            $this->Flash->error(__('That request was not valid.'));

            return $this->redirect(['action' => 'index']);
        }

        if (!in_array($status, ContactSubmission::STATUSES, true)) {
            $this->Flash->error(__('Unrecognised status.'));

            return $this->redirect(['action' => 'index']);
        }

        try {
            $this->ContactSubmissions->changeStatus(
                $submissionId,
                $status,
                $this->currentAdminId(),
            );
        } catch (RecordNotFoundException) {
            $this->Flash->error(__('That enquiry no longer exists.'));

            return $this->redirect(['action' => 'index']);
        }

        $this->fetchTable('AuditLogs')->record(
            'submission.status_change',
            $this->currentAdminId(),
            'submission',
            (string)$submissionId,
            ['to' => $status],
            $this->clientIp(),
        );

        $this->Flash->success(__('Enquiry marked as {0}.', h($status)));

        // I pass $local = true to referer() so it refuses an off-site URL.
        // Otherwise a crafted Referer header turns this into an open redirect.
        return $this->redirect($this->referer(['action' => 'index'], true));
    }

    /**
     * Downloads the current filtered list as CSV.
     *
     * @return \Cake\Http\Response
     */
    public function export(): Response
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

        $query = $this->ContactSubmissions
            ->buildListQuery($filters)
            ->orderBy(['ContactSubmissions.submitted_at' => 'DESC']);

        // This is bulk extraction of customer personal data, so I always
        // audit it.
        $this->fetchTable('AuditLogs')->record(
            'submission.export',
            $this->currentAdminId(),
            'submission',
            null,
            $filters,
            $this->clientIp(),
        );

        $stream = fopen('php://temp', 'r+');
        // I write a BOM so Excel opens the file as UTF-8.
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
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="submissions-' . gmdate('Ymd-His') . '.csv"',
            )
            ->withStringBody($body);
    }

    /**
     * CSV injection guard.
     *
     * If a submitter's name is "=cmd|'/c calc'!A1", it becomes a live formula
     * when the client opens my export in Excel. I prefix any leading formula
     * trigger with a tab so it stays text.
     *
     * @param string $value Raw cell value.
     * @return string
     */
    private function neutraliseFormula(string $value): string
    {
        if ($value !== '' && str_contains("=+-@\t\r", $value[0])) {
            return "\t" . $value;
        }

        return $value;
    }

    /**
     * Positive integer id from the URL, or null.
     *
     * @param mixed $value Raw value.
     * @return int|null
     */
    private function parseId(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int)$id;
    }

    // currentAdminId() now lives on AppController. My copy here called
    // $identity->getIdentifier(), which returns get('id'), and admins has no
    // id column, so it recorded a null user on every real login. See the note
    // on AppController::currentAdminId().

    /**
     * The client IP, or 0.0.0.0 when it cannot be determined.
     *
     * @return string
     */
    private function clientIp(): string
    {
        $ip = $this->request->clientIp();

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}
