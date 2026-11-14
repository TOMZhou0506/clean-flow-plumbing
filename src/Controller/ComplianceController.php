<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\ComplianceCheck;
use App\Model\Entity\ContactSubmission;
use App\Model\Exception\ComplianceNotMetException;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use InvalidArgumentException;

/**
 * Compliance Controller
 *
 * US 3.1: Hannah confirms compliance requirements so she can decide whether a
 * job can proceed.
 *
 * The checklist hangs off either a contact enquiry or a customer job request,
 * so every action takes a record id and, optionally, which of the two it is.
 * Omitting the type means enquiry, which keeps every URL and test written
 * before job requests existed working unchanged.
 *
 * I kept it in its own controller rather than adding actions to
 * ContactSubmissionsController, because compliance is a separate concern with
 * its own permissions story and its own audit trail, and bolting it on would
 * have made that controller do two jobs.
 *
 * Note that I reach the table with fetchTable('ComplianceChecks') rather than
 * $this->ComplianceChecks. Controller::__get() derives the table name from the
 * CONTROLLER name, so on ComplianceController it looks for a Compliance table,
 * which does not exist. I had the property version first and every action here
 * returned a 500 behind an "Undefined property" notice. The name only matches
 * by accident when the controller and table happen to be named the same.
 */
class ComplianceController extends AppController
{
    /**
     * Compliance data is customer data plus a regulatory decision, so every
     * action needs the admin role rather than merely a session.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $this->requireAdmin();
    }

    /**
     * The compliance checklist for one enquiry or job request.
     *
     * @param string|null $submissionId The record id.
     * @param string|null $subjectType 'enquiry' or 'job_request'. Omitted means
     *        enquiry, so every URL written before job requests existed still
     *        resolves.
     * @return \Cake\Http\Response|null
     */
    public function view(?string $submissionId = null, ?string $subjectType = null): ?Response
    {
        $id = $this->parseId($submissionId);
        $type = $this->parseSubjectType($subjectType);

        if ($id === null || $type === null) {
            $this->Flash->error(__('That reference is not valid.'));

            return $this->redirect($this->indexFor($type));
        }

        $submission = $this->findSubject($id, $type);

        if ($submission === null) {
            $this->Flash->error(__('That record no longer exists.'));

            return $this->redirect($this->indexFor($type));
        }

        /** @var \App\Model\Table\ComplianceChecksTable $checks */
        $checks = $this->fetchTable('ComplianceChecks');

        // US 3.2 asks that a status change is timestamped and attributed. The
        // rows already carried both, but only a database query could show
        // them. Putting the recent history on the page the decisions are made
        // on means the question "who cleared this and when" is answerable
        // where it gets asked.
        $trail = $this->fetchTable('AuditLogs')->recentFor($this->auditEntity($type), (string)$id);

        $this->set([
            'submission' => $submission,
            'subjectType' => $type,
            'subjectLabel' => ComplianceCheck::SUBJECT_LABELS[$type],
            'checklist' => $checks->checklistFor($id, $type),
            'progress' => $checks->progressFor($id, $type),
            'isCompliant' => $checks->isCompliant($id, $type),
            'notesMax' => ComplianceCheck::NOTES_MAX,
            'trail' => $trail,
        ]);

        return null;
    }

    /**
     * Records a decision on one requirement.
     *
     * POST only. Each requirement has its own form, so ticking one saves only
     * that one and cannot silently overwrite a colleague's decision on
     * another line.
     *
     * @param string|null $submissionId The record id.
     * @param string|null $subjectType 'enquiry' or 'job_request'.
     * @return \Cake\Http\Response|null
     */
    public function record(?string $submissionId = null, ?string $subjectType = null): ?Response
    {
        $this->request->allowMethod(['post', 'put', 'patch']);

        $id = $this->parseId($submissionId);
        // The type may arrive in the URL or as a hidden form field. The URL
        // wins, because that is what the redirect back has to match.
        $type = $this->parseSubjectType(
            $subjectType ?? $this->request->getData('subject_type'),
        );
        $requirement = $this->request->getData('requirement');

        if ($id === null || $type === null || !is_string($requirement)) {
            $this->Flash->error(__('That request was not valid.'));

            return $this->redirect($this->indexFor($type));
        }

        if (!array_key_exists($requirement, ComplianceCheck::REQUIREMENTS)) {
            $this->Flash->error(__('Unrecognised compliance requirement.'));

            return $this->redirect($this->viewFor($id, $type));
        }

        // The record has to exist. Without this check someone could create
        // compliance rows against an id that was never a real record.
        if ($this->findSubject($id, $type) === null) {
            $this->Flash->error(__('That record no longer exists.'));

            return $this->redirect($this->indexFor($type));
        }

        $notes = $this->request->getData('notes');
        $notes = is_string($notes) ? $notes : null;

        if ($notes !== null && mb_strlen($notes) > ComplianceCheck::NOTES_MAX) {
            $this->Flash->error(__('Please keep the note under {0} characters.', ComplianceCheck::NOTES_MAX));

            return $this->redirect($this->viewFor($id, $type));
        }

        $isMet = (bool)$this->request->getData('is_met');

        /** @var \App\Model\Table\ComplianceChecksTable $checks */
        $checks = $this->fetchTable('ComplianceChecks');

        $checks->record(
            $id,
            $requirement,
            $isMet,
            $notes,
            $this->currentAdminId(),
            $type,
        );

        // Compliance decisions are exactly the kind of thing someone will
        // later need to account for, so each one goes in the audit trail with
        // who recorded it.
        $this->fetchTable('AuditLogs')->record(
            'compliance.record',
            $this->currentAdminId(),
            $this->auditEntity($type),
            (string)$id,
            ['requirement' => $requirement, 'met' => $isMet, 'subject' => $type],
            $this->clientIp(),
        );

        $this->Flash->success(__(
            '{0} marked as {1}.',
            ComplianceCheck::REQUIREMENTS[$requirement],
            $isMet ? __('met') : __('not met'),
        ));

        return $this->redirect($this->afterRecord($id, $type));
    }

    /**
     * Moves the job to its next stage, if compliance allows it.
     *
     * US 3.4: the compliance gate has to be enforced somewhere an admin can
     * actually trigger it, not just in the model layer. This is that action.
     * POST only, reached through Form->postLink, same reasoning as
     * ContactSubmissionsController::changeStatus() - a GET must never change
     * state.
     *
     * @param string|null $submissionId The enquiry.
     * @return \Cake\Http\Response|null
     */
    public function advance(?string $submissionId = null): ?Response
    {
        $this->request->allowMethod(['post', 'put', 'patch']);

        $id = $this->parseId($submissionId);

        if ($id === null) {
            $this->Flash->error(__('That enquiry reference is not valid.'));

            return $this->redirect(['controller' => 'ContactSubmissions', 'action' => 'index']);
        }

        /** @var \App\Model\Table\ContactSubmissionsTable $submissions */
        $submissions = $this->fetchTable('ContactSubmissions');
        /** @var \App\Model\Table\ComplianceChecksTable $checks */
        $checks = $this->fetchTable('ComplianceChecks');

        try {
            $submission = $submissions->advanceStage($id, $checks);
        } catch (RecordNotFoundException) {
            $this->Flash->error(__('That enquiry no longer exists.'));

            return $this->redirect(['controller' => 'ContactSubmissions', 'action' => 'index']);
        } catch (ComplianceNotMetException | InvalidArgumentException $e) {
            // Both are the same case from an admin's point of view: the move
            // was refused and they need to know why in plain language. The
            // exception message already says that - see advanceStage().
            $this->Flash->error($e->getMessage());

            return $this->redirect(['action' => 'view', $id]);
        }

        $this->fetchTable('AuditLogs')->record(
            'submission.stage_change',
            $this->currentAdminId(),
            'submission',
            (string)$id,
            ['to' => $submission->job_stage],
            $this->clientIp(),
        );

        $this->Flash->success(__(
            'Moved to {0}.',
            ContactSubmission::STAGE_LABELS[$submission->job_stage] ?? $submission->job_stage,
        ));

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Accepts a known subject type and nothing else.
     *
     * Null input means enquiry, so every URL written before job requests
     * existed keeps working. An unrecognised value returns null rather than
     * quietly falling back to enquiry: a crafted type should be refused, not
     * reinterpreted as something the caller did not ask for.
     *
     * @param string|null $value The raw type from the URL or the form.
     * @return string|null One of ComplianceCheck::SUBJECT_TYPES, or null.
     */
    private function parseSubjectType(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return ComplianceCheck::SUBJECT_ENQUIRY;
        }

        return in_array($value, ComplianceCheck::SUBJECT_TYPES, true) ? $value : null;
    }

    /**
     * Loads the enquiry or job request the checklist belongs to.
     *
     * @param int $id The record id.
     * @param string $type One of ComplianceCheck::SUBJECT_TYPES.
     * @return \Cake\Datasource\EntityInterface|null Null when it does not exist.
     */
    private function findSubject(int $id, string $type)
    {
        $table = $type === ComplianceCheck::SUBJECT_JOB_REQUEST
            ? 'JobRequests'
            : 'ContactSubmissions';

        try {
            return $this->fetchTable($table)->get($id);
        } catch (RecordNotFoundException) {
            return null;
        }
    }

    /**
     * Where to send someone when the record cannot be shown.
     *
     * @param string|null $type One of ComplianceCheck::SUBJECT_TYPES.
     * @return array<string, mixed>
     */
    private function indexFor(?string $type): array
    {
        if ($type === ComplianceCheck::SUBJECT_JOB_REQUEST) {
            return ['controller' => 'JobRequests', 'action' => 'manage'];
        }

        return ['controller' => 'ContactSubmissions', 'action' => 'index'];
    }

    /**
     * The checklist URL for one record.
     *
     * Enquiries keep the two segment form they have always had, so existing
     * links and tests are untouched. Only job requests carry the third.
     *
     * @param int $id The record id.
     * @param string $type One of ComplianceCheck::SUBJECT_TYPES.
     * @return array<int|string, mixed>
     */
    private function viewFor(int $id, string $type): array
    {
        if ($type === ComplianceCheck::SUBJECT_JOB_REQUEST) {
            return ['action' => 'view', $id, $type];
        }

        return ['action' => 'view', $id];
    }

    /**
     * Where to go after a decision is saved.
     *
     * A tick made from the job request page (which carries return_to=job)
     * goes back to that page, so the checklist reads as part of the job rather
     * than a separate screen. Everything else returns to the checklist page.
     *
     * @param int $id The record id.
     * @param string $type One of ComplianceCheck::SUBJECT_TYPES.
     * @return array<int|string, mixed>
     */
    private function afterRecord(int $id, string $type): array
    {
        if (
            $type === ComplianceCheck::SUBJECT_JOB_REQUEST &&
            $this->request->getData('return_to') === 'job'
        ) {
            return ['controller' => 'JobRequests', 'action' => 'manageView', $id];
        }

        return $this->viewFor($id, $type);
    }

    /**
     * The entity_type recorded in the audit trail.
     *
     * @param string $type One of ComplianceCheck::SUBJECT_TYPES.
     * @return string
     */
    private function auditEntity(string $type): string
    {
        return $type === ComplianceCheck::SUBJECT_JOB_REQUEST ? 'job_request' : 'submission';
    }

    /**
     * Accepts a positive integer id and nothing else.
     *
     * @param string|null $value The raw id from the URL.
     * @return int|null
     */
    private function parseId(?string $value): ?int
    {
        if ($value === null || !ctype_digit($value)) {
            return null;
        }

        $id = (int)$value;

        return $id > 0 ? $id : null;
    }

    // currentAdminId() now lives on AppController, for the reason set out in
    // the note there: getIdentifier() reads get('id') and admins has no id
    // column, so this copy stored a null user on every real login.

    /**
     * @return string
     */
    private function clientIp(): string
    {
        return $this->request->clientIp() ?? '0.0.0.0';
    }
}
