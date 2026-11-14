<?php
/**
 * Signs the visitor out when they return after closing the site.
 * See webroot/js/session-guard.js for how.
 *
 * Rendered by the elements that only appear for a signed-in visitor (the
 * office and customer sidebars, and the account menu on public pages), so a
 * signed-out page never carries it.
 *
 * @var \App\View\AppView $this
 * @var array|string $logoutUrl The POST logout route for this kind of account.
 */
?>
<div hidden data-session-guard
    data-logout-url="<?= h($this->Url->build($logoutUrl)) ?>"
    data-login-url="<?= h($this->Url->build('/login')) ?>"></div>
<?php
// Every page already loads session-guard.js in its <head>; this is a second
// copy only if one ever does not. Html->script() writes it once per page.
?>
<?= $this->Html->script('session-guard') ?>
