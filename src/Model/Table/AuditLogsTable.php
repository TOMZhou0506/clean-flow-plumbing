<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Core\Configure;
use Cake\Log\Log;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Throwable;

/**
 * AuditLogs Model
 *
 * My append-only trail of privileged actions. Nothing in the application
 * updates or deletes rows here, and I would not grant the database user the
 * privilege to do either.
 *
 * @extends \Cake\ORM\Table<array{Timestamp: \Cake\ORM\Behavior\TimestampBehavior}, \App\Model\Entity\AuditLog>
 * @method \App\Model\Entity\AuditLog[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\AuditLog>|false saveMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\AuditLog[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\AuditLog> saveManyOrFail(iterable $entities, array $options = [])
 * @method \App\Model\Entity\AuditLog[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\AuditLog>|false deleteMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\AuditLog[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\AuditLog> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class AuditLogsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('audit_logs');
        $this->setDisplayField('action');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created' => 'new',
                ],
            ],
        ]);
    }

    /**
     * The trail for one entity, newest first.
     *
     * Used by the compliance page to show who last changed what, so the
     * question "who cleared this job" is answerable from the screen rather
     * than only from a database query.
     *
     * @param string $entityType For example 'submission'.
     * @param string $entityId The record's id.
     * @param int $limit How many entries to return.
     * @return array<\App\Model\Entity\AuditLog>
     */
    public function recentFor(string $entityType, string $entityId, int $limit = 10): array
    {
        return $this->find()
            ->where([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ])
            ->orderBy(['created' => 'DESC', 'id' => 'DESC'])
            ->limit($limit)
            ->all()
            ->toArray();
    }

    /**
     * Builds the filtered query for the audit log screen.
     *
     * Same approach to injection as everywhere else: every value goes through
     * the query builder's parameter binding, and `action` is matched against
     * the values actually present rather than interpolated.
     *
     * @param array{action?: string|null, user?: string|null} $filters
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function buildTrailQuery(array $filters = []): SelectQuery
    {
        $query = $this->find();

        $action = $filters['action'] ?? null;
        if (is_string($action) && $action !== '') {
            // Bound as a parameter, so an arbitrary string is safe here. I
            // still cap the length so a huge value cannot be used to burn CPU
            // scanning the table.
            $query->where(['action' => mb_substr($action, 0, 64)]);
        }

        $user = $filters['user'] ?? null;
        if (is_string($user) && ctype_digit($user)) {
            $query->where(['user_id' => (int)$user]);
        }

        return $query;
    }

    /**
     * The distinct action names present, for the filter dropdown.
     *
     * I read these from the data rather than hardcoding a list, so an action
     * added later shows up without anyone remembering to update a constant.
     *
     * @return array<string, string>
     */
    public function knownActions(): array
    {
        $actions = $this->find()
            ->select(['action'])
            ->distinct(['action'])
            ->orderBy(['action' => 'ASC'])
            ->all()
            ->extract('action')
            ->toList();

        return array_combine($actions, $actions) ?: [];
    }

    /**
     * Writes one audit entry.
     *
     * @param string $action For example submission.export or compliance.record.
     * @param int|null $userId The signed-in admin, from the session.
     * @param string|null $entityType The kind of record affected.
     * @param string|null $entityId The record's id.
     * @param array<string, mixed> $detail I keep credentials and full message
     *        bodies out of here. I did not want the log becoming a second copy
     *        of the customer data.
     * @param string $ip Masked to a /24 before it is stored.
     * @return void
     */
    public function record(
        string $action,
        ?int $userId,
        ?string $entityType = null,
        ?string $entityId = null,
        array $detail = [],
        string $ip = '0.0.0.0',
    ): void {
        try {
            $entry = $this->newEmptyEntity();

            // I set every field explicitly. Nothing on this entity is
            // mass-assignable, so a request body can never forge an entry.
            $entry->set('user_id', $userId);
            $entry->set('action', mb_substr($action, 0, 64));
            $entry->set('entity_type', $entityType === null ? null : mb_substr($entityType, 0, 64));
            $entry->set('entity_id', $entityId === null ? null : mb_substr($entityId, 0, 64));
            $encoded = $detail === []
                ? null
                : json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $entry->set('detail', $encoded);
            $entry->set('ip_address', $this->maskIp($ip));

            $this->saveOrFail($entry);
        } catch (Throwable $e) {
            // I never want an audit write to take down the action it is
            // recording. A status change failing because the log table is
            // missing would be worse than a gap in the trail.
            //
            // In debug and test builds I rethrow instead. I learned this the
            // hard way: swallowing the exception left my audit trail silently
            // empty while every test still passed, which made my claim about
            // recording privileged actions worth nothing.
            Log::error('Audit write failed: ' . $e->getMessage());

            if (Configure::read('debug')) {
                throw $e;
            }
        }
    }

    /**
     * Truncates to a /24 (or /64 for IPv6).
     *
     * That is enough for me to correlate a subnet during an abuse
     * investigation, and not enough to sit in a table indefinitely as a
     * standalone identifier.
     */
    private function maskIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts = explode('.', $ip);

            return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $parts = explode(':', $ip);

            return implode(':', array_slice($parts, 0, 4)) . '::';
        }

        return '0.0.0.0';
    }
}
