<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\ComplianceCheck;
use App\Model\Entity\JobRequest;
use App\Validation\BusinessVisitTime;
use App\Validation\MeaningfulText;
use ArrayObject;
use Cake\Database\Query\SelectQuery;
use Cake\Event\EventInterface;
use Cake\I18n\DateTime;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Validation\Validator;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Job Requests Model.
 */
class JobRequestsTable extends Table
{
    public const DESCRIPTION_EMPTY = 'Please describe the plumbing work you need.';
    public const DESCRIPTION_TOO_SHORT = 'Please add a little more detail. '
        . 'The description needs at least 5 characters.';
    public const DESCRIPTION_TOO_LONG = 'That is too long. Please keep the description to 500 characters or fewer.';
    public const DESCRIPTION_NOT_MEANINGFUL = 'Please describe the job with letters, '
        . 'not just repeated characters or a link.';

    public const AUSTRALIAN_STATES = ['ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'VIC', 'WA'];

    /**
     * Sort allowlist for the office list.
     *
     * Same shape and the same reasoning as ContactSubmissionsTable::SORTABLE.
     * I cannot bind an ORDER BY column, so anything not a key here never
     * reaches the query. The keys are what a query string may contain.
     *
     * @var array<string, string>
     */
    public const SORTABLE = [
        'created' => 'JobRequests.created',
        'status' => 'JobRequests.status',
        'suburb' => 'JobRequests.suburb',
        'preferred_datetime' => 'JobRequests.preferred_datetime',
    ];

    /**
     * Spaces at either end of the job description are not part of it.
     *
     * Without this, a description of only spaces counts as filled in and the customer is told
     * the wrong thing. With it, they get the same "please describe the work" message the browser
     * shows.
     *
     * @param \Cake\Event\EventInterface $event The event.
     * @param \ArrayObject<string, mixed> $data The request data being turned into an entity.
     * @return void
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data): void
    {
        if (isset($data['job_description']) && is_string($data['job_description'])) {
            $data['job_description'] = trim($data['job_description']);
        }
    }

    /**
     * Configure the table, timestamps and customer association.
     *
     * @param array<string, mixed> $config Table configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('job_requests');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');

        // LEFT, not INNER.
        //
        // With INNER, a job request whose customer row had been removed would
        // vanish from the office list entirely rather than showing with a
        // blank customer. Silently hiding a request for work because of an
        // unrelated account deletion is the worse of the two failures: the
        // office would never know it was there.
        $this->belongsTo('Customers', [
            'foreignKey' => 'customer_id',
            'bindingKey' => 'cust_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasOne('JobAssignments', ['foreignKey' => 'job_request_id']);
    }

    /**
     * Builds the filtered list query for the office screen.
     *
     * Deliberately mirrors ContactSubmissionsTable::buildListQuery(), because
     * the office asked for the two screens to work the same way and two
     * filters that behave differently would be worse than either one alone.
     *
     * Injection handling is the same too: every value goes through the query
     * builder's parameter binding, the status is checked against a known list,
     * LIKE metacharacters in the search term are escaped, and the sort column
     * is resolved through SORTABLE rather than taken from the request.
     *
     * @param array<string, string|null> $filters Accepts q, status, compliance,
     *        from and to. Anything else is ignored.
     * @return \Cake\Database\Query\SelectQuery
     */
    public function buildListQuery(array $filters = []): SelectQuery
    {
        $query = $this->find()->contain(['Customers']);

        $status = $filters['status'] ?? null;
        if (is_string($status) && in_array($status, JobRequest::STATUSES, true)) {
            $query->where(['JobRequests.status' => $status]);
        }

        $term = $filters['q'] ?? null;
        if (is_string($term) && trim($term) !== '') {
            // Capped, so a huge needle cannot be used to burn CPU scanning
            // every row.
            $term = mb_substr(trim($term), 0, 100);

            // Escaped, so "100%" matches that literal text rather than
            // everything starting with "100".
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $needle = '%' . $escaped . '%';

            $query->where(function ($exp) use ($needle) {
                return $exp->or([
                    'JobRequests.street_address LIKE' => $needle,
                    'JobRequests.suburb LIKE' => $needle,
                    'JobRequests.postcode LIKE' => $needle,
                    'JobRequests.job_description LIKE' => $needle,
                    'JobRequests.contact_phone LIKE' => $needle,
                    'Customers.cust_email LIKE' => $needle,
                ]);
            });
        }

        $compliance = $filters['compliance'] ?? null;
        if (is_string($compliance) && $compliance !== '') {
            $query = $this->applyComplianceFilter($query, $compliance);
        }

        // A date the office picks means that date where they are sitting, not
        // in UTC. Both ends are converted out of the business timezone before
        // being compared against the stored value.
        $from = $this->normaliseDate($filters['from'] ?? null);
        if ($from !== null) {
            $query->where(['JobRequests.created >=' => $this->localDayBoundary($from, false)]);
        }

        $to = $this->normaliseDate($filters['to'] ?? null);
        if ($to !== null) {
            $query->where(['JobRequests.created <=' => $this->localDayBoundary($to, true)]);
        }

        return $query;
    }

    /**
     * Narrows the list to one compliance state.
     *
     * Scoped to job_request rows. compliance_checks holds both kinds now, and
     * without the subject type an enquiry sharing an id with a job request
     * would be counted towards it.
     *
     * @param \Cake\Database\Query\SelectQuery $query The list query.
     * @param string $state One of the ComplianceCheck::PROGRESS_* constants.
     * @return \Cake\Database\Query\SelectQuery
     */
    private function applyComplianceFilter(SelectQuery $query, string $state): SelectQuery
    {
        $total = count(ComplianceCheck::REQUIREMENTS);
        $checks = TableRegistry::getTableLocator()->get('ComplianceChecks');

        $fullyMet = $checks->find()
            ->select(['submission_id'])
            ->where([
                'subject_type' => ComplianceCheck::SUBJECT_JOB_REQUEST,
                'is_met' => true,
            ])
            ->groupBy('submission_id')
            // A real function expression, not having(['COUNT(*) >=' => ...]),
            // which reads the key as a column name and quotes COUNT(*) as an
            // identifier so the comparison never matches.
            ->having(function ($exp, $q) use ($total) {
                return $exp->gte($q->func()->count('*'), $total, 'integer');
            });

        $anyMet = $checks->find()
            ->select(['submission_id'])
            ->where([
                'subject_type' => ComplianceCheck::SUBJECT_JOB_REQUEST,
                'is_met' => true,
            ])
            ->groupBy('submission_id');

        return match ($state) {
            ComplianceCheck::PROGRESS_MET => $query
                ->where(['JobRequests.id IN' => $fullyMet]),
            ComplianceCheck::PROGRESS_PARTIAL => $query
                ->where(['JobRequests.id IN' => $anyMet])
                ->where(['JobRequests.id NOT IN' => $fullyMet]),
            ComplianceCheck::PROGRESS_PENDING => $query
                ->where(['JobRequests.id NOT IN' => $anyMet]),
            // An unrecognised value narrows nothing rather than erroring. A
            // crafted query string should not be able to break the page.
            default => $query,
        };
    }

    /**
     * Counts for the summary cards.
     *
     * Boundaries are worked out in the business timezone and converted to UTC
     * for the query, because the column holds UTC. Midnight in UTC is 10am in
     * Melbourne, so doing it the other way round leaves the Today card wrong
     * for most of the working day.
     *
     * @return array{total:int, unread:int, submitted:int, approved:int, unable_to_complete:int, archived:int, today:int}
     */
    public function summaryCounts(): array
    {
        $now = new DateTimeImmutable('now', $this->businessTimezone());
        $todayStart = $now->setTime(0, 0, 0)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        return [
            'total' => $this->find()->count(),
            'unread' => $this->find()->where(['read_at IS' => null])->count(),
            'submitted' => $this->find()
                ->where(['status' => JobRequest::STATUS_SUBMITTED])
                ->count(),
            'approved' => $this->find()
                ->where(['status' => JobRequest::STATUS_APPROVED])
                ->count(),
            'unable_to_complete' => $this->find()
                ->where(['status' => JobRequest::STATUS_UNABLE_TO_COMPLETE])
                ->count(),
            'archived' => $this->find()
                ->where(['status' => JobRequest::STATUS_ARCHIVED])
                ->count(),
            'today' => $this->find()->where(['created >=' => $todayStart])->count(),
        ];
    }

    /**
     * Moves one request to a new status.
     *
     * @param int $id The request.
     * @param string $status One of JobRequest::STATUSES.
     * @param int|null $adminId Who did it, from the session.
     * @return \App\Model\Entity\JobRequest
     * @throws \InvalidArgumentException When the status is not recognised.
     */
    public function changeStatus(int $id, string $status, ?int $adminId): JobRequest
    {
        if (!in_array($status, JobRequest::STATUSES, true)) {
            throw new InvalidArgumentException('Unrecognised status.');
        }

        /** @var \App\Model\Entity\JobRequest $jobRequest */
        $jobRequest = $this->get($id);

        $allowed = [
            JobRequest::STATUS_SUBMITTED => [JobRequest::STATUS_REVIEWING],
            JobRequest::STATUS_REVIEWING => [JobRequest::STATUS_APPROVED, JobRequest::STATUS_DECLINED],
        ];
        if (!in_array($status, $allowed[$jobRequest->status] ?? [], true)) {
            throw new InvalidArgumentException('This status change is not allowed.');
        }

        // Approval is the "Proceed" step: it opens the job up to assignment, so
        // every compliance check has to be ticked first. It is enforced here
        // rather than only by hiding the button, so a hand-made POST to the
        // status route cannot skip the checklist.
        if (
            $status === JobRequest::STATUS_APPROVED &&
            !TableRegistry::getTableLocator()->get('ComplianceChecks')
                ->isCompliant($id, ComplianceCheck::SUBJECT_JOB_REQUEST)
        ) {
            throw new InvalidArgumentException('Tick every compliance check before proceeding.');
        }

        // set(), not patchEntity(). status, read_at and read_by are all
        // non-assignable on the entity, so a request body cannot forge them.
        $jobRequest->set('status', $status);
        $this->markRead($jobRequest, $adminId);

        $this->saveOrFail($jobRequest);

        return $jobRequest;
    }

    /**
     * Records that an admin has opened a request, the first time only.
     *
     * read_at answers "when was this first seen", so I leave it alone once
     * set. Overwriting it on every view would turn it into "when was this last
     * seen", which is a different question nobody asked.
     *
     * @param \App\Model\Entity\JobRequest $jobRequest The request.
     */
    public function markRead(JobRequest $jobRequest, ?int $adminId): void
    {
        if ($jobRequest->read_at !== null) {
            return;
        }

        $jobRequest->set('read_at', new DateTime('now', 'UTC'));
        $jobRequest->set('read_by', $adminId);
    }

    /**
     * Turns submitted form input into a star count, or null if it is not one.
     *
     * Only a whole number from 1 to 5 is accepted, as an integer or as the
     * plain digit string a form sends. Anything else comes back null: text,
     * an empty value, an array, 0, 6, negatives, decimals, spaces or padding.
     *
     * @param mixed $value The submitted value.
     * @return int|null
     */
    public function parseRating(mixed $value): ?int
    {
        if (is_int($value)) {
            $stars = $value;
        } elseif (is_string($value) && preg_match('/^[1-5]$/', $value) === 1) {
            $stars = (int)$value;
        } else {
            return null;
        }

        return $stars >= JobRequest::RATING_MIN && $stars <= JobRequest::RATING_MAX ? $stars : null;
    }

    /**
     * Records a customer's rating of a completed job, once.
     *
     * The check and the write are one UPDATE, so two clicks at the same moment
     * cannot both succeed: only a row that is this customer's, completed and
     * not yet rated matches, and the second click finds it already rated. It
     * writes the columns directly rather than through an entity, because the
     * rating columns are deliberately not assignable.
     *
     * @param int $id The job request.
     * @param int $customerId The signed-in customer, from the session.
     * @param int $stars A whole number from 1 to 5.
     * @return bool True when the rating was saved, false when nothing matched.
     * @throws \InvalidArgumentException When the rating is outside 1 to 5.
     */
    public function rateCompletedJob(int $id, int $customerId, int $stars): bool
    {
        if ($stars < JobRequest::RATING_MIN || $stars > JobRequest::RATING_MAX) {
            throw new InvalidArgumentException('A rating must be from 1 to 5.');
        }

        $hasPlumber = $this->JobAssignments->find()
            ->where(['JobAssignments.job_request_id' => $id])
            ->count() > 0;
        if (!$hasPlumber) {
            return false;
        }

        $now = new DateTime('now', 'UTC');
        $updated = $this->updateAll(
            ['customer_rating' => $stars, 'rated_at' => $now, 'modified' => $now],
            [
                'id' => $id,
                'customer_id' => $customerId,
                'status' => JobRequest::STATUS_COMPLETED,
                'customer_rating IS' => null,
            ],
        );

        return $updated === 1;
    }

    /**
     * The timezone the business runs in, for day boundaries and display.
     *
     * @return \DateTimeZone
     */
    public function businessTimezone(): DateTimeZone
    {
        return ContactSubmissionsTable::businessTimezone();
    }

    /**
     * Turns a Y-m-d the office picked into a UTC timestamp for the query.
     *
     * @param string $date A validated Y-m-d string.
     * @param bool $endOfDay True for 23:59:59, false for 00:00:00.
     * @return string
     */
    private function localDayBoundary(string $date, bool $endOfDay): string
    {
        $local = new DateTimeImmutable($date, $this->businessTimezone());
        $local = $endOfDay ? $local->setTime(23, 59, 59) : $local->setTime(0, 0, 0);

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Accepts Y-m-d only, and rejects impossible dates such as 2026-02-31.
     *
     * @param string|null $value The raw date from the query string.
     * @return string|null
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

    /**
     * Validate the details needed to assess a job request.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('street_address')
            ->maxLength('street_address', 255)
            ->requirePresence('street_address', 'create')
            ->notEmptyString('street_address', 'Please enter the service street address.');

        $validator
            ->scalar('suburb')
            ->maxLength('suburb', 100)
            ->requirePresence('suburb', 'create')
            ->notEmptyString('suburb', 'Please enter the suburb.');

        $validator
            ->scalar('state')
            ->inList('state', self::AUSTRALIAN_STATES, 'Please select an Australian state or territory.')
            ->requirePresence('state', 'create')
            ->notEmptyString('state');

        $validator
            ->scalar('postcode')
            ->add('postcode', 'format', [
                'rule' => ['custom', '/^\d{4}$/'],
                'message' => 'Please enter a four-digit Australian postcode.',
            ])
            ->requirePresence('postcode', 'create')
            ->notEmptyString('postcode');

        // One message per problem, so the customer can tell what to fix. The first rule that fails
        // stops the others ('last'), so a short entry gets the length message only.
        $validator
            ->scalar('job_description')
            ->requirePresence('job_description', 'create', self::DESCRIPTION_EMPTY)
            ->notEmptyString('job_description', self::DESCRIPTION_EMPTY)
            ->add('job_description', 'minLength', [
                'rule' => ['minLength', 5],
                'message' => self::DESCRIPTION_TOO_SHORT,
                'last' => true,
            ])
            ->add('job_description', 'maxLength', [
                'rule' => ['maxLength', 500],
                'message' => self::DESCRIPTION_TOO_LONG,
                'last' => true,
            ])
            ->add('job_description', 'meaningful', [
                'rule' => [MeaningfulText::class, 'isMeaningful'],
                'message' => self::DESCRIPTION_NOT_MEANINGFUL,
            ]);

        $validator
            ->dateTime('preferred_datetime', ['ymd'])
            ->add('preferred_datetime', 'businessHours', [
                'rule' => [BusinessVisitTime::class, 'preferredIsValid'],
                'message' => 'Choose a future weekday between 08:00 and 16:00 (Melbourne time).',
            ])
            ->requirePresence('preferred_datetime', 'create')
            ->notEmptyDateTime('preferred_datetime');

        $validator
            ->scalar('contact_phone')
            ->maxLength('contact_phone', 12)
            ->add('contact_phone', 'format', [
                'rule' => ['custom', '/^(?:04\d{2} \d{3} \d{3}|03 \d{4} \d{4})$/'],
                'message' => 'Use a 03 landline (03 9123 4567) or 04 mobile (0412 345 678).',
            ])
            ->requirePresence('contact_phone', 'create')
            ->notEmptyString('contact_phone');

        $validator
            ->scalar('access_instructions')
            ->maxLength('access_instructions', 2000)
            ->allowEmptyString('access_instructions');

        return $validator;
    }

    /**
     * Ensure every request belongs to an existing customer account.
     *
     * @param \Cake\ORM\RulesChecker $rules Rules checker.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['customer_id'], 'Customers'), [
            'errorField' => 'customer_id',
            'message' => 'The customer account could not be found.',
        ]);

        return $rules;
    }
}
