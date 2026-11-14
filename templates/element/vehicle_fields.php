<?php
/** @var \App\View\AppView $this */
echo $this->Form->control('registration_number', [
    'label' => __('Registration number'),
    'required' => true,
    'maxlength' => 15,
    'placeholder' => __('For example, ABC 123'),
]);
echo $this->Form->control('label', [
    'label' => __('Vehicle name or fleet number'),
    'required' => true,
    'maxlength' => 100,
    'placeholder' => __('For example, Service van 1'),
]);
echo $this->Form->control('notes', [
    'label' => __('Notes (optional)'),
    'type' => 'textarea',
    'maxlength' => 500,
]);
