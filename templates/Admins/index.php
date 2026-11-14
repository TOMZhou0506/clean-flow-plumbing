<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\App\Model\Entity\Admin> $admins
 */
$this->assign('title', 'Admin accounts');
?>
<?= $this->Html->css('dashboard') ?>

<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">

    <div class="dash__header">
        <h1 class="dash__title"><?= __('Admin accounts') ?></h1>
        <div class="dash__header-actions">
            <?php
            // Log out used to live here too, as a plain Html->link - a GET
            // request. AdminsController::logout() only allows POST (see the
            // comment there on why), so clicking it did nothing but throw a
            // 405. The sidebar's own Log out link, included above, already
            // does this correctly with Form->postLink; removed the broken
            // duplicate rather than fixing it in two places.
            ?>
            <?= $this->Html->link(__('New admin'), ['action' => 'add'], ['class' => 'dash__btn']) ?>
        </div>
    </div>

    <?= $this->Flash->render() ?>

    <div class="dash__table-wrap">
        <table class="dash__table">
            <thead>
                <tr>
                    <th><?= $this->Paginator->sort('admin_id', __('ID')) ?></th>
                    <th><?= $this->Paginator->sort('admin_username', __('Username')) ?></th>
                    <th><?= $this->Paginator->sort('admin_email', __('Email')) ?></th>
                    <th><?= __('Status') ?></th>
                    <th class="dash__actions-col"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($admins as $admin) : ?>
                <tr>
                    <td><?= $this->Number->format($admin->admin_id) ?></td>
                    <td><?= h($admin->admin_username) ?></td>
                    <td><?= h($admin->admin_email) ?></td>
                    <td><?= $admin->active ? __('Active') : __('Deactivated') ?></td>
                    <td class="dash__actions-col">
                        <?php if ($admin->active) : ?>
                            <?= $this->Html->link(
                                __('Deactivate'),
                                ['action' => 'deactivate', $admin->admin_id],
                                ['class' => 'dash__btn dash__btn--small dash__btn--ghost'],
                            ) ?>
                        <?php else : ?>
                            <?= $this->Html->link(
                                __('Reactivate'),
                                ['action' => 'reactivate', $admin->admin_id],
                                ['class' => 'dash__btn dash__btn--small'],
                            ) ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="paginator dash__paginator">
        <ul class="pagination">
            <?= $this->Paginator->first('<< ' . __('first')) ?>
            <?= $this->Paginator->prev('< ' . __('previous')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('next') . ' >') ?>
            <?= $this->Paginator->last(__('last') . ' >>') ?>
        </ul>
        <p><?= $this->Paginator->counter(
            __('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total'),
        ) ?></p>
    </div>
</div>
