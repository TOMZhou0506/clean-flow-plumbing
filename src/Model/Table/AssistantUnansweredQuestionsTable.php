<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\Database\Driver\Mysql;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Questions the assistant could not answer.
 *
 * @method \App\Model\Entity\AssistantUnansweredQuestion newEmptyEntity()
 * @method \App\Model\Entity\AssistantUnansweredQuestion get(mixed $primaryKey, array $options = [])
 * @extends \Cake\ORM\Table<array{Timestamp: \Cake\ORM\Behavior\TimestampBehavior}, \App\Model\Entity\AssistantUnansweredQuestion>
 * @method \App\Model\Entity\AssistantUnansweredQuestion[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\AssistantUnansweredQuestion>|false saveMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\AssistantUnansweredQuestion[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\AssistantUnansweredQuestion> saveManyOrFail(iterable $entities, array $options = [])
 * @method \App\Model\Entity\AssistantUnansweredQuestion[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\AssistantUnansweredQuestion>|false deleteMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\AssistantUnansweredQuestion[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\AssistantUnansweredQuestion> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class AssistantUnansweredQuestionsTable extends Table
{
    /**
     * Matches the question column's practical limit and the assistant's own
     * input cap. Anything longer was already truncated before it got here.
     *
     * @var int
     */
    private const MAX_LENGTH = 500;

    /**
     * @param array<string, mixed> $config Table configuration.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('assistant_unanswered_questions');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
    }

    /**
     * @param \Cake\Validation\Validator $validator Validator.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('question')
            ->maxLength('question', self::MAX_LENGTH)
            ->notEmptyString('question');

        $validator
            ->scalar('audience')
            ->maxLength('audience', 20)
            ->notEmptyString('audience');

        return $validator;
    }

    /**
     * Record that we could not answer something.
     *
     * Upsert rather than insert. The same gap asked twice is one gap asked
     * twice, and that count is the useful part: it tells the team which missing
     * answer to write first.
     *
     * Deliberately swallows nothing and throws nothing of its own. Logging a
     * question is housekeeping, and housekeeping must never be the reason a
     * visitor sees an error instead of a reply, so the caller wraps this.
     *
     * @param string $question The question, already trimmed and capped.
     * @param string $audience Which kind of account was asking.
     * @param string $reason Why the assistant could not answer.
     * @return void
     */
    public function record(string $question, string $audience, string $reason): void
    {
        $question = trim($question);
        if ($question === '') {
            return;
        }

        $question = mb_substr($question, 0, self::MAX_LENGTH);
        $hash = hash('sha256', mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $question))));

        $this->getConnection()->transactional(function () use ($question, $audience, $reason, $hash): void {
            $query = $this->find()->where(['question_hash' => $hash]);

            // Same locking approach as the rate limit counters: without it two
            // simultaneous asks of a brand new question both see "no row" and
            // one of the inserts dies on the unique index.
            if ($this->getConnection()->getDriver() instanceof Mysql) {
                $query->epilog('FOR UPDATE');
            }

            /** @var \App\Model\Entity\AssistantUnansweredQuestion|null $existing */
            $existing = $query->first();

            if ($existing !== null) {
                $existing->set('times_asked', (int)$existing->times_asked + 1);
                $existing->set('modified', DateTime::now());
                // Asked again means it is still a gap, whatever somebody
                // ticked off earlier.
                $existing->set('resolved', false);
                $this->saveOrFail($existing);

                return;
            }

            $entity = $this->newEmptyEntity();

            // One field at a time rather than set() with an array. Cake 5.2
            // deprecated the array form in favour of patch(), but patch()
            // respects _accessible, and nothing on this entity is accessible by
            // design, so it would quietly set nothing at all. Scalar set() is
            // neither deprecated nor guarded, which is what is wanted here:
            // every value comes from the application, none from a request.
            $entity->set('question_hash', $hash);
            $entity->set('question', $question);
            $entity->set('audience', $audience);
            $entity->set('reason', $reason);
            $entity->set('times_asked', 1);
            $entity->set('resolved', false);

            $this->saveOrFail($entity);
        });
    }

    /**
     * The gaps worth filling first.
     *
     * @param int $limit How many to return.
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findWorstGaps(int $limit = 50): SelectQuery
    {
        return $this->find()
            ->where(['resolved' => false])
            ->orderBy(['times_asked' => 'DESC', 'modified' => 'DESC'])
            ->limit($limit);
    }
}
