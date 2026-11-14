<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\PasswordResetService;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Forgot password, for office accounts (admins, plumbers and the manager).
 *
 * Two steps: ask for a code, then use it. The code goes to the account's own
 * mailbox on the site's domain. Customers are not covered.
 *
 * @property \App\Model\Table\PasswordResetsTable $PasswordResets
 */
class PasswordResetsController extends AppController
{
    /**
     * Anyone who cannot sign in has to be able to reach these pages.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        $this->Authentication->allowUnauthenticated(['request', 'reset']);
    }

    /**
     * Ask for a code.
     *
     * The answer is the same whether or not the address belongs to an account, so this page
     * cannot be used to find out which addresses exist.
     *
     * @return \Cake\Http\Response|null
     */
    public function request(): ?Response
    {
        if ($this->request->is('post')) {
            (new PasswordResetService())->requestCode($this->postedText('email'));

            $this->Flash->success(__(
                'If that email belongs to an account, a 6 digit code has been sent to it. It works for {0} minutes.',
                PasswordResetService::MINUTES,
            ));

            return $this->redirect(['action' => 'reset']);
        }

        return null;
    }

    /**
     * Use the code to set a new password.
     *
     * @return \Cake\Http\Response|null
     */
    public function reset(): ?Response
    {
        $admin = $this->fetchTable('Admins')->newEmptyEntity();

        if ($this->request->is('post')) {
            $result = (new PasswordResetService())->reset(
                $this->postedText('email'),
                $this->postedText('code'),
                $this->postedText('admin_password'),
                $this->postedText('confirm_password'),
                $this->request->clientIp() ?? '0.0.0.0',
            );

            if ($result['status'] === PasswordResetService::OK) {
                $this->Flash->success(__('Your password has been changed. Sign in with your new password.'));

                return $this->redirect(['controller' => 'Login', 'action' => 'index']);
            }

            if ($result['status'] === PasswordResetService::PASSWORD && $result['admin'] !== null) {
                $admin = $result['admin'];
                $this->Flash->error(__('The password could not be changed. Please check the fields below.'));
            } else {
                // One message for a wrong, expired, used or cancelled code and for an unknown address.
                $this->Flash->error(__('That code is not valid or has expired. Ask for a new code and try again.'));
            }
        }

        $this->set(compact('admin'));

        return null;
    }

    /**
     * @param string $field The posted field.
     * @return string
     */
    private function postedText(string $field): string
    {
        $value = $this->request->getData($field);

        return is_string($value) ? $value : '';
    }
}
