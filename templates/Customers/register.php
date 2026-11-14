<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Customer $customer
 */
$this->assign('title', 'Create customer account');
$this->Html->script('form-enhancements', ['block' => true]);
$this->Html->script('password-validation', ['block' => true]);
?>

<?= $this->Html->css('dashboard') ?>

<div class="auth-wrapper">
    <div class="auth-brand">
        <a href="<?= $this->Url->build('/') ?>">
            <img src="<?= $this->Url->image('clean-flow-logo.png') ?>" alt="Clean Flow Plumbing" class="auth-logo">
        </a>
    </div>
    <?= $this->Form->create($customer, ['class' => 'auth-form auth-form--wide', 'data-password-form' => true]) ?>
    <div class="auth-card">
        <h1 class="auth-title">Create a customer account</h1>

        <div class="auth-field-grid">
            <?= $this->Form->control('cust_fname', ['label' => 'First name', 'required' => true]) ?>
            <?= $this->Form->control('cust_lname', ['label' => 'Last name', 'required' => true]) ?>
        </div>
        <?= $this->Form->control('cust_email', [
            'label' => 'Email',
            'type' => 'email',
            'required' => true,
            'autocomplete' => 'email',
        ]) ?>
        <?= $this->Form->control('cust_phone', [
            'label' => 'Phone',
            'type' => 'tel',
            'autocomplete' => 'tel',
            'maxlength' => 12,
            'inputmode' => 'numeric',
            'placeholder' => '0412 345 678 or 03 9123 4567',
            'pattern' => '(?:04[0-9]{2} [0-9]{3} [0-9]{3}|03 [0-9]{4} [0-9]{4})',
            'data-au-phone' => true,
            'title' => 'Enter a 03 landline (03 9123 4567) or 04 mobile (0412 345 678).',
        ]) ?>
        <small>Enter a 03 landline (03 9123 4567) or 04 mobile (0412 345 678).</small>
        <?= $this->Form->control('cust_address', [
            'label' => 'Address',
            'autocomplete' => 'street-address',
        ]) ?>
        <div class="auth-field-grid">
            <?= $this->Form->control('password', [
                'label' => 'Password',
                'type' => 'password',
                'required' => false,
                'autocomplete' => 'new-password',
                'data-password-field' => true,
            ]) ?>
            <?= $this->Form->control('confirm_password', [
                'label' => 'Confirm password',
                'type' => 'password',
                'required' => false,
                'autocomplete' => 'new-password',
                'data-confirm-password' => true,
            ]) ?>
        </div>
        <div class="password-guidance" aria-label="Password requirements">
            <p>Password must include:</p>
            <ul>
                <li>At least one uppercase letter</li>
                <li>At least one number</li>
                <li>At least one special character</li>
            </ul>
        </div>
        <?= $this->Form->button('Create account', ['class' => 'auth-submit']) ?>

        <p class="auth-secondary">
            Already registered?
            <?php
            // Was ['action' => 'login'], which resolves relative to this
            // controller - Customers::login(), the old customer-only sign in
            // page. /login is the shared one now (see config/routes.php);
            // routing back to the old page here was exactly the kind of
            // "which login button" confusion that page was built to remove.
            ?>
            <?= $this->Html->link('Sign in', ['controller' => 'Login', 'action' => 'index']) ?>
        </p>
    </div>
    <?= $this->Form->end() ?>
</div>
