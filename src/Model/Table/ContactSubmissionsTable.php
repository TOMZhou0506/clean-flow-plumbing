<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\ComplianceCheck;
use App\Model\Entity\ContactSubmission;
use App\Model\Exception\ComplianceNotMetException;
use App\Validation\MeaningfulText;
use Cake\Core\Configure;
use Cake\Database\Query\SelectQuery;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Validation\Validator;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * ContactSubmissions Model
 *
 * How I handled injection here, for the security review:
 *
 *   - I put every value through the ORM's query builder, which binds
 *     parameters. I concatenate nothing into SQL.
 *   - I cannot bind the ORDER BY column, so I resolve it through SORTABLE, a
 *     hardcoded allowlist. A sort field that is not a key there never reaches
 *     the query.
 *   - I escape the search term's LIKE metacharacters, so "100%" matches that
 *     literal text rather than everything beginning with "100".
 *
 * @method \App\Model\Entity\ContactSubmission newEmptyEntity()
 * @method \App\Model\Entity\ContactSubmission get(mixed $primaryKey, array $options = [])
 * @method \App\Model\Entity\ContactSubmission patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method \App\Model\Entity\ContactSubmission|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @extends \Cake\ORM\Table<array{Timestamp: \Cake\ORM\Behavior\TimestampBehavior}, \App\Model\Entity\ContactSubmission>
 * @method \App\Model\Entity\ContactSubmission[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\ContactSubmission>|false saveMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\ContactSubmission[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\ContactSubmission> saveManyOrFail(iterable $entities, array $options = [])
 * @method \App\Model\Entity\ContactSubmission[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\ContactSubmission>|false deleteMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\ContactSubmission[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\ContactSubmission> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class ContactSubmissionsTable extends Table
{
    /**
     * My allowlist for sorting. The key is what I accept from the request,
     * the value is the column. I pass the keys to the paginator as
     * `sortableFields`, which is Cake's own guard against sorting by an
     * arbitrary column name.
     *
     * @var array<string, string>
     */
    public const SORTABLE = [
        'submitted_at' => 'ContactSubmissions.submitted_at',
        'name' => 'ContactSubmissions.name',
        'email' => 'ContactSubmissions.email',
        'status' => 'ContactSubmissions.status',
    ];

    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('contact_submissions');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        // I let the behaviour manage submitted_at only, and only on create.
        // I set read_at explicitly when an admin opens an enquiry, so I must
        // not let it be touched on every save.
        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'submitted_at' => 'new',
                ],
            ],
        ]);
    }

    /**
     * Validation for inbound contact form submissions.
     *
     * I validate server-side because a bot posting straight to the endpoint
     * never runs the browser's validation.
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('name')
            ->maxLength('name', 100, 'Please keep the name under 100 characters.')
            ->notEmptyString('name', 'Please tell us your name.')
            ->requirePresence('name', 'create');

        $validator
            ->email('email', false, 'That does not look like a valid email address.')
            ->maxLength('email', 254)
            ->notEmptyString('email', 'We need an email address to reply to.')
            ->requirePresence('email', 'create');

        $validator
            ->scalar('phone')
            ->maxLength('phone', 12)
            ->add('phone', 'format', [
                'rule' => ['custom', '/^(?:04\d{2} \d{3} \d{3}|03 \d{4} \d{4})$/'],
                'message' => 'Use a 03 landline (03 9123 4567) or 04 mobile (0412 345 678).',
            ])
            ->allowEmptyString('phone');

        $validator
            ->scalar('subject')
            ->maxLength('subject', 150)
            ->allowEmptyString('subject');

        $validator
            ->scalar('message')
            ->minLength('message', 5, 'Please enter at least 5 characters.')
            ->maxLength('message', 500, 'Please keep your message to 500 characters or fewer.')
            ->add('message', 'meaningful', [
                'rule' => [MeaningfulText::class, 'isMeaningful'],
                'message' => 'Please write a message with letters, not just repeated characters or a link.',
            ])
            ->notEmptyString('message', 'Please enter a message.')
            ->requirePresence('message', 'create', 'Please enter a message.');

        return $validator;
    }

    /**
     * Builds the filtered list query for the dashboard.
     *
     * I wrote this as a plain method rather than a custom finder, because
     * finder signatures changed across CakePHP 5 minor versions and this keeps
     * working regardless of which 5.x we end up on.
     *
     * @param array{q?: string|null, status?: string|null, from?: string|null, to?: string|null} $filters
     */
    public function buildListQuery(array $filters = []): SelectQuery
    {
        $query = $this->find();

        $status = $filters['status'] ?? null;
        if ($status !== null && in_array($status, ContactSubmission::STATUSES, true)) {
            $query->where(['ContactSubmissions.status' => $status]);
        }

        $term = $filters['q'] ?? null;
        if (is_string($term) && trim($term) !== '') {
            // I cap the needle so a huge search string cannot be used to
            // burn CPU scanning every row.
            $term = mb_substr(trim($term), 0, 100);

            // I escape the LIKE metacharacters. Without this a "%" matches
            // everything and "_" matches any single character.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $needle = '%' . $escaped . '%';

            $query->where(function ($exp) use ($needle) {
                return $exp->or([
                    'ContactSubmissions.name LIKE' => $needle,
                    'ContactSubmissions.email LIKE' => $needle,
                    'ContactSubmissions.subject LIKE' => $needle,
                    'ContactSubmissions.message LIKE' => $needle,
                ]);
            });
        }

        // Someone picking "31 Aug" means the 31st where they are sitting, not
        // the 31st in UTC. I convert both ends of the range out of the
        // business timezone before comparing them against the stored value.
        $stage = $filters['stage'] ?? null;
        if (is_string($stage) && in_array($stage, ContactSubmission::STAGES, true)) {
            $query->where(['ContactSubmissions.job_stage' => $stage]);
        }

        // US 3.3: find jobs by compliance status.
        //
        // Compliance lives in a separate table with one row per requirement,
        // so "fully met" is a count, not a column. I express it as a subquery
        // counting met requirements per submission and compare against the
        // number of requirements there are. That keeps the filtering in SQL
        // rather than loading every enquiry and sifting them in PHP, which
        // would defeat the paginator: page 2 of a list filtered after the
        // query has already been paged is not page 2 of anything.
        $compliance = $filters['compliance'] ?? null;
        if (is_string($compliance) && $compliance !== '') {
            $query = $this->applyComplianceFilter($query, $compliance);
        }

        $from = $this->normaliseDate($filters['from'] ?? null);
        if ($from !== null) {
            $query->where([
                'ContactSubmissions.submitted_at >=' => $this->localDayBoundary($from, false),
            ]);
        }

        $to = $this->normaliseDate($filters['to'] ?? null);
        if ($to !== null) {
            // I include the whole end day, because that is what a person
            // picking a date in the UI means.
            $query->where([
                'ContactSubmissions.submitted_at <=' => $this->localDayBoundary($to, true),
            ]);
        }

        return $query;
    }

    /**
     * Turns a Y-m-d the user picked into a UTC timestamp for the query.
     *
     * @param string $date A validated Y-m-d string.
     * @param bool $endOfDay True for 23:59:59, false for 00:00:00.
     * @return string A UTC timestamp in Y-m-d H:i:s form.
     */
    private function localDayBoundary(string $date, bool $endOfDay): string
    {
        $local = new DateTimeImmutable($date, self::businessTimezone());
        $local = $endOfDay ? $local->setTime(23, 59, 59) : $local->setTime(0, 0, 0);

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The timezone the business runs in, for day boundaries and display.
     *
     * Configured in config/app.php as App.businessTimezone. I fall back to
     * Melbourne rather than UTC, because a silent fallback to UTC is exactly
     * the bug this method exists to prevent.
     *
     * @return \DateTimeZone
     */
    public static function businessTimezone(): DateTimeZone
    {
        return new DateTimeZone(
            (string)Configure::read('App.businessTimezone', 'Australia/Melbourne'),
        );
    }

    /**
     * Narrows a list query to enquiries in one compliance state.
     *
     * The three states match ComplianceCheck::progressStatus(), so the filter
     * and the badge on each row cannot disagree about what "partial" means.
     *
     * @param \Cake\Database\Query\SelectQuery $query The list query.
     * @param string $state One of the ComplianceCheck::PROGRESS_* constants.
     * @return \Cake\Database\Query\SelectQuery
     */
    private function applyComplianceFilter(SelectQuery $query, string $state): SelectQuery
    {
        $total = count(ComplianceCheck::REQUIREMENTS);

        // The locator, not fetchTable(). fetchTable() comes from
        // LocatorAwareTrait, which Cake\ORM\Table does not use: it is
        // available on controllers and commands, not inside a table class.
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');

        // Submissions with every requirement met.
        //
        // I build these as real subquery objects and pass them to IN / NOT IN,
        // rather than assembling correlated SQL as a string. My first version
        // concatenated a query object into the WHERE clause, which relies on
        // __toString() and drops the bound parameters on the floor. This form
        // is bound properly and runs on both MySQL and the SQLite test
        // connection.
        $fullyMet = $checks->find()
            ->select(['submission_id'])
            // Scoped to enquiries. compliance_checks now also holds job
            // request rows, and without this a job request sharing an id with
            // an enquiry would be counted towards that enquiry's total.
            ->where([
                'subject_type' => ComplianceCheck::SUBJECT_ENQUIRY,
                'is_met' => true,
            ])
            ->groupBy('submission_id')
            // A callback with a real function expression, not
            // having(['COUNT(*) >=' => $total]).
            //
            // In the array form the key is read as a column name, so COUNT(*)
            // gets quoted as an identifier and the comparison never matches.
            // The filter returned an empty set for "fully met" and every
            // cleared job fell through to "partly met" instead, which looked
            // like a counting bug rather than a quoting one.
            ->having(function ($exp, $q) use ($total) {
                return $exp->gte($q->func()->count('*'), $total, 'integer');
            });

        // Submissions with at least one requirement met.
        $anyMet = $checks->find()
            ->select(['submission_id'])
            ->where([
                'subject_type' => ComplianceCheck::SUBJECT_ENQUIRY,
                'is_met' => true,
            ])
            ->groupBy('submission_id');

        return match ($state) {
            ComplianceCheck::PROGRESS_MET => $query
                ->where(['ContactSubmissions.id IN' => $fullyMet]),

            // At least one met, but not all of them.
            ComplianceCheck::PROGRESS_PARTIAL => $query
                ->where(['ContactSubmissions.id IN' => $anyMet])
                ->where(['ContactSubmissions.id NOT IN' => $fullyMet]),

            // Nothing met, which includes enquiries nobody has reviewed. Those
            // two need the same follow-up, so they are one state here, exactly
            // as ComplianceCheck::progressStatus() classifies them.
            ComplianceCheck::PROGRESS_PENDING => $query
                ->where(['ContactSubmissions.id NOT IN' => $anyMet]),

            // An unrecognised value narrows nothing rather than erroring. A
            // crafted query string should not be able to make the page fail.
            default => $query,
        };
    }

    /**
     * Moves a job to the next stage, refusing if compliance is not met.
     *
     * US 3.3: a job which has not passed compliance cannot proceed.
     *
     * The check lives here rather than in the controller on purpose. A guard
     * in a controller protects one route; a console command, an import, or a
     * teammate's new action would all walk straight past it. Putting it on the
     * model means every path into a stage change goes through the same gate,
     * including ones nobody has written yet.
     *
     * @param int $id The enquiry.
     * @param \App\Model\Table\ComplianceChecksTable $compliance Used to ask
     *        whether the job has cleared.
     * @param bool $override An admin deliberately proceeding despite a
     *        failure. The caller is responsible for having checked the role
     *        and for logging it; this method only records that it happened.
     * @return \App\Model\Entity\ContactSubmission
     * @throws \App\Model\Exception\ComplianceNotMetException When the job is
     *         gated and compliance is not met.
     * @throws \InvalidArgumentException When there is no next stage.
     */
    public function advanceStage(
        int $id,
        ComplianceChecksTable $compliance,
        bool $override = false,
    ): ContactSubmission {
        /** @var \App\Model\Entity\ContactSubmission $submission */
        $submission = $this->get($id);

        $next = $submission->nextStage();

        if ($next === null) {
            throw new InvalidArgumentException('This job is already at the final stage.');
        }

        if (
            !$override
            && ContactSubmission::stageNeedsCompliance($next)
            && !$compliance->isCompliant($id)
        ) {
            $progress = $compliance->progressFor($id);

            throw new ComplianceNotMetException(sprintf(
                'This job cannot proceed to %s: %d of %d compliance requirements are met, '
                . '%d still outstanding.',
                ContactSubmission::STAGE_LABELS[$next] ?? $next,
                $progress['met'],
                $progress['total'],
                $progress['outstanding'],
            ));
        }

        // set(), not patchEntity(). job_stage is not mass assignable, which is
        // what stops a crafted request body skipping this method entirely.
        $submission->set('job_stage', $next);
        $this->saveOrFail($submission);

        return $submission;
    }

    /**
     * Counts for the dashboard summary cards.
     *
     * Six ORM queries rather than the one hand-written SELECT I started with.
     * That version used UTC_TIMESTAMP(), UTC_DATE() and INTERVAL 7 DAY, which
     * are MySQL-only and failed outright on the SQLite connection my tests
     * run against. I now compute the boundaries in PHP and pass them as bound
     * values, so the same code runs on both engines.
     *
     * Six indexed counts over a table this size does not worry me.
     *
     * @return array{total:int, new:int, read:int, replied:int, archived:int, last_7_days:int, today:int}
     */
    public function summaryCounts(): array
    {
        // Boundaries are worked out in the business timezone and then
        // converted to UTC for the query, because the column holds UTC.
        //
        // I had this computing midnight in UTC, which is 10am in Melbourne.
        // An enquiry that arrived at 9am did not appear in the Today count,
        // and the card sat there quietly wrong for most of the working day.
        $now = new DateTimeImmutable('now', self::businessTimezone());

        $sevenDaysAgo = $now->modify('-7 days')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $todayStart = $now->setTime(0, 0, 0)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        return [
            'total' => $this->find()->count(),
            'new' => $this->find()
                ->where(['status' => ContactSubmission::STATUS_NEW])
                ->count(),
            'read' => $this->find()
                ->where(['status' => ContactSubmission::STATUS_READ])
                ->count(),
            'replied' => $this->find()
                ->where(['status' => ContactSubmission::STATUS_REPLIED])
                ->count(),
            'archived' => $this->find()
                ->where(['status' => ContactSubmission::STATUS_ARCHIVED])
                ->count(),
            'last_7_days' => $this->find()
                ->where(['submitted_at >=' => $sevenDaysAgo])
                ->count(),
            'today' => $this->find()
                ->where(['submitted_at >=' => $todayStart])
                ->count(),
        ];
    }

    /**
     * Moves one submission to a new status.
     *
     * I use read_at to record when the enquiry was FIRST opened. Once set I
     * leave it, so moving read -> new -> read does not rewrite history.
     */
    public function changeStatus(int $id, string $status, ?int $userId): ContactSubmission
    {
        if (!in_array($status, ContactSubmission::STATUSES, true)) {
            throw new InvalidArgumentException('Unrecognised status.');
        }

        /** @var \App\Model\Entity\ContactSubmission $submission */
        $submission = $this->get($id);

        // I set these explicitly rather than through patchEntity, because I
        // deliberately made them non mass-assignable on the entity.
        $submission->set('status', $status);

        if ($status !== ContactSubmission::STATUS_NEW && $submission->read_at === null) {
            $submission->set('read_at', new DateTime('now', 'UTC'));
            $submission->set('read_by', $userId);
        }

        $this->saveOrFail($submission);

        return $submission;
    }

    /**
     * I run the bulk status change inside a transaction, so a partial failure
     * does not leave half the selection moved.
     *
     * @param list<int> $ids
     * @return int Number of records actually changed.
     */
    public function changeStatusBulk(array $ids, string $status, ?int $userId): int
    {
        if (!in_array($status, ContactSubmission::STATUSES, true)) {
            throw new InvalidArgumentException('Unrecognised status.');
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return 0;
        }

        return $this->getConnection()->transactional(function () use ($ids, $status, $userId): int {
            $changed = 0;

            $submissions = $this->find()
                ->where([
                    'ContactSubmissions.id IN' => $ids,
                    'ContactSubmissions.status !=' => $status,
                ])
                ->all();

            foreach ($submissions as $submission) {
                /** @var \App\Model\Entity\ContactSubmission $submission */
                $submission->set('status', $status);

                if ($status !== ContactSubmission::STATUS_NEW && $submission->read_at === null) {
                    $submission->set('read_at', new DateTime('now', 'UTC'));
                    $submission->set('read_by', $userId);
                }

                $this->saveOrFail($submission);
                $changed++;
            }

            return $changed;
        });
    }

    /**
     * I accept Y-m-d only, and reject impossible dates such as 2026-02-31.
     */
    private function normaliseDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }
}
