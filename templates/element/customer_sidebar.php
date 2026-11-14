<?php
/**
 * Customer portal navigation.
 *
 * The job request pages had no navigation at all outside the public landing
 * page's top-nav dropdown, so a signed-in customer browsing "My job
 * requests" or the request form had no way back to the other one, and no way
 * to log out. This gives the customer pages a persistent sidebar, the same
 * shape as the office's admin_sidebar: a fixed rail with the logo built in
 * (see dashboard.css and portal-nav.js) so it reaches the top of the page,
 * and a toggle button to close it. The toggle is rendered here rather than
 * in every template that includes this element, so there is one copy of it
 * to keep in sync with the rail it controls.
 *
 * @var \App\View\AppView $this
 */

$this->Html->css('dashboard', ['block' => true]);
$this->Html->script('portal-nav', ['block' => true]);

$action = $this->getRequest()->getParam('action');

// The confirmation receipt is reached from "My job requests" rather than
// being a nav destination of its own, so it is treated as part of that
// section rather than leaving nothing highlighted.
$onMyRequests = in_array($action, ['index', 'confirmation'], true);
$onNewRequest = $action === 'add';
?>
<button
    type="button"
    class="portal-nav__toggle"
    id="portal-nav-toggle"
    aria-expanded="true"
    aria-controls="portal-nav"
    aria-label="<?= __('Toggle navigation') ?>"
>
    <span class="portal-nav__toggle-bar"></span>
    <span class="portal-nav__toggle-bar"></span>
    <span class="portal-nav__toggle-bar"></span>
</button>

<?= $this->element('session_guard', ['logoutUrl' => '/customers/logout']) ?>
<aside class="portal-nav" id="portal-nav">
    <div class="portal-nav__brand">
        <a href="<?= $this->Url->build('/') ?>">
            <img
                src="<?= $this->Url->image('clean-flow-logo.png') ?>"
                alt="Clean Flow Plumbing"
                class="portal-nav__logo"
            >
        </a>
    </div>

    <nav class="dash__nav" aria-label="<?= __('Customer portal sections') ?>">
        <p class="dash__nav-heading"><?= __('Job requests') ?></p>
        <ul class="dash__nav-list">
            <?php
            // The attribute is added only when it applies, matching
            // admin_sidebar: passing false would render aria-current="false",
            // which means something different to a screen reader.
            $items = [
                ['label' => __('My job requests'), 'action' => 'index', 'current' => $onMyRequests],
                ['label' => __('Request service'), 'action' => 'add', 'current' => $onNewRequest],
            ];
            foreach ($items as $item):
                $linkOptions = [
                    'class' => 'dash__nav-link' . ($item['current'] ? ' is-current' : ''),
                ];
                if ($item['current']) {
                    $linkOptions['aria-current'] = 'page';
                }
            ?>
            <li class="dash__nav-item">
                <?= $this->Html->link($item['label'], [
                    'controller' => 'JobRequests',
                    'action' => $item['action'],
                ], $linkOptions) ?>
            </li>
            <?php endforeach; ?>
        </ul>

        <ul class="dash__nav-list dash__nav-list--footer">
            <li class="dash__nav-item">
                <?= $this->Html->link(__('Back to home'), '/', ['class' => 'dash__nav-link']) ?>
            </li>
            <li class="dash__nav-item">
                <?= $this->Form->postLink(__('Log out'), [
                    'controller' => 'Customers',
                    'action' => 'logout',
                ], ['class' => 'dash__nav-link']) ?>
            </li>
        </ul>
    </nav>
</aside>
