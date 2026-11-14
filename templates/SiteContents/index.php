<?php
/**
 * The website content editor's front page: one card per group.
 *
 * @var \App\View\AppView $this
 * @var array<string, array{label: string, intro: string, count: int, edited: int, latest: \Cake\I18n\DateTime|null}> $groups
 */
use App\Model\Table\ContactSubmissionsTable;

$this->assign('title', 'Website content');
$tz = ContactSubmissionsTable::businessTimezone();
?>
<?= $this->Html->css(['dashboard', 'site-content']) ?>
<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <div>
            <h1 class="dash__title"><?= __('Website content') ?></h1>
            <p class="dash__card-sub">
                <?= __('Change the wording on the public website. Saved changes are live straight away.') ?>
            </p>
        </div>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('View the website'), '/', [
                'class' => 'dash__btn dash__btn--ghost',
                'target' => '_blank',
                'rel' => 'noopener',
            ]) ?>
        </div>
    </div>
    <?= $this->Flash->render() ?>

    <ul class="content-groups">
        <?php foreach ($groups as $name => $group) : ?>
            <li class="dash__card content-group">
                <h2 class="dash__card-title"><?= h($group['label']) ?></h2>
                <p class="content-group__intro"><?= h($group['intro']) ?></p>
                <p class="content-group__meta">
                    <?= h(__n('{0} item', '{0} items', $group['count'], $group['count'])) ?>
                    ·
                    <?php if ($group['edited'] === 0) : ?>
                        <?= __('all original wording') ?>
                    <?php else : ?>
                        <?= h(__('{0} changed', $group['edited'])) ?>
                    <?php endif; ?>
                    <?php if ($group['latest'] !== null) : ?>
                        · <?= h(__('last edited {0}', $group['latest']->setTimezone($tz)->format('j M Y, g:ia'))) ?>
                    <?php endif; ?>
                </p>
                <?= $this->Html->link(__('Edit'), ['action' => 'edit', $name], [
                    'class' => 'dash__btn',
                    'aria-label' => __('Edit {0}', $group['label']),
                ]) ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <p class="content-note">
        <?= __('Text is shown exactly as typed. HTML and scripts are not run, so they appear as plain text.') ?>
        <?= __('Every change is recorded in the audit trail.') ?>
        <?= __('Earlier wording can be restored from each item\'s history.') ?>
    </p>
</div>
