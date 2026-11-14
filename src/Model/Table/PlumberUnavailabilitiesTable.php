<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/** Stores one-off exceptions to the business's standard working week. */
/**
 * @extends \Cake\ORM\Table<array{Timestamp: \Cake\ORM\Behavior\TimestampBehavior}, \App\Model\Entity\PlumberUnavailability>
 * @property \App\Model\Table\AdminsTable&\Cake\ORM\Association\BelongsTo $Admins
 * @method \App\Model\Entity\PlumberUnavailability[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\PlumberUnavailability>|false saveMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\PlumberUnavailability[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\PlumberUnavailability> saveManyOrFail(iterable $entities, array $options = [])
 * @method \App\Model\Entity\PlumberUnavailability[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\PlumberUnavailability>|false deleteMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\PlumberUnavailability[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\PlumberUnavailability> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
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
