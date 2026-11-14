<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Validation\StrongPassword;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\FactoryLocator;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Customers Model.
 *
 * @extends \Cake\ORM\Table<array{}, \App\Model\Entity\Customer>
 * @property \App\Model\Table\JobRequestsTable&\Cake\ORM\Association\HasMany $JobRequests
 * @method \App\Model\Entity\Customer[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Customer>|false saveMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\Customer[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Customer> saveManyOrFail(iterable $entities, array $options = [])
 * @method \App\Model\Entity\Customer[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Customer>|false deleteMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\Customer[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Customer> deleteManyOrFail(iterable $entities, array $options = [])
 */
class CustomersTable extends Table
{
    /**
     * Configure the legacy customer table and its request association.
     *
     * @param array<string, mixed> $config Table configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('customer');
        $this->setPrimaryKey('cust_id');
        $this->setDisplayField('cust_email');

        $this->hasMany('JobRequests', [
            'foreignKey' => 'customer_id',
        ]);
    }

    /**
     * Validate customer registration fields.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->email('cust_email')
            ->maxLength('cust_email', 255)
            ->requirePresence('cust_email', 'create')
            ->notEmptyString('cust_email');

        foreach (['cust_fname', 'cust_lname'] as $field) {
            $validator
                ->scalar($field)
                ->maxLength($field, 50)
                ->requirePresence($field, 'create')
                ->notEmptyString($field);
        }

        $validator
            ->scalar('cust_phone')
            ->maxLength('cust_phone', 12)
            ->add('cust_phone', 'format', [
                'rule' => ['custom', '/^(?:04\d{2} \d{3} \d{3}|03 \d{4} \d{4})$/'],
                'message' => 'Use a 03 landline (03 9123 4567) or 04 mobile (0412 345 678).',
            ])
            ->allowEmptyString('cust_phone');

        $validator
            ->scalar('cust_address')
            ->maxLength('cust_address', 255)
            ->allowEmptyString('cust_address');

        $validator
            ->scalar('password')
            ->requirePresence('password', 'create', StrongPassword::REQUIRED)
            ->notEmptyString('password', StrongPassword::REQUIRED);

        $passwordMessages = [
            StrongPassword::LENGTH,
            StrongPassword::UPPERCASE,
            StrongPassword::LOWERCASE,
            StrongPassword::NUMBER,
            StrongPassword::SPECIAL,
        ];
        foreach ($passwordMessages as $index => $message) {
            $validator->add('password', 'strength' . $index, [
                'rule' => static fn(mixed $value): bool => !in_array(
                    $message,
                    StrongPassword::errors($value),
                    true,
                ),
                'message' => $message,
            ]);
        }

        $validator
            ->scalar('confirm_password')
            ->requirePresence('confirm_password', 'create')
            ->notEmptyString('confirm_password')
            ->sameAs('confirm_password', 'password', 'Passwords do not match.');

        return $validator;
    }

    /**
     * Enforce unique customer email addresses.
     *
     * @param \Cake\ORM\RulesChecker $rules Rules checker.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['cust_email']), [
            'errorField' => 'cust_email',
            'message' => 'This email address is already registered.',
        ]);

        // Everyone signs in on one page, and the address decides which table is
        // checked. An address that is also an office account's would be
        // ambiguous, so it cannot be registered here. Same message as above, so
        // the form does not say which kind of account holds the address.
        $rules->add(
            fn(EntityInterface $entity): bool => !$entity->isDirty('cust_email')
                || !FactoryLocator::get('Table')->get('Admins')->exists(['admin_email' => $entity->get('cust_email')]),
            'officeEmail',
            [
                'errorField' => 'cust_email',
                'message' => 'This email address is already registered.',
            ],
        );

        return $rules;
    }
}
