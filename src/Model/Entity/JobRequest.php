<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * A customer's request for plumbing work.
 */
class JobRequest extends Entity
{
    public const STATUS_SUBMITTED = 'submitted';

    /**
     * The office has read it and is working on it.
     *
     * @var string
     */
    public const STATUS_REVIEWING = 'reviewing';

    /**
     * Compliance cleared and the work is going ahead.
     *
     * @var string
     */
    public const STATUS_APPROVED = 'approved';

    /**
     * Not going ahead.
     *
     * @var string
     */
    public const STATUS_DECLINED = 'declined';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_UNABLE_TO_COMPLETE = 'unable_to_complete';

    /**
     * Dealt with and out of the working list.
     *
     * @var string
     */
    public const STATUS_ARCHIVED = 'archived';

    /**
     * The statuses a job request can hold.
     *
     * Xiaoyu's version only had 'submitted', which is where every request
     * starts. The office needs somewhere to move them to, so the list is
     * filled out here rather than in a second place. The column is already a
     * string(30) with a 'submitted' default, so no migration is needed for
     * this: the extra values fit the existing column.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_REVIEWING,
        self::STATUS_APPROVED,
        self::STATUS_DECLINED,
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_UNABLE_TO_COMPLETE,
        self::STATUS_ARCHIVED,
    ];

    /** Customer-facing wording keeps internal workflow terms out of the portal. */
    public function customerStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SUBMITTED => 'Request received',
            self::STATUS_REVIEWING => 'Under review',
            self::STATUS_APPROVED => 'Approved',
            self::STATUS_DECLINED => 'Unable to accept',
            self::STATUS_ASSIGNED => 'Appointment scheduled',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_UNABLE_TO_COMPLETE => 'Unable to complete',
            self::STATUS_ARCHIVED => 'Request closed',
            default => 'Status unavailable',
        };
    }

    /** A short explanation suitable for both the list and detail pages. */
    public function customerStatusMessage(): string
    {
        return match ($this->status) {
            self::STATUS_SUBMITTED => 'We received your request and will review it.',
            self::STATUS_REVIEWING => 'Our team is reviewing your request.',
            self::STATUS_APPROVED => 'We can take on this work and will confirm a visit time.',
            self::STATUS_DECLINED => 'We cannot take on this request. Please contact us if you have questions.',
            self::STATUS_ASSIGNED => 'Your visit has been scheduled. See the confirmed time below.',
            self::STATUS_IN_PROGRESS => 'Your plumber has started this job.',
            self::STATUS_COMPLETED => 'This job has been completed.',
            self::STATUS_UNABLE_TO_COMPLETE =>
                'The visit could not be completed. We will contact you about next steps.',
            self::STATUS_ARCHIVED => 'This request is closed. Please contact us if you have questions.',
            default => 'Please contact us for an update.',
        };
    }

    /**
     * Wording for the office and the plumber, who think in workflow stages
     * rather than the softer customer-facing labels above.
     */
    public function officeStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_REVIEWING => 'Compliance check',
            self::STATUS_APPROVED => 'Ready to assign',
            self::STATUS_DECLINED => 'Declined',
            self::STATUS_ASSIGNED => 'Pending plumber approval',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_UNABLE_TO_COMPLETE => 'Unable to complete',
            self::STATUS_ARCHIVED => 'Archived',
            default => ucwords(str_replace('_', ' ', (string)$this->status)),
        };
    }

    /**
     * The same as officeStatusLabel(), but worded for the plumber. "Pending
     * plumber approval" would be talking about themselves in the third person,
     * so on their own pages it is just "Pending approval".
     */
    public function plumberStatusLabel(): string
    {
        return $this->status === self::STATUS_ASSIGNED ? 'Pending approval' : $this->officeStatusLabel();
    }

    /**
     * The stages a request moves through, for the progress bar.
     *
     * Each step is [key, label, state] where state is one of:
     * done, current, upcoming or stopped. A request that was declined, could
     * not be completed, or was archived stops at the step it reached instead
     * of pretending to carry on.
     *
     * @param bool $forCustomer Use the customer-facing wording, which keeps
     *   internal steps such as the compliance check out of the portal.
     * @return list<array{key:string, label:string, state:string}>
     */
    public function progressSteps(bool $forCustomer = false): array
    {
        $labels = $forCustomer ? [
            'received' => 'Request received',
            'compliance' => 'Under review',
            'assign' => 'Arranging your visit',
            'approval' => 'Appointment scheduled',
            'work' => 'Work in progress',
            'done' => 'Completed',
        ] : [
            'received' => 'Request received',
            'compliance' => 'Compliance check',
            'assign' => 'Assign plumber & vehicle',
            'approval' => 'Plumber approval',
            'work' => 'Work in progress',
            'done' => 'Completed',
        ];

        // A customer's request that nobody has opened yet is still at "Request
        // received". The office counts that step as done and sits on the
        // compliance check, but "Under review" before anyone has looked would
        // contradict the status shown next to the bar.
        $justReceived = $forCustomer && $this->status === self::STATUS_SUBMITTED;

        // The step each status is currently sitting on.
        $position = $justReceived ? 'received' : match ($this->status) {
            self::STATUS_SUBMITTED, self::STATUS_REVIEWING, self::STATUS_DECLINED => 'compliance',
            self::STATUS_APPROVED => 'assign',
            self::STATUS_ASSIGNED => 'approval',
            self::STATUS_IN_PROGRESS => 'work',
            self::STATUS_COMPLETED, self::STATUS_UNABLE_TO_COMPLETE => 'done',
            default => 'received',
        };
        $stopped = in_array($this->status, [
            self::STATUS_DECLINED,
            self::STATUS_UNABLE_TO_COMPLETE,
            self::STATUS_ARCHIVED,
        ], true);

        $keys = array_keys($labels);
        $currentIndex = (int)array_search($position, $keys, true);
        $finished = $this->status === self::STATUS_COMPLETED;

        $steps = [];
        foreach ($keys as $index => $key) {
            if ($finished || $index < $currentIndex) {
                $state = 'done';
            } elseif ($index === $currentIndex) {
                $state = $stopped ? 'stopped' : 'current';
            } else {
                $state = 'upcoming';
            }

            // The first step is done once the request exists, unless the
            // request was archived before it went any further.
            if ($index === 0 && $state !== 'stopped' && !$justReceived) {
                $state = 'done';
            }

            $label = $labels[$key];
            if ($state === 'stopped') {
                $label = $this->status === self::STATUS_DECLINED ? 'Declined' : 'Unable to complete';
                if ($this->status === self::STATUS_ARCHIVED) {
                    $label = 'Archived';
                }
                if ($forCustomer) {
                    $label = $this->customerStatusLabel();
                }
            }

            $steps[] = ['key' => $key, 'label' => $label, 'state' => $state];
        }

        return $steps;
    }

    /** Only a scheduled or finished visit has customer-facing assignment details. */
    public function customerHasConfirmedVisit(): bool
    {
        return $this->job_assignment !== null && in_array($this->status, [
            self::STATUS_ASSIGNED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_UNABLE_TO_COMPLETE,
        ], true);
    }

    public const RATING_MIN = 1;

    public const RATING_MAX = 5;

    /** Whether the customer has already rated this job. */
    public function isRated(): bool
    {
        return $this->customer_rating !== null;
    }

    /**
     * Whether the customer may rate this job now.
     *
     * The rating is recorded against the plumber who did the work, so a job
     * with no assignment cannot be rated. The assignment has to be loaded.
     */
    public function canBeRated(): bool
    {
        return $this->status === self::STATUS_COMPLETED
            && !$this->isRated()
            && $this->job_assignment !== null;
    }

    /** The rating drawn as stars, for example "★★★★☆". Empty when not rated. */
    public function ratingStars(): string
    {
        if (!$this->isRated()) {
            return '';
        }

        $stars = max(self::RATING_MIN, min(self::RATING_MAX, (int)$this->customer_rating));

        return str_repeat('★', $stars) . str_repeat('☆', self::RATING_MAX - $stars);
    }

    protected array $_accessible = [
        'customer_id' => false,
        'street_address' => true,
        'suburb' => true,
        'state' => true,
        'postcode' => true,
        'job_description' => true,
        'preferred_datetime' => true,
        'contact_phone' => true,
        'access_instructions' => true,
        'status' => false,
        'created' => false,
        'modified' => false,
        'customer' => false,

        // Set by the office, never by a request body. If read_by were
        // assignable, a crafted POST could record that a different admin had
        // read a request they never opened.
        'read_at' => false,
        'read_by' => false,

        // Written only by JobRequestsTable::rateCompletedJob(), which checks
        // the owner, the status and that nothing has been rated yet. If these
        // were assignable, a crafted form could rate any job or change a
        // rating after the fact.
        'customer_rating' => false,
        'rated_at' => false,
    ];

    /**
     * Whether nobody has opened this request yet.
     *
     * Mirrors ContactSubmission::isUnread(), so the two list templates can
     * highlight an untouched row the same way.
     *
     * @return bool
     */
    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * A short, flattened form of the job description for the list view.
     *
     * Same reasoning as ContactSubmission::_getPreview(): a page of rows
     * should not ship every full description to the browser.
     *
     * @return string
     */
    protected function _getPreview(): string
    {
        $text = (string)($this->job_description ?? '');
        $flat = preg_replace('/\s+/u', ' ', trim($text)) ?? '';

        if (mb_strlen($flat) <= 140) {
            return $flat;
        }

        return mb_substr($flat, 0, 139) . '...';
    }

    /** The customer-facing job reference, for example REQ-000002. */
    public function reference(): string
    {
        return sprintf('REQ-%06d', (int)$this->id);
    }

    /**
     * The address on one line, for lists and headings.
     *
     * @return string
     */
    public function addressLine(): string
    {
        return trim(sprintf(
            '%s, %s %s %s',
            (string)$this->street_address,
            (string)$this->suburb,
            (string)$this->state,
            (string)$this->postcode,
        ), ' ,');
    }
}
