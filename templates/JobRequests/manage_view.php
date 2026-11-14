<?php
/**
 * One job request, for the office.
 *
 * The page follows the work one stage at a time, and shows only the stage the
 * job is at, under a progress bar that always stays on top:
 *
 *  - Compliance check: the job's details, then the compliance checklist. A
 *    Proceed button appears once every item is ticked.
 *  - Assign: just the form for choosing a plumber and vehicle, with who is
 *    free for the chosen time.
 *  - Assigned onwards: the assignment, and how the plumber is getting on.
 *
 * Earlier stages are not repeated underneath once the job has moved on.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\JobRequest $jobRequest
 * @var array{met:int, total:int, outstanding:int} $progress
 * @var bool $isCompliant
 * @var array<string, array{key:string, label:string, check:\App\Model\Entity\ComplianceCheck|null}> $checklist
 * @var array<int, string>|null $plumbers
 * @var array<int, string>|null $vehicleOptions
 */

use App\Model\Entity\ComplianceCheck;
use App\Model\Entity\JobRequest;
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'Job request');
$this->Html->script('compliance-checklist', ['block' => true]);

$tz = ContactSubmissionsTable::businessTimezone();
$assignment = $jobRequest->job_assignment;

// Which stage's content to show. A job that is assigned or later shows its
// assignment; an approved one shows the assignment form; anything else (still
// at the compliance check, declined or archived) shows the job's details.
$showAssignment = $assignment !== null;
$showAssignForm = !$showAssignment
    && $jobRequest->status === JobRequest::STATUS_APPROVED
    && isset($plumbers, $vehicleOptions);
$showDetails = !$showAssignment && !$showAssignForm;
$showChecklist = $showDetails && in_array($jobRequest->status, [
    JobRequest::STATUS_SUBMITTED,
    JobRequest::STATUS_REVIEWING,
], true);
// Without a checklist beside it, the details card gets the address map instead.
$showMap = $showDetails && !$showChecklist;
// Two initials for a plumber's avatar: "TomPlumbers" gives TP, "plumber_staff" PS.
$initials = static function (string $name): string {
    $parts = preg_split('/[^A-Za-z0-9]+|(?<=[a-z])(?=[A-Z])/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [$name];

    return strtoupper(substr($parts[0], 0, 1)
        . (isset($parts[1]) ? substr($parts[1], 0, 1) : substr($parts[0], 1, 1)));
};
?>
<?= $this->Html->css($showMap || $showAssignment ? ['dashboard', 'plumber-job'] : 'dashboard') ?>

<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <div>
            <h1 class="dash__title"><?= __('Job request #{0}', $jobRequest->id) ?></h1>
            <?php if (!$showDetails) : ?>
                <?php // One line of context, since the details card is not repeated. ?>
                <p class="dash__card-sub job-context">
                    <?= h($jobRequest->addressLine()) ?>
                    <?php if ($jobRequest->customer !== null) : ?>
                        &middot; <?= h($jobRequest->customer->cust_email) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('Back to list'), ['action' => 'manage'], [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <?= $this->element('job_progress', ['job' => $jobRequest]) ?>

    <?php if ($showDetails) : ?>
        <?php // The details sit beside the checklist (or the map) so neither is a full-width strip. ?>
        <div class="dash__split">
        <?php // Stage 1a: the job itself. ?>
        <div class="dash__card">
            <div class="dash__card-head">
                <div>
                    <h2 class="dash__card-title"><?= h($jobRequest->addressLine()) ?></h2>
                    <p class="dash__card-sub">
                        <?= h($jobRequest->customer->cust_email ?? __('Account removed')) ?>
                    </p>
                </div>
                <span class="dash__badge dash__badge--<?= h($jobRequest->status) ?>">
                    <?= h($jobRequest->officeStatusLabel()) ?>
                </span>
            </div>

            <dl class="dash__meta">
                <dt><?= __('Received') ?></dt>
                <dd><?= h($jobRequest->created?->setTimezone($tz)->format('d M Y, g:ia')) ?></dd>

                <dt><?= __('Preferred time') ?></dt>
                <dd>
                    <?= h($jobRequest->preferred_datetime
                        ?->setTimezone($tz)
                        ->format('d M Y, g:ia') ?: __('No preference given')) ?>
                </dd>

                <dt><?= __('Contact phone') ?></dt>
                <dd><?= h($jobRequest->contact_phone ?: __('Not supplied')) ?></dd>

                <dt><?= __('First opened') ?></dt>
                <dd>
                    <?php if ($jobRequest->read_at) : ?>
                        <?= h($jobRequest->read_at->setTimezone($tz)->format('d M Y, g:ia')) ?>
                        <?php if ($jobRequest->read_by) : ?>
                            <?= __('by admin #{0}', h((string)$jobRequest->read_by)) ?>
                        <?php endif; ?>
                    <?php else : ?>
                        <?= __('Not opened yet') ?>
                    <?php endif; ?>
                </dd>
            </dl>

            <h3 class="dash__card-title"><?= __('Job description') ?></h3>
            <div class="dash__message">
                <?php
                // Escape first, then nl2br, exactly as on the enquiry detail
                // page. The customer typed this, so markup in it renders as
                // text rather than as HTML.
                echo nl2br(h((string)$jobRequest->job_description));
                ?>
            </div>

            <?php if ($jobRequest->access_instructions) : ?>
                <h3 class="dash__card-title"><?= __('Access instructions') ?></h3>
                <div class="dash__message">
                    <?= nl2br(h((string)$jobRequest->access_instructions)) ?>
                </div>
            <?php endif; ?>

            <div class="dash__card-actions">
                <?php if ($jobRequest->status === JobRequest::STATUS_REVIEWING) : ?>
                    <?= $this->Form->postLink(
                        __('Decline request'),
                        ['action' => 'changeStatus', $jobRequest->id],
                        [
                            'data' => ['status' => JobRequest::STATUS_DECLINED],
                            'class' => 'dash__btn dash__btn--ghost',
                            'confirm' => __('Decline this request? The customer will be told it cannot be taken on.'),
                        ],
                    ) ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($showChecklist) : ?>
        <?php // Stage 1b: compliance, ticked off one item at a time. ?>
        <div class="dash__card checklist-card">
            <div class="dash__card-head">
                <div>
                    <h2 class="dash__card-title"><?= __('Compliance checklist') ?></h2>
                    <p class="dash__card-sub">
                        <?= __('Tick each requirement once it has been confirmed.') ?>
                    </p>
                </div>
                <span class="dash__badge dash__badge--compliance-<?= $isCompliant ? 'met' : 'pending' ?>">
                    <?= __('{0} of {1} complete', $progress['met'], $progress['total']) ?>
                </span>
            </div>

            <ul class="checklist">
                <?php foreach ($checklist as $item) : ?>
                    <?php
                    $check = $item['check'];
                    $met = $check !== null && $check->is_met;
                    ?>
                    <li class="checklist__item<?= $met ? ' is-met' : '' ?>">
                        <?= $this->Form->create(null, [
                            'url' => [
                                'controller' => 'Compliance',
                                'action' => 'record',
                                $jobRequest->id,
                                ComplianceCheck::SUBJECT_JOB_REQUEST,
                            ],
                            'class' => 'checklist__form',
                        ]) ?>
                        <?= $this->Form->hidden('requirement', ['value' => $item['key']]) ?>
                        <?= $this->Form->hidden('subject_type', [
                            'value' => ComplianceCheck::SUBJECT_JOB_REQUEST,
                        ]) ?>
                        <?= $this->Form->hidden('return_to', ['value' => 'job']) ?>
                        <label class="checklist__label" for="check-<?= h($item['key']) ?>">
                            <?= $this->Form->checkbox('is_met', [
                                'id' => 'check-' . $item['key'],
                                'value' => '1',
                                'checked' => $met,
                                'class' => 'checklist__input',
                                'data-autosubmit' => true,
                            ]) ?>
                            <span class="checklist__box" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                            <span class="checklist__text"><?= h($item['label']) ?></span>
                        </label>
                        <noscript>
                            <?= $this->Form->button(__('Save'), ['class' => 'dash__btn dash__btn--small']) ?>
                        </noscript>
                        <?= $this->Form->end() ?>
                        <?php if ($met && $check->checked_at) : ?>
                            <span class="checklist__meta">
                                <?= __(
                                    'Confirmed {0}{1}',
                                    h($check->checked_at->setTimezone($tz)->format('d M Y, g:ia')),
                                    $check->checked_by ? ' ' . __('by admin #{0}', (int)$check->checked_by) : '',
                                ) ?>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($isCompliant) : ?>
                <div class="checklist__proceed">
                    <p><?= __('Every requirement is ticked. This job can move on to assignment.') ?></p>
                    <?= $this->Form->postLink(
                        __('Proceed'),
                        ['action' => 'proceed', $jobRequest->id],
                        ['class' => 'dash__btn dash__btn--proceed'],
                    ) ?>
                </div>
            <?php else : ?>
                <p class="checklist__hint">
                    <?= __('The Proceed button appears once every requirement is ticked.') ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($showMap) : ?>
        <?= $this->element('job_map', ['address' => $jobRequest->addressLine()]) ?>
    <?php endif; ?>
    <?php if ($showDetails) : ?>
        </div>
    <?php endif; ?>

    <?php if ($showAssignForm) : ?>
        <?php // Stage 2: assign a plumber and a vehicle. ?>
        <div class="dash__card">
            <div class="dash__card-head">
                <div>
                    <h2 class="dash__card-title"><?= __('Assign a plumber and vehicle') ?></h2>
                    <p class="dash__card-sub">
                        <?= __('The plumber will see this job in their portal and must approve it.') ?>
                    </p>
                </div>
            </div>
            <?= $this->element('job_assign_form', [
                'job' => $jobRequest,
                'plumbers' => $plumbers,
                'vehicleOptions' => $vehicleOptions,
            ]) ?>
        </div>
    <?php endif; ?>

    <?php if ($showAssignment) : ?>
        <?php
        // Stage 3 onwards: who, what, when, and how the plumber is getting on,
        // beside a map of where they are going.
        $plumberName = $assignment->plumber->admin_username ?? __('The plumber');
        $start = $assignment->scheduled_start->setTimezone($tz);
        $end = $assignment->scheduled_end->setTimezone($tz);
        $hours = round(($end->getTimestamp() - $start->getTimestamp()) / 3600, 1);
        $finishedLabel = $jobRequest->status === JobRequest::STATUS_UNABLE_TO_COMPLETE
            ? __('Could not be completed')
            : __('Completed');
        $steps = [
            ['label' => __('Assigned'), 'at' => $assignment->created],
            ['label' => __('Approved and started'), 'at' => $assignment->started_at],
            ['label' => $finishedLabel, 'at' => $assignment->finished_at],
        ];
        ?>
        <div class="dash__split">
        <div class="dash__card">
            <div class="dash__card-head">
                <div>
                    <h2 class="dash__card-title"><?= __('Assignment') ?></h2>
                    <p class="dash__card-sub">
                        <?= h(match ($jobRequest->status) {
                            JobRequest::STATUS_ASSIGNED =>
                                __('Waiting for {0} to approve this job in their portal.', $plumberName),
                            JobRequest::STATUS_IN_PROGRESS =>
                                __('{0} has approved this job and is working on it.', $plumberName),
                            JobRequest::STATUS_COMPLETED =>
                                __('{0} marked this job as completed.', $plumberName),
                            JobRequest::STATUS_UNABLE_TO_COMPLETE =>
                                __('{0} reported the job could not be completed.', $plumberName),
                            default => '',
                        }) ?>
                    </p>
                </div>
                <span class="dash__badge dash__badge--<?= h($jobRequest->status) ?>">
                    <?= h($jobRequest->officeStatusLabel()) ?>
                </span>
            </div>

            <div class="allocation-tiles">
                <div class="allocation-tile">
                    <span class="allocation-tile__icon" aria-hidden="true">
                        <?= h($initials((string)($assignment->plumber->admin_username ?? '?'))) ?>
                    </span>
                    <span class="allocation-tile__body">
                        <span class="allocation-tile__label"><?= __('Plumber') ?></span>
                        <span class="allocation-tile__value">
                            <?= h($assignment->plumber->admin_username ?? __('Account unavailable')) ?>
                        </span>
                    </span>
                </div>
                <div class="allocation-tile">
                    <span class="allocation-tile__icon allocation-tile__icon--square" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/>
                            <circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/></svg>
                    </span>
                    <span class="allocation-tile__body">
                        <span class="allocation-tile__label"><?= __('Vehicle') ?></span>
                        <?php if ($assignment->vehicle) : ?>
                            <span class="allocation-tile__value"><?= h($assignment->vehicle->label) ?></span>
                            <span class="allocation-tile__meta">
                                <?= h($assignment->vehicle->registration_number) ?>
                            </span>
                        <?php else : ?>
                            <span class="allocation-tile__value">
                                <?= __('Not recorded for this earlier assignment') ?>
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="allocation-tile allocation-tile--wide">
                    <span class="allocation-tile__icon allocation-tile__icon--square" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/>
                            <path d="M3 10h18M8 3v4M16 3v4"/></svg>
                    </span>
                    <span class="allocation-tile__body">
                        <span class="allocation-tile__label"><?= __('Scheduled visit') ?></span>
                        <span class="allocation-tile__value"><?= h($start->format('D j M Y')) ?></span>
                        <span class="allocation-tile__meta">
                            <?= h($start->format('g:ia') . '–' . $end->format('g:ia')) ?>
                            · <?= h(__n('{0} hour', '{0} hours', (int)ceil($hours), $hours)) ?>
                        </span>
                    </span>
                </div>
            </div>

            <ol class="allocation-timeline" aria-label="<?= h(__('Progress')) ?>">
                <?php foreach ($steps as $step) : ?>
                    <li class="allocation-timeline__step<?= $step['at'] !== null ? ' is-done' : '' ?>">
                        <span class="allocation-timeline__dot" aria-hidden="true"></span>
                        <span class="allocation-timeline__label"><?= h($step['label']) ?></span>
                        <span class="allocation-timeline__time">
                            <?= $step['at'] !== null
                                ? h($step['at']->setTimezone($tz)->format('j M, g:ia'))
                                : __('Not yet') ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ol>

            <?php if ($jobRequest->isRated()) : ?>
                <div class="allocation-rating">
                    <span class="allocation-tile__label"><?= __('Customer rating') ?></span>
                    <span class="allocation-rating__stars" aria-hidden="true">
                        <?= h($jobRequest->ratingStars()) ?>
                    </span>
                    <span class="allocation-rating__text">
                        <?= h(__('{0} out of {1}', (int)$jobRequest->customer_rating, JobRequest::RATING_MAX)) ?>
                        <?php if ($jobRequest->rated_at) : ?>
                            · <?= h(__('rated {0}', $jobRequest->rated_at->setTimezone($tz)->format('j M Y'))) ?>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($assignment->unable_reason) : ?>
                <div class="allocation-reason">
                    <strong><?= __('Unable to complete reason') ?></strong>
                    <p><?= h($assignment->unable_reason) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($jobRequest->status === JobRequest::STATUS_ASSIGNED) : ?>
                <?php // Until the plumber approves it, the office can still change their mind. ?>
                <div class="dash__card-actions allocation-actions">
                    <?= $this->Html->link(__('Change allocation'), [
                        'action' => 'assign', $jobRequest->id,
                    ], ['class' => 'dash__btn']) ?>
                    <?= $this->Form->postLink(__('Remove allocation'), [
                        'action' => 'unassign', $jobRequest->id,
                    ], [
                        'class' => 'dash__btn dash__btn--ghost',
                        'confirm' => __('Take {0} off this job? It goes back to ready to assign.', $plumberName),
                    ]) ?>
                </div>
            <?php endif; ?>
        </div>
        <?= $this->element('job_map', ['address' => $jobRequest->addressLine()]) ?>
        </div>
    <?php endif; ?>
</div>
