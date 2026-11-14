<?php
/**
 * Customer navigation that can be reused by full-page and shared layouts.
 *
 * @var \App\View\AppView $this
 * @var string|null $homeOfficeRole
 */
use App\Model\Entity\Admin;

$customerAuth = $this->getRequest()->getSession()->read('CustomerAuth');
$customerId = null;
$customerName = null;

if (is_array($customerAuth)) {
    $customerId = $customerAuth['cust_id'] ?? null;
    $customerName = $customerAuth['cust_fname'] ?? null;
} elseif ($customerAuth instanceof ArrayAccess) {
    $customerId = $customerAuth['cust_id'] ?? null;
    $customerName = $customerAuth['cust_fname'] ?? null;
}

$isCustomer = is_int($customerId) || ctype_digit((string)$customerId);
$menuLabel = is_string($customerName) && trim($customerName) !== ''
    ? trim($customerName)
    : 'My account';

$adminAuth = $this->getRequest()->getSession()->read('AdminAuth');
$adminId = null;
$adminUsername = null;

if (is_array($adminAuth)) {
    $adminId = $adminAuth['admin_id'] ?? null;
    $adminUsername = $adminAuth['admin_username'] ?? null;
} elseif ($adminAuth instanceof ArrayAccess) {
    $adminId = $adminAuth['admin_id'] ?? null;
    $adminUsername = $adminAuth['admin_username'] ?? null;
}

$isAdmin = is_int($adminId) || ctype_digit((string)$adminId);
$adminLabel = is_string($adminUsername) && trim($adminUsername) !== ''
    ? trim($adminUsername)
    : 'Admin';
$isManager = ($homeOfficeRole ?? null) === Admin::ROLE_MANAGER;
$isPlumber = ($homeOfficeRole ?? null) === Admin::ROLE_STAFF;
?>
<?php if ($isAdmin) : ?>
<details class="customer-menu">
    <summary>
        <span class="customer-menu__label">
            <?= h($adminLabel) ?>
            <svg class="customer-menu__caret" viewBox="0 0 10 6" aria-hidden="true">
                <path d="M1 1l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6"
                    stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </span>
    </summary>
    <div class="customer-menu__items">
        <?= $this->Html->link(
            $isManager ? 'Manager dashboard' : ($isPlumber ? 'Plumber dashboard' : 'Admin dashboard'),
            $isManager
                ? ['controller' => 'ManagerJobs', 'action' => 'index']
                : ($isPlumber
                    ? ['controller' => 'Plumbers', 'action' => 'dashboard']
                    : ['controller' => 'JobRequests', 'action' => 'manage']),
        ) ?>
        <?= $this->Form->postLink(
            'Log out',
            ['controller' => 'Admins', 'action' => 'logout'],
            ['class' => 'customer-menu__logout', 'confirm' => 'Are you sure you want to log out?'],
        ) ?>
    </div>
</details>
    <?= $this->element('session_guard', ['logoutUrl' => ['controller' => 'Admins', 'action' => 'logout']]) ?>

<?php elseif ($isCustomer) : ?>
    <details class="customer-menu">
        <summary>
            <span class="customer-menu__label">
                <?= h($menuLabel) ?>
                <svg class="customer-menu__caret" viewBox="0 0 10 6" aria-hidden="true">
                    <path d="M1 1l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6"
                        stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
        </summary>
        <div class="customer-menu__items">
            <?= $this->Html->link('My job requests', '/job-requests') ?>
            <?= $this->Html->link('Request service', '/job-requests/add') ?>
            <?= $this->Form->postLink(
                'Log out',
                '/customers/logout',
                ['class' => 'customer-menu__logout', 'confirm' => 'Are you sure you want to log out?'],
            ) ?>
        </div>
    </details>
    <?= $this->element('session_guard', ['logoutUrl' => '/customers/logout']) ?>
<?php else : ?>
    <?php
    // Signed out, this is two plain links rather than a dropdown.
    //
    // It used to be a menu labelled "Customer", which was wrong twice over:
    // it named the account type rather than the action, and it hid the only
    // way into the site behind a click. Removing the double login button was
    // the point of this work, so the one remaining login button should not be
    // buried.
    //
    // "Log in" goes to the shared /login page, which asks whether you are an
    // admin or a customer. /customers/login redirects here now that the
    // separate customer-only login page is gone.
    //
    // There is no "Create account" link beside it: the sign-in page already
    // offers one to new customers, so a second copy on every page was just
    // clutter.
    ?>
    <?= $this->Html->link('Log in', '/login') ?>
<?php endif; ?>
