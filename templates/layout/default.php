<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         0.10.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 * @var \App\View\AppView $this
 */

$siteName = 'Clean Flow Plumbing';
$pageTitle = $this->fetch('title');
?>
<!DOCTYPE html>
<html>
<head>
    <?= $this->Html->charset() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle !== '' ? "{$siteName} - {$pageTitle}" : $siteName ?></title>
    <link rel="icon" type="image/png" href="<?= $this->Url->image('favicon.png') ?>">
    <link rel="apple-touch-icon" href="<?= $this->Url->image('apple-touch-icon.png') ?>">

    <?= $this->Html->css(['normalize.min', 'milligram.min', 'fonts', 'cake', 'customer-menu']) ?>

    <?= $this->fetch('meta') ?>
    <?= $this->fetch('css') ?>
    <?= $this->fetch('script') ?>
    <?php // On every page, so a tab that is still on the site is never taken for a closed one. ?>
    <?= $this->Html->script('session-guard') ?>
</head>
<body>
    <nav class="top-nav">
        <div class="top-nav-title">
            <a href="<?= $this->Url->build('/') ?>">
                <img
                    src="<?= $this->Url->image('clean-flow-logo.png') ?>"
                    alt="Clean Flow Plumbing"
                    class="brand-logo"
                >
            </a>
        </div>
    </nav>
    <main class="main">
        <div class="container">
            <?= $this->Flash->render() ?>
            <?= $this->fetch('content') ?>
        </div>
    </main>
    <footer>
    </footer>

    <?php
    // Last thing in the body, so the assistant's markup and script load after
    // the page content rather than delaying it. It positions itself in the
    // bottom right corner regardless of where it sits in the document.
    ?>
    <?= $this->element('assistant') ?>
</body>
</html>
