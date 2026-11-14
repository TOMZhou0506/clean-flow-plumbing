<?php
/**
 * Edit one group of public text.
 *
 * @var \App\View\AppView $this
 * @var string $group
 * @var array{label: string, intro: string} $groupInfo
 * @var array<string, array{group: string, label: string, kind: string, max: int, default: string, help?: string}> $items
 * @var array<string, string> $values What each field shows: the saved value, or what was just submitted.
 * @var array<string, string> $errors Content key => problem.
 * @var array<string, array{at: \Cake\I18n\DateTime|null, by: int|null}> $lastChanges
 * @var array<int, string> $editors Admin id => username.
 */
use App\Controller\SiteContentsController;
use App\Model\Table\ContactSubmissionsTable;
use App\Service\Content\ContentCatalogue;

$this->assign('title', 'Edit ' . $groupInfo['label']);
$this->Html->script('form-enhancements', ['block' => true]);
$tz = ContactSubmissionsTable::businessTimezone();

// Related items share a box: "service.1.title" and "service.1.text" become
// "Service 1". Anything that does not end in a number stands on its own.
$sets = [];
foreach ($items as $key => $item) {
    $prefix = preg_match('/^(.*\.\d+)\.[a-z]+$/', $key, $match) === 1 ? $match[1] : $key;
    $sets[$prefix][$key] = $item;
}
$setTitle = static function (string $prefix): ?string {
    if (preg_match('/^service\.(\d+)$/', $prefix, $m) === 1) {
        return __('Service {0}', $m[1]);
    }
    if (preg_match('/^faq\.(\d+)$/', $prefix, $m) === 1) {
        return __('Question {0}', $m[1]);
    }
    if (preg_match('/^home\.how\.(\d+)$/', $prefix, $m) === 1) {
        return __('How it works, step {0}', $m[1]);
    }

    return null;
};
?>
<?= $this->Html->css(['dashboard', 'site-content']) ?>
<?= $this->element('admin_sidebar') ?>

<div class="dash portal-content">
    <div class="dash__header">
        <div>
            <h1 class="dash__title"><?= h($groupInfo['label']) ?></h1>
            <p class="dash__card-sub"><?= h($groupInfo['intro']) ?></p>
        </div>
        <div class="dash__header-actions">
            <?= $this->Html->link(__('All content'), ['action' => 'index'], [
                'class' => 'dash__btn dash__btn--ghost',
            ]) ?>
        </div>
    </div>

    <nav class="content-tabs" aria-label="<?= h(__('Content groups')) ?>">
        <?php foreach (ContentCatalogue::GROUPS as $name => $info) : ?>
            <?= $this->Html->link($info['label'], ['action' => 'edit', $name], [
                'class' => 'content-tabs__tab' . ($name === $group ? ' is-current' : ''),
                'aria-current' => $name === $group ? 'page' : null,
            ]) ?>
        <?php endforeach; ?>
    </nav>

    <?= $this->Flash->render() ?>

    <?= $this->Form->create(null, ['class' => 'content-form', 'novalidate' => true]) ?>
    <?php foreach ($sets as $prefix => $setItems) : ?>
        <?php $title = $setTitle($prefix); ?>
        <section class="dash__card content-set">
            <?php if ($title !== null) : ?>
                <h2 class="content-set__title"><?= h($title) ?></h2>
            <?php endif; ?>
            <?php foreach ($setItems as $key => $item) : ?>
                <?php
                $field = SiteContentsController::fieldName($key);
                $id = 'content-' . $field;
                $value = $values[$key] ?? '';
                $error = $errors[$key] ?? null;
                $change = $lastChanges[$key] ?? null;
                $isOriginal = $value === $item['default'];
                $attributes = [
                    'id' => $id,
                    'name' => 'content[' . $field . ']',
                    'value' => $value,
                    'maxlength' => $item['max'],
                    'data-char-counter' => true,
                    'class' => $error !== null ? 'form-error' : null,
                    'aria-invalid' => $error !== null ? 'true' : null,
                    'aria-describedby' => $error !== null ? $id . '-error' : null,
                ];
                ?>
                <div class="content-field<?= $error !== null ? ' has-error' : '' ?>">
                    <div class="content-field__head">
                        <label for="<?= h($id) ?>"><?= h($item['label']) ?></label>
                        <span class="content-field__meta">
                            <?php if ($change !== null && !$isOriginal) : ?>
                                <?= h(__(
                                    'Changed {0}{1}',
                                    $change['at']?->setTimezone($tz)->format('j M Y, g:ia') ?? '',
                                    $change['by'] !== null && isset($editors[$change['by']])
                                        ? ' ' . __('by {0}', $editors[$change['by']])
                                        : '',
                                )) ?>
                            <?php else : ?>
                                <span class="content-field__original"><?= __('Original wording') ?></span>
                            <?php endif; ?>
                            ·
                            <?= $this->Html->link(__('History'), ['action' => 'history', '?' => ['key' => $key]]) ?>
                        </span>
                    </div>
                    <?php if (!empty($item['help'])) : ?>
                        <p class="content-field__help"><?= h($item['help']) ?></p>
                    <?php endif; ?>
                    <?php if ($item['kind'] === ContentCatalogue::KIND_TEXT) : ?>
                        <?php unset($attributes['value']); ?>
                        <?= $this->Form->textarea('content.' . $field, $attributes + [
                            'rows' => $item['max'] > 300 ? 4 : 3,
                            'value' => $value,
                        ]) ?>
                    <?php else : ?>
                        <?= $this->Form->text('content.' . $field, $attributes + [
                            'type' => $item['kind'] === ContentCatalogue::KIND_PHONE ? 'tel' : 'text',
                        ]) ?>
                    <?php endif; ?>
                    <?php if ($error !== null) : ?>
                        <p class="error-message" id="<?= h($id) ?>-error"><?= h($error) ?></p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>

    <div class="content-savebar">
        <p><?= __('Changes go live as soon as you save. Earlier wording stays in each item\'s history.') ?></p>
        <div class="content-savebar__actions">
            <?= $this->Html->link(__('View the website'), '/', [
                'class' => 'dash__btn dash__btn--ghost',
                'target' => '_blank',
                'rel' => 'noopener',
            ]) ?>
            <?= $this->Form->button(__('Save changes'), ['class' => 'dash__btn']) ?>
        </div>
    </div>
    <?= $this->Form->end() ?>
</div>
