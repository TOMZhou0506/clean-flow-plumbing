<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/** Stores one-off exceptions to the business's standard working week. */
class PlumberUnavailabilitiesTable extends Table
{
    public const WORK_START_MINUTE = 8 * 60;

    public const WORK_END_MINUTE = 17 * 60;

    /**
     * @param array<string, mixed> $config Table configuration.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('plumber_unavailabilities');
        $this->setPrimaryKey('id');
        $this->belongsTo('Admins', ['foreignKey' => 'admin_id']);
        $this->addBehavior('Timestamp');
    }

    /** Whether a current exception overlaps this local-date interval. */
    public function overlaps(int $adminId, string $date, int $startMinute, int $endMinute): bool
    {
        return $this->find()
            ->where([
                'admin_id' => $adminId,
                'unavailable_date' => $date,
                'cancelled_at IS' => null,
                'start_minute <' => $endMinute,
                'end_minute >' => $startMinute,
            ])
            ->count() > 0;
    }
}
