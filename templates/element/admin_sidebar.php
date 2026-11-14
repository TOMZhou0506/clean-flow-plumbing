<?php
use App\Model\Entity\Admin;
use App\Service\Content\ContentCatalogue;

/**
 * Office navigation.
 *
 * The client feedback was that contact enquiries and job requests were not
 * distinguishable: both arrived through the site, both ended up somewhere in
 * the admin area, and nothing on screen said which was which. They are two
 * genuinely different things. A contact enquiry is somebody asking a question.
 * A job request is a customer with an account asking for work at an address.
 * So they get separate entries here rather than being blended into one list.
 *
 * I mark the current section with aria-current as well as a class, so it is
 * announced to a screen reader rather than only shown with a colour.
 *
 * Built as a fixed rail with the logo inside it, the same shape as the
 * customer portal's sidebar (see customer_sidebar.php, dashboard.css and
 * portal-nav.js): it reaches the top of the page instead of sitting in a
 * grid column below the shared top-nav's logo, and it can be closed. The
 * toggle button is rendered here rather than in every admin template, so
 * there is one copy of it to keep in sync with the rail it controls.
 *
 * @var \App\View\AppView $this
 */

$this->Html->css('dashboard', ['block' => true]);
$this->Html->script('portal-nav', ['block' => true]);

$here = $this->getRequest()->getParam('controller');
$action = $this->getRequest()->getParam('action');
$identity = $this->getRequest()->getAttribute('identity');
$isManager = ($officeRole ?? $identity?->get('role')) === Admin::ROLE_MANAGER;
$isPlumber = ($officeRole ?? $identity?->get('role')) === Admin::ROLE_STAFF;
// Account records belong to the manager now, including an admin's own. The
// password form is the last thing in this controller an admin could reach, so
// the link has to go with it, otherwise the footer offers a 403.
$isAdmin = ($officeRole ?? $identity?->get('role')) === Admin::ROLE_ADMIN;
$officeAccountId = $officeAccountId ?? $identity?->get('admin_id');

// Job requests share a controller between the customer pages and the office
// list, so the controller name alone is not enough to decide what is current.
$onJobRequests = $here === 'JobRequests' && in_array($action, ['manage', 'manageView', 'assign'], true);
$onEnquiries = $here === 'ContactSubmissions' || $here === 'Compliance';
$onAudit = $here === 'AuditLogs';
$onAdmins = $here === 'Admins' && $action !== 'edit';
$onPlumbers = $here === 'Plumbers';
$onVehicles = $here === 'Vehicles' && $action !== 'schedule';
$onVehicleAvailability = $here === 'Vehicles' && $action === 'schedule';
$onPlumberAvailability = $here === 'PlumberAvailability';
$onManagerJobs = $here === 'ManagerJobs';

/**
 * The nav itself, walked by the loop below.
 *
 * Job requests sits first because that is where the office starts its day and
 * it is where a signed-in admin lands.
 *
 * Written as a plain array rather than a closure. My first version used a
 * closure with `use ($this)`, which is a fatal error: PHP binds $this to a
 * closure automatically and rejects it in the use list.
 *
 * @var list<array{heading:string, items:list<array{label:string, url:array<string, mixed>, current:bool}>}> $sections
 */
$sections = $isPlumber ? [
    [
        'heading' => __('Workspace'),
        'items' => [
            [
                'label' => __('My dashboard'),
                'url' => ['controller' => 'Plumbers', 'action' => 'dashboard'],
                'current' => $onPlumbers && $action === 'dashboard',
            ],
            [
                'label' => __('My Timetable'),
                'url' => ['controller' => 'Plumbers', 'action' => 'availability'],
                'current' => $onPlumbers && in_array($action, ['availability', 'cancelUnavailable'], true),
            ],
            [
                'label' => __('My jobs'),
                'url' => ['controller' => 'Plumbers', 'action' => 'jobs'],
                'current' => $onPlumbers && in_array($action, ['jobs', 'job', 'startJob', 'finishJob'], true),
            ],
        ],
    ],
] : ($isManager ? [
    [
        'heading' => __('Operations'),
        'items' => [
            [
                'label' => __('Job overview'),
                'url' => ['controller' => 'ManagerJobs', 'action' => 'index'],
                'current' => $onManagerJobs,
            ],
        ],
    ],
    [
        'heading' => __('Accounts'),
        'items' => [
            [
                'label' => __('Admin accounts'),
                'url' => ['controller' => 'Admins', 'action' => 'index'],
                'current' => $onAdmins,
            ],
            [
                'label' => __('Plumbers'),
                'url' => ['controller' => 'Plumbers', 'action' => 'index'],
                'current' => $onPlumbers,
            ],
        ],
    ],
] : [
    [
        'heading' => __('Submissions'),
        'items' => [
            [
                'label' => __('Job requests'),
                'url' => ['controller' => 'JobRequests', 'action' => 'manage'],
                'current' => $onJobRequests,
            ],
            [
                'label' => __('Contact enquiries'),
                'url' => ['controller' => 'ContactSubmissions', 'action' => 'index'],
                'current' => $onEnquiries,
            ],
        ],
    ],
    [
        'heading' => __('Resources'),
        'items' => [
            [
                'label' => __('Plumbers'),
                'url' => ['controller' => 'Plumbers', 'action' => 'index'],
                'current' => $onPlumbers,
            ],
            [
                'label' => __('Plumber availability'),
                'url' => ['controller' => 'PlumberAvailability', 'action' => 'index'],
                'current' => $onPlumberAvailability,
            ],
            [
                'label' => __('Vehicles'),
                'url' => ['controller' => 'Vehicles', 'action' => 'index'],
                'current' => $onVehicles,
            ],
            [
                'label' => __('Vehicle availability'),
                'url' => ['controller' => 'Vehicles', 'action' => 'schedule'],
                'current' => $onVehicleAvailability,
            ],
        ],
    ],
    // No Records section here any more. The audit trail belongs to the manager
    // role now, so an admin has nothing to put in it. Removing the entry
    // matters beyond tidiness: AuditLogsController refuses an admin outright,
    // so leaving the link would hand them a nav item that only ever produces a
    // 403.
]);

// The website content editor, only for the roles the client nominated in
// Cms.editorRoles. Anyone else would only get a 403 from it, so they do not
// see the link at all.
if (ContentCatalogue::canEdit($officeRole ?? $identity?->get('role'))) {
    $sections[] = [
        'heading' => __('Website'),
        'items' => [
            [
                'label' => __('Website content'),
                'url' => ['controller' => 'SiteContents', 'action' => 'index'],
                'current' => $here === 'SiteContents',
            ],
        ],
    ];
}
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

<?= $this->element('session_guard', ['logoutUrl' => ['controller' => 'Admins', 'action' => 'logout']]) ?>
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

    <nav class="dash__nav" aria-label="<?= __('Office sections') ?>">
        <?php foreach ($sections as $section) : ?>
            <p class="dash__nav-heading"><?= h($section['heading']) ?></p>
            <ul class="dash__nav-list">
                <?php foreach ($section['items'] as $item) : ?>
                    <li class="dash__nav-item">
                        <?php
                        // The attribute is added only when it applies. Passing
                        // false would rely on CakePHP omitting it rather than
                        // rendering aria-current="false", which means something
                        // different to a screen reader.
                        $linkOptions = [
                            'class' => 'dash__nav-link' . ($item['current'] ? ' is-current' : ''),
                        ];
                        if ($item['current']) {
                            $linkOptions['aria-current'] = 'page';
                        }
                        ?>
                        <?= $this->Html->link($item['label'], $item['url'], $linkOptions) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>

        <ul class="dash__nav-list dash__nav-list--footer">
            <?php if (!$isAdmin) : ?>
                <li class="dash__nav-item">
                    <?= $this->Html->link(__('Change password'), [
                        'controller' => 'Admins',
                        'action' => 'edit',
                        $officeAccountId,
                    ], [
                        'class' => 'dash__nav-link' . ($here === 'Admins' && $action === 'edit' ? ' is-current' : ''),
                    ]) ?>
                </li>
            <?php endif; ?>
            <li class="dash__nav-item">
                <?= $this->Html->link(__('Back to home'), '/', ['class' => 'dash__nav-link']) ?>
            </li>
            <li class="dash__nav-item">
                <?php
                // postLink, not link. Logging out changes state, so it should not
                // happen on a GET that any page can trigger with an image tag.
                // This posts with the CSRF token, so only a real click here does
                // it. Same shape as the customer menu's log out.
                ?>
                <?= $this->Form->postLink(__('Log out'), [
                    'controller' => 'Admins',
                    'action' => 'logout',
                ], ['class' => 'dash__nav-link']) ?>
            </li>
        </ul>
    </nav>
</aside>
