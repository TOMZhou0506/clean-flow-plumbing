<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * ContactSubmission Entity
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $subject
 * @property string $message
 * @property string $status
 * @property \Cake\I18n\DateTime|null $read_at
 * @property int|null $read_by
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Cake\I18n\DateTime $submitted_at
 * @property string $job_stage
 * @property-read mixed $preview
 */
class ContactSubmission extends Entity
{
    public const STATUS_NEW = 'new';
    public const STATUS_READ = 'read';
    public const STATUS_REPLIED = 'replied';
    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_READ,
        self::STATUS_REPLIED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * What the office sees for each status.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_NEW => 'New',
        self::STATUS_READ => 'Read',
        self::STATUS_REPLIED => 'Replied',
        self::STATUS_ARCHIVED => 'Archived',
    ];

    /**
     * The status as the office reads it: New, Read, Replied or Archived.
     *
     * @return string
     */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string)$this->status);
    }

    /**
     * A new job, not yet approved.
     *
     * @var string
     */
    public const STAGE_ENQUIRY = 'enquiry';

    /**
     * Compliance confirmed and the job may go ahead.
     *
     * @var string
     */
    public const STAGE_APPROVED = 'approved';

    /**
     * Booked in with a plumber.
     *
     * @var string
     */
    public const STAGE_SCHEDULED = 'scheduled';

    /**
     * Work finished.
     *
     * @var string
     */
    public const STAGE_COMPLETED = 'completed';

    /**
     * The job workflow, in order.
     *
     * This is separate from `status` on purpose. `status` is triage, meaning
     * "has an admin dealt with this message". This is workflow, meaning "how
     * far has the work got". An enquiry can be read and unapproved, or
     * archived and already scheduled, and one field could not express both.
     *
     * The order here is the order of the workflow. nextStage() reads the
     * sequence from this array, so adding a stage in the middle is one edit
     * rather than a search for hardcoded transitions.
     *
     * @var list<string>
     */
    public const STAGES = [
        self::STAGE_ENQUIRY,
        self::STAGE_APPROVED,
        self::STAGE_SCHEDULED,
        self::STAGE_COMPLETED,
    ];

    /**
     * Human readable stage names.
     *
     * @var array<string, string>
     */
    public const STAGE_LABELS = [
        self::STAGE_ENQUIRY => 'Enquiry',
        self::STAGE_APPROVED => 'Approved',
        self::STAGE_SCHEDULED => 'Scheduled',
        self::STAGE_COMPLETED => 'Completed',
    ];

    /**
     * Transitions that compliance gates.
     *
     * Only the first one. Once a job is approved, compliance has already been
     * confirmed, and re-checking it at every later step would mean a job
     * halfway through the work could be frozen by an unrelated requirement
     * being unticked. Gating the entry to the workflow is the decision point.
     *
     * @var list<string>
     */
    public const COMPLIANCE_GATED_STAGES = [self::STAGE_APPROVED];

    /**
     * Mass-assignment guard.
     *
     * I only expose the contact form's own fields. I set id, status, read_at,
     * read_by and submitted_at to false on purpose, so a crafted request body
     * cannot patch an enquiry straight to "archived" or forge who read it. I
     * set those through explicit ->set() calls in the table class, never from
     * request data.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'name' => true,
        'email' => true,
        'phone' => true,
        'subject' => true,
        'message' => true,
        'ip_address' => true,
        'user_agent' => true,

        'id' => false,
        'status' => false,
        'read_at' => false,
        'read_by' => false,
        'submitted_at' => false,

        // Not mass assignable, and this one matters more than the rest. If a
        // request body could patch job_stage, the compliance gate would be
        // one crafted POST away from irrelevant. Stage changes go through
        // ContactSubmissionsTable::advanceStage(), which checks compliance
        // first.
        'job_stage' => false,
    ];

    /**
     * Never serialised into a JSON response.
     *
     * Nothing I built needs the submitter's IP or user agent, and shipping
     * them to the browser would put personal data on screen for no reason. I
     * keep them available server-side for abuse investigation.
     *
     * @var list<string>
     */
    protected array $_hidden = ['ip_address', 'user_agent'];

    /**
     * I truncate the message for the list view, so a page of 100 rows does
     * not ship the entire dataset to the browser.
     *
     * @see \App\Model\Entity\ContactSubmission::$preview
     */
    protected function _getPreview(): string
    {
        $message = (string)($this->message ?? '');
        $flat = preg_replace('/\s+/u', ' ', trim($message)) ?? '';

        if (mb_strlen($flat) <= 140) {
            return $flat;
        }

        return mb_substr($flat, 0, 139) . "\u{2026}";
    }

    /**
     * Whether this enquiry has not been opened yet.
     *
     * @return bool
     */
    public function isUnread(): bool
    {
        return $this->status === self::STATUS_NEW;
    }

    /**
     * The stage after this one, or null at the end of the workflow.
     *
     * Derived from the position in STAGES rather than a hardcoded map, so the
     * sequence has one definition.
     *
     * @return string|null
     */
    public function nextStage(): ?string
    {
        $current = (string)($this->job_stage ?? self::STAGE_ENQUIRY);
        $index = array_search($current, self::STAGES, true);

        // An unrecognised stage means the column holds something the app did
        // not put there. I return null rather than guessing, so the job stops
        // rather than jumping to a stage nobody chose.
        if ($index === false || $index === count(self::STAGES) - 1) {
            return null;
        }

        return self::STAGES[$index + 1];
    }

    /**
     * Whether moving into the given stage requires compliance to be met.
     *
     * @param string $stage The stage being entered.
     * @return bool
     */
    public static function stageNeedsCompliance(string $stage): bool
    {
        return in_array($stage, self::COMPLIANCE_GATED_STAGES, true);
    }

    /**
     * Human readable current stage.
     *
     * @return string
     */
    public function stageLabel(): string
    {
        $stage = (string)($this->job_stage ?? self::STAGE_ENQUIRY);

        return self::STAGE_LABELS[$stage] ?? $stage;
    }
}
