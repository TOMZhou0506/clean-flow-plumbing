<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * ComplianceCheck Entity
 *
 * One recorded decision about one compliance requirement on one enquiry.
 *
 * @property int $id
 * @property int $submission_id
 * @property string $requirement
 * @property bool $is_met
 * @property string|null $notes
 * @property int|null $checked_by
 * @property \Cake\I18n\DateTime $checked_at
 */
class ComplianceCheck extends Entity
{
    /**
     * The fixed checklist, as agreed for this iteration.
     *
     * Key is what goes in the database, value is what Hannah reads. Keeping
     * both here means the allowed values are visible in code review and the
     * labels cannot drift away from the stored keys.
     *
     * A requirement posted that is not a key in this array is rejected by the
     * controller rather than saved, so the column cannot fill up with
     * invented values.
     *
     * @var array<string, string>
     */
    public const REQUIREMENTS = [
        'licence' => 'Plumber licence verified',
        'permit' => 'Council or water authority permit obtained',
        'safety' => 'Site safety assessment completed',
        'insurance' => 'Public liability insurance current',
    ];

    /**
     * Maximum length of an admin note.
     *
     * Long enough for a licence number and an explanation, short enough that
     * the column cannot be used to store something large.
     *
     * @var int
     */
    public const NOTES_MAX = 2000;

    /**
     * The checklist belongs to a contact enquiry.
     *
     * @var string
     */
    public const SUBJECT_ENQUIRY = 'enquiry';

    /**
     * The checklist belongs to a customer job request.
     *
     * @var string
     */
    public const SUBJECT_JOB_REQUEST = 'job_request';

    /**
     * @var list<string>
     */
    public const SUBJECT_TYPES = [self::SUBJECT_ENQUIRY, self::SUBJECT_JOB_REQUEST];

    /**
     * What each subject type is called on screen.
     *
     * @var array<string, string>
     */
    public const SUBJECT_LABELS = [
        self::SUBJECT_ENQUIRY => 'Enquiry',
        self::SUBJECT_JOB_REQUEST => 'Job request',
    ];

    /**
     * Nothing here is mass assignable.
     *
     * Every field is set explicitly in ComplianceChecksTable::record(), which
     * takes the admin id from the session rather than the request. If
     * checked_by were assignable, one admin could record a decision under
     * another admin's name, which would make the audit trail worse than
     * useless: it would be confidently wrong.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
    ];

    /**
     * The human readable label for this requirement.
     *
     * @return string
     */
    public function label(): string
    {
        return self::REQUIREMENTS[$this->requirement] ?? $this->requirement;
    }

    /**
     * Every requirement on the enquiry has been recorded as met.
     *
     * @var string
     */
    public const PROGRESS_MET = 'met';

    /**
     * At least one requirement is met, but not all of them.
     *
     * @var string
     */
    public const PROGRESS_PARTIAL = 'partial';

    /**
     * Nothing has been recorded as met yet, including the case where nobody
     * has looked at all. There is no separate "not started" state: an
     * enquiry with zero decisions and one with every decision recorded as
     * "not met" both need the same follow-up, so they get the same colour.
     *
     * @var string
     */
    public const PROGRESS_PENDING = 'pending';

    /**
     * Classifies a met/total pair from ComplianceChecksTable::progressFor()
     * or progressForMany() into one of the three states above.
     *
     * Centralised here rather than duplicated in the enquiry list and the
     * enquiry detail templates, so the two pages cannot quietly disagree
     * about what counts as "partial".
     *
     * @param int $met Requirements recorded as met.
     * @param int $total Requirements in the checklist.
     * @return string One of the PROGRESS_* constants.
     */
    public static function progressStatus(int $met, int $total): string
    {
        if ($met >= $total && $total > 0) {
            return self::PROGRESS_MET;
        }

        return $met > 0 ? self::PROGRESS_PARTIAL : self::PROGRESS_PENDING;
    }
}
