<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Password reset codes for office accounts.
 *
 * @method \App\Model\Entity\PasswordReset newEmptyEntity()
 * @method \App\Model\Entity\PasswordReset saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @extends \Cake\ORM\Table<array{Timestamp: \Cake\ORM\Behavior\TimestampBehavior}, \App\Model\Entity\PasswordReset>
 * @method \App\Model\Entity\PasswordReset[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\PasswordReset>|false saveMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\PasswordReset[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\PasswordReset> saveManyOrFail(iterable $entities, array $options = [])
 * @method \App\Model\Entity\PasswordReset[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\PasswordReset>|false deleteMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\PasswordReset[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\PasswordReset> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class PasswordResetsTable extends Table
{
    /**
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('password_resets');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp', [
            'events' => ['Model.beforeSave' => ['created' => 'new']],
        ]);
    }
}
