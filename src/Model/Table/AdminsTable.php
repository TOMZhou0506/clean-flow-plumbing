<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\Admin;
use App\Validation\StrongPassword;
use ArrayObject;
use Cake\Core\Configure;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\FactoryLocator;
use Cake\Event\EventInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use InvalidArgumentException;

/**
 * Admins Model
 *
 * @method \App\Model\Entity\Admin newEmptyEntity()
 * @method \App\Model\Entity\Admin newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Admin> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Admin get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Admin findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Admin patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Admin> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Admin|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Admin saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Admin[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Admin>|false saveMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\Admin[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Admin> saveManyOrFail(iterable $entities, array $options = [])
 * @method \App\Model\Entity\Admin[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Admin>|false deleteMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\Admin[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\Admin> deleteManyOrFail(iterable $entities, array $options = [])
 * @extends \Cake\ORM\Table<array{}, \App\Model\Entity\Admin>
 */
class AdminsTable extends Table
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

        $this->setTable('admins');
        $this->setDisplayField('admin_username');
        $this->setPrimaryKey('admin_id');
    }

    /**
     * Tidies the two names before they are validated.
     *
     * An email address is stored trimmed and in lower case, so one address cannot be
     * entered twice in two spellings and a sign-in does not depend on capital letters.
     * Whatever else arrives is left alone for the validator to judge.
     *
     * @param \Cake\Event\EventInterface $event The event.
     * @param \ArrayObject<string, mixed> $data The request data being turned into an entity.
     * @return void
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data): void
    {
        if (isset($data['admin_email']) && is_string($data['admin_email'])) {
            $data['admin_email'] = mb_strtolower(trim($data['admin_email']));
        }
        if (isset($data['admin_username']) && is_string($data['admin_username'])) {
            $data['admin_username'] = trim($data['admin_username']);
        }
    }

    /**
     * The domain office email addresses must be on.
     *
     * Mail can only be delivered to mailboxes on the site's own domain, so an
     * office account with an outside address could never receive a reset code.
     *
     * @return string
     */
    public static function officeDomain(): string
    {
        return mb_strtolower((string)Configure::read('Mail.officeDomain', 'u26s2248.iedev.org'));
    }

    /**
     * A disabled office account must not be found by the login identifier.
     */
    public function findActive(SelectQuery $query): SelectQuery
    {
        return $query->where([$this->aliasField('active') => true]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('admin_username')
            ->maxLength('admin_username', 255)
            ->requirePresence('admin_username', 'create')
            ->notEmptyString('admin_username');

        // The address an office account signs in with. Format and length are checked
        // here; whether it is already taken is checked in buildRules().
        $validator
            ->scalar('admin_email')
            ->requirePresence('admin_email', 'create', 'Please enter an email address.')
            ->notEmptyString('admin_email', 'Please enter an email address.')
            ->maxLength('admin_email', 254, 'An email address can be at most 254 characters.')
            ->email('admin_email', false, 'Please enter a valid email address, for example name@example.com.')
            ->add('admin_email', 'officeDomain', [
                'rule' => static fn(mixed $value): bool => is_string($value)
                    && str_ends_with($value, '@' . self::officeDomain()),
                'message' => 'Please use an email address at ' . self::officeDomain()
                    . ', for example name@' . self::officeDomain() . '.',
            ]);

        $validator
            ->scalar('admin_password')
            ->requirePresence('admin_password', 'create', StrongPassword::REQUIRED)
            ->notEmptyString('admin_password', StrongPassword::REQUIRED, 'create');

        $passwordMessages = [
            StrongPassword::LENGTH,
            StrongPassword::UPPERCASE,
            StrongPassword::LOWERCASE,
            StrongPassword::NUMBER,
            StrongPassword::SPECIAL,
        ];
        foreach ($passwordMessages as $index => $message) {
            $validator->add('admin_password', 'strength' . $index, [
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
            ->requirePresence('confirm_password', 'create', 'Please confirm the password.')
            ->notEmptyString('confirm_password', 'Please confirm the password.')
            ->sameAs('confirm_password', 'admin_password', 'Passwords do not match.');

        // Nothing outside the app should be able to invent a role name. An
        // unrecognised value would fail the === check in
        // AppController::requireAdmin() and so would deny rather than grant,
        // but I would rather it never reach the column in the first place.
        $validator
            ->scalar('role')
            ->inList('role', Admin::ROLES, 'Unrecognised role.')
            ->notEmptyString('role');

        return $validator;
    }

    /**
     * New plumber usernames are display names, not login identifiers. Apply these
     * rules only during creation so legacy accounts remain usable and editable.
     */
    public function validationPlumberCreate(Validator $validator): Validator
    {
        $validator = $this->validationDefault($validator);
        $validator->remove('admin_username', 'maxLength');

        $validator
            ->requirePresence('admin_username', 'create', 'Please enter a username.')
            ->notEmptyString('admin_username', 'Please enter a username.')
            ->minLength('admin_username', 3, 'Username must be between 3 and 32 characters.')
            ->maxLength('admin_username', 32, 'Username must be between 3 and 32 characters.')
            ->regex(
                'admin_username',
                '/\A[A-Za-z0-9_-]+\z/',
                'Username may contain only English letters, numbers, underscores, and hyphens.',
            )
            ->add('admin_username', 'reserved', [
                'rule' => static fn(mixed $value): bool => !is_string($value)
                    || !in_array(strtolower($value), ['admin', 'manager', 'root'], true),
                'message' => 'The usernames admin, manager, and root are reserved.',
            ]);

        return $validator;
    }

    /**
     * Keep duplicate usernames and email addresses on the form instead of surfacing a SQL error.
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['admin_username']), [
            'errorField' => 'admin_username',
            'message' => 'This username is already in use.',
        ]);
        $rules->add($rules->isUnique(['admin_email']), [
            'errorField' => 'admin_email',
            'message' => 'This email address is already in use.',
        ]);

        // Everyone signs in on one page, and the address decides which table is
        // checked. An address a customer has registered with would be ambiguous,
        // so it cannot be given to an office account either.
        $rules->add(
            fn(EntityInterface $entity): bool => !$entity->isDirty('admin_email')
                || !FactoryLocator::get('Table')->get('Customers')
                    ->exists(['cust_email' => $entity->get('admin_email')]),
            'customerEmail',
            [
                'errorField' => 'admin_email',
                'message' => 'This email address is already in use.',
            ],
        );

        return $rules;
    }

    /**
     * Changes an account's role.
     *
     * This exists as its own method because `role` is not mass assignable on
     * the entity. If it were, a POST to /admins/edit carrying role=admin
     * would let a staff account promote itself, and the authorisation check
     * would be worth nothing.
     *
     * @param int $adminId The account to change.
     * @param string $role One of Admin::ROLES.
     * @return \App\Model\Entity\Admin
     * @throws \InvalidArgumentException When the role is not recognised.
     */
    public function changeRole(int $adminId, string $role): Admin
    {
        if (!in_array($role, Admin::ROLES, true)) {
            throw new InvalidArgumentException('Unrecognised role.');
        }

        /** @var \App\Model\Entity\Admin $admin */
        $admin = $this->get($adminId);
        if (
            $role === Admin::ROLE_MANAGER &&
            $admin->role !== Admin::ROLE_MANAGER &&
            $this->find()->where(['role' => Admin::ROLE_MANAGER])->count() > 0
        ) {
            throw new InvalidArgumentException('A manager account already exists.');
        }
        if ($admin->role === Admin::ROLE_MANAGER && $role !== Admin::ROLE_MANAGER) {
            throw new InvalidArgumentException('The sole manager cannot be demoted.');
        }
        $admin->set('role', $role);
        $this->saveOrFail($admin);

        return $admin;
    }
}
