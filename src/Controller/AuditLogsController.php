<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Event\EventInterface;

/**
 * AuditLogs Controller
 *
 * US 3.2 asks for the audit trail to be checked: that a change is timestamped
 * and attributed to the admin who made it. The rows already carried that, but
 * only a database query could show them, which is not something you can
 * demonstrate to a client or hand to a marker.
 *
 * Read only, deliberately. There is no add, edit or delete action here and
 * there should never be one. An audit trail that the application can rewrite
 * is not evidence of anything: the whole value of it rests on nobody being
 * able to tidy up their own entries afterwards. Ideally the database user
 * would have INSERT and SELECT on this table and not UPDATE or DELETE, so
 * even a compromised application account could not rewrite history.
 *
 * @property \App\Model\Table\AuditLogsTable $AuditLogs
 */
class AuditLogsController extends AppController
{
    /**
     * Rows per page.
     *
     * @var int
     */
    private const PER_PAGE = 50;

    /**
     * The trail records who read customer data, so reading the trail is
     * itself an admin-only action.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // Manager, not admin. The team decided the audit trail belongs to the
        // manager role: an admin is one of the people the trail exists to
        // record, so letting them read it lets them check whether anybody
        // would notice what they did. Separating the two is the whole point of
        // keeping an audit log at all.
        $this->requireManager();
    }

    /**
     * The audit trail.
     *
     * @return void
     */
    public function index(): void
    {
        $auditLogs = $this->fetchTable('AuditLogs');

        $filters = [
            'action' => $this->request->getQuery('action'),
            'user' => $this->request->getQuery('user'),
        ];

        $query = $auditLogs->buildTrailQuery($filters);

        $this->paginate = [
            'limit' => self::PER_PAGE,
            'maxLimit' => 200,
            // Only these two columns can be sorted on. Anything else in the
            // query string is ignored rather than reaching ORDER BY.
            'sortableFields' => ['created', 'action'],
            'order' => ['AuditLogs.created' => 'desc', 'AuditLogs.id' => 'desc'],
        ];

        $entries = $this->paginate($query);

        $this->set([
            'entries' => $entries,
            'filters' => $filters,
            'actions' => $auditLogs->knownActions(),
        ]);
    }
}
