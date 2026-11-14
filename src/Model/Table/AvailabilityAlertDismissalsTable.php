<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Admin;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use DateTimeImmutable;

/** Per-admin read state for immutable availability events in the audit trail. */
/**
 * @extends \Cake\ORM\Table<array{Timestamp: \Cake\ORM\Behavior\TimestampBehavior}, \Cake\ORM\Entity>
 * @property \App\Model\Table\AdminsTable&\Cake\ORM\Association\BelongsTo $Admins
 * @property \App\Model\Table\AuditLogsTable&\Cake\ORM\Association\BelongsTo $AuditLogs
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class AvailabilityAlertDismissalsTable extends Table
{
    /** Configure the acknowledgement table. */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('availability_alert_dismissals');
        $this->setPrimaryKey('id');
        $this->belongsTo('Admins', ['foreignKey' => 'admin_id']);
        $this->belongsTo('AuditLogs', ['foreignKey' => 'audit_log_id']);
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
    }

    /**
     * Only future/current plumber changes are actionable. A cancellation
     * supersedes the earlier "added" alert for the same period.
     *
     * @return list<array{event: \App\Model\Entity\AuditLog, period: \App\Model\Entity\PlumberUnavailability, plumber: \App\Model\Entity\Admin}>
     */
    public function unreadForAdmin(int $adminId): array
    {
        $today = (new DateTimeImmutable('now', ContactSubmissionsTable::businessTimezone()))->format('Y-m-d');
        $accounts = TableRegistry::getTableLocator()->get('Admins');
        $plumbers = $accounts->find()->where(['role' => Admin::ROLE_STAFF])->all()->indexBy('admin_id')->toArray();
        if ($plumbers === []) {
            return [];
        }

        $periods = TableRegistry::getTableLocator()->get('PlumberUnavailabilities')->find()
            ->where(['admin_id IN' => array_keys($plumbers), 'unavailable_date >=' => $today])
            ->all()->indexBy('id')->toArray();
        if ($periods === []) {
            return [];
        }

        $dismissed = $this->find()->select(['audit_log_id'])->where(['admin_id' => $adminId])
            ->all()->extract('audit_log_id')->toList();
        $dismissed = array_fill_keys(array_map('intval', $dismissed), true);
        $events = TableRegistry::getTableLocator()->get('AuditLogs')->find()
            ->where([
                'entity_type' => 'plumber_unavailability',
                'entity_id IN' => array_map('strval', array_keys($periods)),
                'action IN' => ['plumber.unavailable_added', 'plumber.unavailable_cancelled'],
            ])
            ->orderBy(['created' => 'DESC', 'id' => 'DESC'])->all();

        $alerts = [];
        foreach ($events as $event) {
            $period = $periods[(int)$event->entity_id] ?? null;
            if ($period === null || isset($dismissed[(int)$event->id])) {
                continue;
            }
            if ($period->cancelled_at !== null && $event->action === 'plumber.unavailable_added') {
                continue;
            }
            if ($period->cancelled_at === null && $event->action === 'plumber.unavailable_cancelled') {
                continue;
            }
            $plumber = $plumbers[(int)$period->admin_id] ?? null;
            if ($plumber !== null) {
                $alerts[] = compact('event', 'period', 'plumber');
            }
        }

        return $alerts;
    }
}
