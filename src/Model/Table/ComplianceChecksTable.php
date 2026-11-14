<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\ComplianceCheck;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use InvalidArgumentException;

/**
 * ComplianceChecks Model
 *
 * How I handled injection here, for the security review. Same approach as
 * ContactSubmissionsTable, because the reasoning has not changed:
 *
 *   - Every value goes through the ORM query builder, which binds parameters.
 *     I concatenate nothing into SQL.
 *   - `requirement` is checked against ComplianceCheck::REQUIREMENTS before it
 *     reaches the database, so a crafted value never gets stored.
 *   - `notes` is stored exactly as typed. I deliberately do NOT strip tags or
 *     escape on the way in. Escaping belongs at output, where the template
 *     knows the context. Sanitising on input corrupts legitimate text (a note
 *     reading "pressure < 500kPa" would lose its meaning) and gives a false
 *     sense of safety, because anything that later writes the column by
 *     another path bypasses it. See templates/Compliance/view.php.
 *
 * @method \App\Model\Entity\ComplianceCheck newEmptyEntity()
 * @method \App\Model\Entity\ComplianceCheck get(mixed $primaryKey, array $options = [])
 * @method \App\Model\Entity\ComplianceCheck|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @extends \Cake\ORM\Table<array{}, \App\Model\Entity\ComplianceCheck>
 * @property \App\Model\Table\ContactSubmissionsTable&\Cake\ORM\Association\BelongsTo $ContactSubmissions
 * @method \App\Model\Entity\ComplianceCheck[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\ComplianceCheck>|false saveMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\ComplianceCheck[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\ComplianceCheck> saveManyOrFail(iterable $entities, array $options = [])
 * @method \App\Model\Entity\ComplianceCheck[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\ComplianceCheck>|false deleteMany(iterable $entities, array $options = [])
 * @method \App\Model\Entity\ComplianceCheck[]|\Cake\Datasource\ResultSetInterface<int, \App\Model\Entity\ComplianceCheck> deleteManyOrFail(iterable $entities, array $options = [])
 */
class ComplianceChecksTable extends Table
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

        $this->setTable('compliance_checks');
        $this->setDisplayField('requirement');
        $this->setPrimaryKey('id');

        // LEFT, not INNER. A row with subject_type = 'job_request' has no
        // matching contact_submissions record, and an INNER join would drop it
        // from any query that contained this association without saying so.
        $this->belongsTo('ContactSubmissions', [
            'foreignKey' => 'submission_id',
            'joinType' => 'LEFT',
            'conditions' => ['ComplianceChecks.subject_type' => 'enquiry'],
        ]);

        // checked_at is set explicitly in record() rather than by the
        // Timestamp behaviour, because I want it to move every time a
        // decision changes, not only on create.
    }

    /**
     * Validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('requirement')
            ->inList('requirement', array_keys(ComplianceCheck::REQUIREMENTS), 'Unrecognised requirement.')
            ->notEmptyString('requirement');

        $validator
            ->scalar('subject_type')
            ->inList(
                'subject_type',
                ComplianceCheck::SUBJECT_TYPES,
                'Unrecognised compliance subject type.',
            )
            ->notEmptyString('subject_type');

        $validator
            ->boolean('is_met')
            ->notEmptyString('is_met');

        $validator
            ->scalar('notes')
            ->maxLength(
                'notes',
                ComplianceCheck::NOTES_MAX,
                'Please keep the note under 2000 characters.',
            )
            ->allowEmptyString('notes');

        return $validator;
    }

    /**
     * Records one admin's decision about one requirement.
     *
     * Each requirement saves on its own, which is what the story asks for.
     * I use a single row per (submission, requirement) pair and update it in
     * place, so re-ticking a box corrects the record rather than appending a
     * second contradictory one.
     *
     * @param int $submissionId The enquiry.
     * @param string $requirement One of ComplianceCheck::REQUIREMENTS.
     * @param bool $isMet Whether the requirement is satisfied.
     * @param string|null $notes Optional free text from the admin.
     * @param int|null $adminId Who recorded it, from the session.
     * @param string $subjectType Whether the id is an enquiry or a job request.
     *        Defaults to enquiry so every existing caller keeps working
     *        unchanged; only the job request screens pass the other value.
     * @return \App\Model\Entity\ComplianceCheck
     * @throws \InvalidArgumentException When the requirement or subject type
     *         is not recognised.
     */
    public function record(
        int $submissionId,
        string $requirement,
        bool $isMet,
        ?string $notes,
        ?int $adminId,
        string $subjectType = ComplianceCheck::SUBJECT_ENQUIRY,
    ): ComplianceCheck {
        if (!in_array($subjectType, ComplianceCheck::SUBJECT_TYPES, true)) {
            throw new InvalidArgumentException('Unrecognised compliance subject type.');
        }

        // I check here as well as in the validator. The validator protects
        // form submissions; this protects anything calling the model
        // directly, including a future console command or a teammate's code.
        if (!array_key_exists($requirement, ComplianceCheck::REQUIREMENTS)) {
            throw new InvalidArgumentException('Unrecognised requirement.');
        }

        $check = $this->find()
            ->where([
                'subject_type' => $subjectType,
                'submission_id' => $submissionId,
                'requirement' => $requirement,
            ])
            ->first();

        if ($check === null) {
            $check = $this->newEmptyEntity();
            $check->set('subject_type', $subjectType);
            $check->set('submission_id', $submissionId);
            $check->set('requirement', $requirement);
        }

        // set() rather than patchEntity(), because nothing on this entity is
        // mass assignable. checked_by comes from the session, never from the
        // request body.
        $check->set('is_met', $isMet);
        $check->set('notes', $notes === null || trim($notes) === '' ? null : trim($notes));
        $check->set('checked_by', $adminId);
        // UTC, matching every other timestamp in the app. The compliance page
        // converts to App.businessTimezone on display.
        $check->set('checked_at', new DateTime('now', 'UTC'));

        $this->saveOrFail($check);

        return $check;
    }

    /**
     * The full checklist for one enquiry, including requirements nobody has
     * looked at yet.
     *
     * The template needs every requirement in a fixed order whether or not a
     * row exists, so I build that here rather than making the view reconcile
     * two lists.
     *
     * @param int $submissionId The enquiry or job request id.
     * @param string $subjectType Which of the two it is.
     * @return array<string, array{key:string, label:string, check:\App\Model\Entity\ComplianceCheck|null}>
     */
    public function checklistFor(
        int $submissionId,
        string $subjectType = ComplianceCheck::SUBJECT_ENQUIRY,
    ): array {
        $existing = $this->find()
            ->where(['subject_type' => $subjectType, 'submission_id' => $submissionId])
            ->all()
            ->indexBy('requirement')
            ->toArray();

        $checklist = [];
        foreach (ComplianceCheck::REQUIREMENTS as $key => $label) {
            $checklist[$key] = [
                'key' => $key,
                'label' => $label,
                'check' => $existing[$key] ?? null,
            ];
        }

        return $checklist;
    }

    /**
     * Whether every requirement has been recorded as met.
     *
     * This is the answer to "can the job proceed". I treat a requirement
     * nobody has looked at as not met, deliberately: the safe reading of
     * "unknown" is "not cleared". Saying a job can proceed because nobody
     * checked would be the worst possible failure mode for a compliance
     * feature.
     *
     * @param int $submissionId The enquiry or job request id.
     * @param string $subjectType Which of the two it is.
     * @return bool
     */
    public function isCompliant(
        int $submissionId,
        string $subjectType = ComplianceCheck::SUBJECT_ENQUIRY,
    ): bool {
        $met = $this->find()
            ->where([
                'subject_type' => $subjectType,
                'submission_id' => $submissionId,
                'is_met' => true,
            ])
            ->count();

        return $met === count(ComplianceCheck::REQUIREMENTS);
    }

    /**
     * Counts for the summary line on the compliance page.
     *
     * @param int $submissionId The enquiry or job request id.
     * @param string $subjectType Which of the two it is.
     * @return array{met:int, total:int, outstanding:int}
     */
    public function progressFor(
        int $submissionId,
        string $subjectType = ComplianceCheck::SUBJECT_ENQUIRY,
    ): array {
        $total = count(ComplianceCheck::REQUIREMENTS);
        $met = $this->find()
            ->where([
                'subject_type' => $subjectType,
                'submission_id' => $submissionId,
                'is_met' => true,
            ])
            ->count();

        return [
            'met' => $met,
            'total' => $total,
            'outstanding' => $total - $met,
        ];
    }

    /**
     * The same summary as progressFor(), for many enquiries at once.
     *
     * The enquiry list shows one badge per row. Calling progressFor() in a
     * loop there would be one query per row; this does it in one, then fills
     * in zero for any id that has no rows yet, which is the ordinary state
     * for a fresh enquiry nobody has checked.
     *
     * @param list<int> $submissionIds The enquiries or job requests.
     * @param string $subjectType Which of the two they are.
     * @return array<int, array{met:int, total:int, outstanding:int}>
     */
    public function progressForMany(
        array $submissionIds,
        string $subjectType = ComplianceCheck::SUBJECT_ENQUIRY,
    ): array {
        $total = count(ComplianceCheck::REQUIREMENTS);

        $result = [];
        foreach ($submissionIds as $id) {
            $result[$id] = ['met' => 0, 'total' => $total, 'outstanding' => $total];
        }

        if ($submissionIds === []) {
            return $result;
        }

        $counts = $this->find()
            ->select([
                'submission_id' => 'submission_id',
                'met_count' => $this->find()->func()->count('*'),
            ])
            ->where([
                // Without the type, enquiry 7 and job request 7 would have
                // their counts added together and both rows would show the
                // wrong number.
                'subject_type' => $subjectType,
                'submission_id IN' => $submissionIds,
                'is_met' => true,
            ])
            ->groupBy('submission_id')
            ->all();

        foreach ($counts as $row) {
            $id = (int)$row['submission_id'];
            if (isset($result[$id])) {
                $met = (int)$row['met_count'];
                $result[$id] = ['met' => $met, 'total' => $total, 'outstanding' => $total - $met];
            }
        }

        return $result;
    }
}
