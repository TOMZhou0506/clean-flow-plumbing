<?php
declare(strict_types=1);

namespace App\Controller;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Event\EventInterface;

/**
 * Customer account registration.
 *
 * @property \App\Model\Table\CustomersTable $Customers
 */
class CustomersController extends AppController
{
    /**
     * Keep registration public.
     *
     * @param \Cake\Event\EventInterface $event Controller event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['register']);
    }

    /**
     * Register a customer account.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function register()
    {
        $customers = $this->fetchTable('Customers');
        $customer = $customers->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $this->request->getData();
            if (isset($data['cust_email']) && is_string($data['cust_email'])) {
                $data['cust_email'] = strtolower(trim($data['cust_email']));
            }

            $customer = $customers->patchEntity($customer, $data);
            if ($customer->getErrors() !== []) {
                $this->Flash->error('Please correct the highlighted fields.');
            } else {
                $customer->set(
                    'cust_password_hash',
                    (new DefaultPasswordHasher())->hash((string)$data['password']),
                );

                if ($customers->save($customer)) {
                    $this->Flash->success('Your customer account has been created.');

                    return $this->redirect(['action' => 'register']);
                }

                $this->Flash->error(
                    'Your account could not be created. Please check the form and try again.',
                );
            }
        }

        $this->set(compact('customer'));
    }

    /**
     * End the customer session.
     *
     * @return \Cake\Http\Response
     */
    public function logout()
    {
        $this->request->allowMethod(['post']);
        $this->Authentication->logout();

        // Same reasoning as AdminsController::logout(). logout() only clears
        // the keys for the authenticators loaded on this URL, which here is
        // CustomerAuth, so a stale AdminAuth would outlive it. Destroy the lot
        // and let Flash start a fresh session.
        $this->request->getSession()->destroy();

        $this->Flash->success('You have been logged out successfully.');

        return $this->redirect('/');
    }
}
