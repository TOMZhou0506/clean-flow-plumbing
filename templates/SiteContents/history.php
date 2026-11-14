<?php
/**
 * Every saved version of one item, each of which can be put back.
 *
 * @var \App\View\AppView $this
 * @var string $key
 * @var array{group: string, label: string, kind: string, max: int, default: string} $item
 * @var list<\Cake\Datasource\EntityInterface> $versions Newest first.
 * @var string $current What the site shows now.
 */
use App\Model\Table\ContactSubmissionsTable;
use App\Service\Content\ContentCatalogue;

$this->assign('title', 'History: ' . $item['label']);
$tz = ContactSubmissionsTable::businessTimezone();
$groupLabel = ContentCatalogue::GROUPS[$item['group']]['label'];
// Values are plain text: escaped, with the editor's line breaks kept.
$show = static fn(string $text): string => $text === ''
    ? '<em class="content-version__empty">' . __('(empty, so hidden on the site)') . '</em>'
    : nl2br(h($text), false);
$restoreButton = function (string $version) use ($key): string {
    return $this->Form->postButton(__('Restore this'), ['action' => 'restore'], [
        'data' => ['key' => $key, 'version' => $version],
        'class' => 'dash__btn dash__btn--small dash__btn--ghost',
        'form' => ['class' => 'content-version__restore'],
    ]);
};
?>
<?= $this->Html->css(['dashboard', 'site-content']) ?>
<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <div>
            <h1 class="dash__title"><?= h($item['label']) ?></h1>
            <p class="dash__card-sub"><?= h(__('{0} · version history', $groupLabel)) ?></p>
        </div>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('Back to editing'), [
                'action' => 'edit', $item['group'],
            ], ['class' => 'dash__btn dash__btn--ghost']) ?>
        </div>
    </div>
    <?= $this->Flash->render() ?>

    <section class="dash__card">
        <h2 class="dash__card-title"><?= __('Showing on the site now') ?></h2>
        <div class="content-version__text content-version__text--current"><?= $show($current) ?></div>
    </section>

    <section class="dash__card">
        <h2 class="dash__card-title"><?= __('Earlier versions') ?></h2>
        <ol class="content-versions">
            <?php foreach ($versions as $index => $version) : ?>
                <?php $isCurrent = $index === 0 && (string)$version->value === $current; ?>
                <li class="content-version<?= $isCurrent ? ' is-current' : '' ?>">
                    <div class="content-version__head">
                        <span>
                            <strong><?= h($version->created?->setTimezone($tz)->format('j M Y, g:ia')) ?></strong>
                            <?= h(__('by {0}', $version->editor->admin_username ?? __('a removed account'))) ?>
                            <?php if ($version->restored_from !== null) : ?>
                                · <?= (int)$version->restored_from === 0
                                    ? __('restored the original wording')
                                    : __('restored an earlier version') ?>
                            <?php endif; ?>
                        </span>
                        <?php if ($isCurrent) : ?>
                            <span class="content-version__badge"><?= __('Showing now') ?></span>
                        <?php else : ?>
                            <?= $restoreButton((string)$version->id) ?>
                        <?php endif; ?>
                    </div>
                    <div class="content-version__text"><?= $show((string)$version->value) ?></div>
                </li>
            <?php endforeach; ?>
            <?php $originalShowing = $current === $item['default']; ?>
            <li class="content-version content-version--original<?= $originalShowing ? ' is-current' : '' ?>">
                <div class="content-version__head">
                    <strong><?= __('Original wording') ?></strong>
                    <?php if ($originalShowing) : ?>
                        <span class="content-version__badge"><?= __('Showing now') ?></span>
                    <?php else : ?>
                        <?= $restoreButton('default') ?>
                    <?php endif; ?>
                </div>
                <div class="content-version__text"><?= $show($item['default']) ?></div>
            </li>
        </ol>
        <?php if ($versions === []) : ?>
            <p class="dash__card-sub"><?= __('This has never been changed, so it shows the original wording.') ?></p>
        <?php endif; ?>
    </section>
</div>
