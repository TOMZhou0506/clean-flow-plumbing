<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Vehicle;
use Cake\Database\Driver\Mysql;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/** Validates and stores the administrator's fleet register. */
/**
 * @extends \Cake\ORM\Table<array{Timestamp: \Cake\ORM\Behavior\TimestampBehavior}, \App\Model\Entity\Vehicle>
 * @method \App\Model\Entity\Vehicle[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Vehicle>|false saveMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\Vehicle[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Vehicle> saveManyOrFail(iterable $entities, array $options = [])
 * @method \App\Model\Entity\Vehicle[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Vehicle>|false deleteMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\Vehicle[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Vehicle> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class VehiclesTable extends Table
{
    /** Lock the fleet row during a dispatch or deactivation transaction on MySQL. */
    public function locked(int $id): Vehicle
    {
        $query = $this->find()->where(['Vehicles.id' => $id]);
        if ($this->getConnection()->getDriver() instanceof Mysql) {
            $query->epilog('FOR UPDATE');
        }

        return $query->firstOrFail();
    }

    /** Set the database name, display field and timestamps. */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('vehicles');
        $this->setDisplayField('label');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }

    /** Validate the small set of fields needed for an identifiable vehicle. */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('registration_number')
            ->minLength('registration_number', 2)
            ->maxLength('registration_number', 15)
            ->add('registration_number', 'format', [
                'rule' => ['custom', '/^[A-Z0-9][A-Z0-9 -]*$/'],
                'message' => 'Use letters, numbers, spaces or hyphens for the registration number.',
            ])
            ->requirePresence('registration_number', 'create')
            ->notEmptyString('registration_number');

        $validator
            ->scalar('label')
            ->minLength('label', 2)
            ->maxLength('label', 100)
            ->requirePresence('label', 'create')
            ->notEmptyString('label');

        $validator
            ->allowEmptyString('notes')
            ->scalar('notes')
            ->maxLength('notes', 500);

        return $validator;
    }

    /** A registration number identifies one vehicle, even while inactive. */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['registration_number']), [
            'errorField' => 'registration_number',
            'message' => 'This registration number is already in use.',
        ]);

        return $rules;
    }
}
