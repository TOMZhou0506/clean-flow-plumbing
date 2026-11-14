<?php
/**
 * Compliance checklist for one enquiry or job request.
 *
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\EntityInterface $submission
 * @var string $subjectType
 * @var string $subjectLabel
 * @var array<string, array{key:string, label:string, check:\App\Model\Entity\ComplianceCheck|null}> $checklist
 * @var array{met:int, total:int, outstanding:int} $progress
 * @var bool $isCompliant
 * @var int $notesMax
 * @var array<\App\Model\Entity\AuditLog> $trail
 */

use App\Model\Entity\ComplianceCheck;
use App\Model\Entity\ContactSubmission;
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'Compliance');

$tz = ContactSubmissionsTable::businessTimezone();

// The two record types carry different fields, so the header text is resolved
// once here rather than with a conditional at every point of use. A job
// request has no subject line and no contact name; the address is the thing
// that identifies it to someone reading the screen.
$isJobRequest = $subjectType === ComplianceCheck::SUBJECT_JOB_REQUEST;

if ($isJobRequest) {
    $headline = __('Job request #{0}', $submission->id);
    $subline = trim(sprintf(
        '%s %s %s',
        (string)$submission->street_address,
        (string)$submission->suburb,
        (string)$submission->postcode,
    ));
    $backLabel = __('Back to job requests');
    $backUrl = ['controller' => 'JobRequests', 'action' => 'manage'];
    $listLabel = __('All job requests');
    $listUrl = ['controller' => 'JobRequests', 'action' => 'manage'];
} else {
    $headline = $submission->subject ?: __('No subject');
    $subline = (string)$submission->name;
    $backLabel = __('Back to enquiry');
    $backUrl = ['controller' => 'ContactSubmissions', 'action' => 'view', $submission->id];
    $listLabel = __('All enquiries');
    $listUrl = ['controller' => 'ContactSubmissions', 'action' => 'index'];
}
?>
<?= $this->Html->css('dashboard') ?>

<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">

    <div class="dash__header">
        <h1 class="dash__title"><?= __('Compliance') ?></h1>
        <div class="dash__header-actions">
            <?= $this->Html->link($backLabel, $backUrl, [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
            <?= $this->Html->link($listLabel, $listUrl, [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <div class="dash__card">
        <div class="dash__card-head">
            <div>
                <h2 class="dash__card-title"><?= h($headline) ?></h2>
                <p class="dash__card-sub">
                    <span class="dash__badge dash__badge--stage"><?= h($subjectLabel) ?></span>
                    <?= h($subline) ?>
                </p>
            </div>

            <?php
            // The verdict Hannah is here to reach. I do not rely on colour
            // alone: the badge spells the state out, so this reads correctly
            // without colour vision and in a printed copy.
            ?>
            <span class="dash__badge dash__badge--<?= $isCompliant ? 'read' : 'new' ?>">
                <?= $isCompliant ? __('Can proceed') : __('Blocked') ?>
            </span>
        </div>

        <p class="dash__card-sub">
            <?= __(
                '{0} of {1} requirements met.',
                $progress['met'],
                $progress['total'],
            ) ?>
            <?php if (!$isCompliant): ?>
                <?= __('{0} outstanding.', $progress['outstanding']) ?>
            <?php endif; ?>
        </p>

        <?php
        // The stage workflow belongs to enquiries. A job request carries its
        // own status column and no job_stage, so stageLabel() and nextStage()
        // do not exist on it and calling them here would be a fatal error.
        ?>
        <?php if (!$isJobRequest): ?>
            <p class="dash__card-sub">
                <?= __('Current stage:') ?>
                <span class="dash__badge dash__badge--stage"><?= h($submission->stageLabel()) ?></span>
            </p>

            <?php
            // The button is always shown when there is a next stage, whether
            // or not compliance is currently met. Hiding it when blocked would
            // just move the "why can't I do this" question off screen instead
            // of answering it - clicking it and reading the refusal is how an
            // admin finds out what is still outstanding.
            $next = $submission->nextStage();
            ?>
            <?php if ($next !== null): ?>
                <div class="dash__card-actions">
                    <?= $this->Form->postLink(
                        __('Proceed to {0}', ContactSubmission::STAGE_LABELS[$next] ?? $next),
                        ['controller' => 'Compliance', 'action' => 'advance', $submission->id],
                        ['class' => 'dash__btn'],
                    ) ?>
                </div>
            <?php else: ?>
                <p class="dash__card-sub"><?= __('This job has reached its final stage.') ?></p>
            <?php endif; ?>
        <?php else: ?>
            <p class="dash__card-sub">
                <?= __('Request status:') ?>
                <span class="dash__badge dash__badge--stage">
                    <?= h(ucfirst((string)$submission->status)) ?>
                </span>
            </p>
        <?php endif; ?>
    </div>

    <?php foreach ($checklist as $item): ?>
        <?php $check = $item['check']; ?>
        <div class="dash__card">
            <div class="dash__card-head">
                <div>
                    <h3 class="dash__card-title"><?= h($item['label']) ?></h3>
                    <p class="dash__card-sub">
                        <?php if ($check === null): ?>
                            <?= __('Not yet reviewed') ?>
                        <?php else: ?>
                            <?= __(
                                'Last recorded {0}',
                                h($check->checked_at?->setTimezone($tz)->format('d M Y, g:ia')),
                            ) ?>
                            <?php if ($check->checked_by): ?>
                                <?= __('by admin #{0}', h((string)$check->checked_by)) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="dash__badge dash__badge--<?= $check && $check->is_met ? 'read' : 'archived' ?>">
                    <?= $check && $check->is_met ? __('Met') : __('Not met') ?>
                </span>
            </div>

            <?php if ($check && $check->notes !== null): ?>
                <div class="dash__message">
                    <?php
                    // Escape first, then nl2br. A note containing
                    // <script>alert(1)</script> renders as that literal text.
                    // If I did it the other way round, or trusted the model to
                    // have stripped it on the way in, an admin's note would be
                    // a stored XSS vector against every colleague who later
                    // opens this page.
                    echo nl2br(h($check->notes));
                    ?>
                </div>
            <?php endif; ?>

            <?php
            // One form per requirement, so saving one leaves the others
            // untouched. A single form for the whole checklist would let two
            // admins working at once overwrite each other's decisions.
            ?>
            <?= $this->Form->create(null, [
                'url' => $isJobRequest
                    ? ['action' => 'record', $submission->id, $subjectType]
                    : ['action' => 'record', $submission->id],
                'class' => 'dash__card-actions',
            ]) ?>
                <?= $this->Form->hidden('requirement', ['value' => $item['key']]) ?>
                <?php
                // Belt and braces. The URL segment is what the controller
                // trusts, but posting the type as well means a form scraped
                // out of context still says what it is for.
                ?>
                <?= $this->Form->hidden('subject_type', ['value' => $subjectType]) ?>
                <?= $this->Form->control('notes', [
                    'type' => 'textarea',
                    'label' => __('Notes (optional)'),
                    'rows' => 2,
                    'maxlength' => $notesMax,
                    'value' => $check->notes ?? '',
                ]) ?>
                <?= $this->Form->button(
                    $check && $check->is_met ? __('Mark as not met') : __('Mark as met'),
                    [
                        'class' => 'dash__btn',
                        'name' => 'is_met',
                        'value' => $check && $check->is_met ? '0' : '1',
                    ],
                ) ?>
            <?= $this->Form->end() ?>
        </div>
    <?php endforeach; ?>

    <?php
    // US 3.2: the trail has to be checkable, not merely present. Each entry
    // carries who and when, so a decision can be traced back to a person
    // without opening the database.
    ?>
    <div class="dash__card">
        <div class="dash__card-head">
            <div>
                <h3 class="dash__card-title"><?= __('Recent activity') ?></h3>
                <p class="dash__card-sub">
                    <?= __('Who changed what on this enquiry, newest first.') ?>
                </p>
            </div>
            <?= $this->Html->link(__('Full audit trail'), [
                'controller' => 'AuditLogs',
                'action' => 'index',
            ], ['class' => 'dash__btn dash__btn--ghost dash__btn--small']) ?>
        </div>

        <?php if ($trail === []): ?>
            <p class="dash__empty"><?= __('Nothing recorded against this enquiry yet.') ?></p>
        <?php else: ?>
            <div class="dash__table-wrap">
                <table class="dash__table">
                    <thead>
                        <tr>
                            <th><?= __('When') ?></th>
                            <th><?= __('Admin') ?></th>
                            <th><?= __('Action') ?></th>
                            <th><?= __('Detail') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trail as $entry): ?>
                        <tr>
                            <td class="dash__nowrap">
                                <?= h($entry->created?->setTimezone($tz)->format('d M Y, g:ia')) ?>
                            </td>
                            <td class="dash__nowrap">
                                <?= $entry->user_id !== null
                                    ? __('Admin #{0}', h((string)$entry->user_id))
                                    : __('Not signed in') ?>
                            </td>
                            <td><?= h($entry->action) ?></td>
                            <td><span class="dash__preview"><?= h($entry->detail) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
