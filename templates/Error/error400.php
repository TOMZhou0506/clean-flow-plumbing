<?php
/**
 * @var \App\View\AppView $this
 * @var string $message
 * @var string $url
 */
use Cake\Core\Configure;

$this->setLayout('error');

if (Configure::read('debug')) :
    $this->setLayout('dev_error');

    $this->assign('title', $message);
    $this->assign('templateName', 'error400.php');

    $this->start('file');
    echo $this->element('auto_table_warning');
    $this->end();
endif;
?>
<?php
// This template handles every 4xx, but the skeleton version hardcoded "was
// not found on this server" as the explanation. A 403 therefore rendered as
// "Your account does not have access to this page" immediately followed by a
// line saying the address does not exist, which are two different claims and
// only one of them is true. I saw exactly that while testing the role check
// and briefly thought the route was broken.
//
// So I pick the wording off the actual status code.
$statusCode = $this->getResponse()->getStatusCode();
?>
<h2><?= h($message) ?></h2>
<p class="error">
    <strong><?= __d('cake', 'Error') ?>: </strong>
    <?php $target = "<strong>'" . h($url) . "'</strong>"; ?>
    <?php if ($statusCode === 403): ?>
        <?= __('You are signed in, but this account cannot open {0}.', $target) ?>
    <?php elseif ($statusCode === 401): ?>
        <?= __('You need to sign in before opening {0}.', $target) ?>
    <?php else: ?>
        <?= __d('cake', 'The requested address {0} was not found on this server.', "<strong>'{$url}'</strong>") ?>
    <?php endif; ?>
</p>
