<?php
/** @var \App\View\AppView $this */
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'My jobs');
$tz = ContactSubmissionsTable::businessTimezone();
?>
<?= $this->Html->css('dashboard') ?>
<?= $this->element('admin_sidebar') ?>
<div class="dash portal-content">
    <div class="dash__header"><h1 class="dash__title"><?= __('My jobs') ?></h1></div>
    <?= $this->Flash->render() ?>
    <div class="dash__card">
        <div class="dash__table-wrap">
        <table class="dash__table">
            <thead>
                <tr>
                    <th><?= __('Visit') ?></th><th><?= __('Address') ?></th>
                    <th><?= __('Status') ?></th><th><?= __('Action') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($assignments as $assignment) : ?>
                <tr>
                    <td><?= h($assignment->scheduled_start->setTimezone($tz)->format('d M Y, g:ia')) ?></td>
                    <td><?= h($assignment->job_request->addressLine()) ?></td>
                    <td>
                        <span class="dash__badge dash__badge--<?= h($assignment->job_request->status) ?>">
                            <?= h($assignment->job_request->plumberStatusLabel()) ?>
                        </span>
                    </td>
                    <td><?= $this->Html->link(__('Open'), [
                        'action' => 'job', $assignment->job_request_id,
                    ], ['class' => 'dash__btn dash__btn--small']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
