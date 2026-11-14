<?php
/**
 * One enquiry, with the full message.
 *
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\ContactSubmission $submission
 * @var list<string> $statuses
 */

use App\Model\Entity\ContactSubmission;
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'Enquiry from ' . $submission->name);

// Both timestamps on this page are stored as UTC and shown in the business
// timezone. See the note on App.businessTimezone in config/app.php.
$tz = ContactSubmissionsTable::businessTimezone();
?>
<?= $this->Html->css('dashboard') ?>

<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">

    <div class="dash__header">
        <h1 class="dash__title"><?= __('Enquiry') ?></h1>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('Back to list'), ['action' => 'index'], [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <div class="dash__card">

        <div class="dash__card-head">
            <div>
                <h2 class="dash__card-title"><?= h($submission->subject ?: __('No subject')) ?></h2>
                <p class="dash__card-sub">
                    <?= h($submission->name) ?>
                    &lt;<?= h($submission->email) ?>&gt;
                    <?php if ($submission->phone): ?>
                        &middot; <?= h($submission->phone) ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="dash__card-badges">
                <span class="dash__badge dash__badge--<?= h($submission->status) ?>">
                    <?= h($submission->statusLabel()) ?>
                </span>
            </div>
        </div>

        <dl class="dash__meta">
            <dt><?= __('Received') ?></dt>
            <dd><?= h($submission->submitted_at?->setTimezone($tz)->format('d M Y, g:ia')) ?></dd>

            <dt><?= __('First opened') ?></dt>
            <dd>
                <?php if ($submission->read_at): ?>
                    <?= h($submission->read_at->setTimezone($tz)->format('d M Y, g:ia')) ?>
                    <?php if ($submission->read_by): ?>
                        <?= __('by admin #{0}', h((string)$submission->read_by)) ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?= __('Not opened yet') ?>
                <?php endif; ?>
            </dd>
        </dl>

        <div class="dash__message">
            <?php
            // I escape first, then nl2br. That way submitted markup shows as
            // text. If I did it the other way round an enquiry could inject
            // HTML straight into this page.
            echo nl2br(h($submission->message));
            ?>
        </div>

        <div class="dash__card-actions">
            <?php foreach ($statuses as $status): ?>
                <?php if ($status !== $submission->status): ?>
                    <?= $this->Form->postLink(
                        match ($status) {
                            ContactSubmission::STATUS_NEW => __('Mark as unread'),
                            ContactSubmission::STATUS_READ => __('Mark as read'),
                            ContactSubmission::STATUS_REPLIED => __('Mark as replied'),
                            ContactSubmission::STATUS_ARCHIVED => __('Archive'),
                            default => __('Mark as {0}', ucfirst($status)),
                        },
                        ['action' => 'changeStatus', $submission->id],
                        [
                            'data' => ['status' => $status],
                            'class' => 'dash__btn' . ($status === 'archived' ? ' dash__btn--ghost' : ''),
                        ],
                    ) ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
