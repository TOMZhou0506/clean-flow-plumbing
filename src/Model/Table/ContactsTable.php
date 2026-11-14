<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class ContactsTable extends Table
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

        // Connect this Table class to the "contacts" database table.
        $this->setTable('contacts');

        // Use id as the primary key.
        $this->setPrimaryKey('id');

        // Automatically manage created and modified fields.
        $this->addBehavior('Timestamp');
    }

    /**
     * Default validation rules for the public contact form.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        // Name is required and cannot be empty.
        $validator
            ->scalar('name')
            ->maxLength('name', 128)
            ->requirePresence('name', 'create')
            ->notEmptyString('name', 'Please enter your name.');

        // Email is required and must be a valid email address.
        $validator
            ->email('email', false, 'Please enter a valid email address.')
            ->maxLength('email', 255)
            ->requirePresence('email', 'create')
            ->notEmptyString('email', 'Please enter your email address.');

        // Message is required and cannot be empty.
        $validator
            ->scalar('message')
            ->requirePresence('message', 'create')
            ->notEmptyString('message', 'Please enter a message.');

        return $validator;
    }
}
