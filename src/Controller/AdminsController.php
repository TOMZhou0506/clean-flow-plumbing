<?php
declare(strict_types=1);

namespace App\Controller;

use App\Model\Entity\Admin;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;

/**
 * Admins Controller
 *
 * @property \App\Model\Table\AdminsTable $Admins
 */
class AdminsController extends AppController
{
    private const STATUS_CONFIRM_LIMIT = 5;

    private const STATUS_CONFIRM_WINDOW = 900;

    /**
     * Index method
     *
     * @return \Cake\Http\Response|null|void Renders view
     */
    public function index()
    {
        $query = $this->Admins->find()
            ->select(['admin_id', 'admin_username', 'admin_email', 'active'])
            ->where(['role' => Admin::ROLE_ADMIN]);
        $admins = $this->paginate($query);

        $this->set(compact('admins'));
    }

    /**
     * Add method
     *
     * @return \Cake\Http\Response|null|void Redirects on successful add, renders view otherwise.
     */
    public function add()
    {
        $admin = $this->Admins->newEmptyEntity();
        if ($this->request->is('post')) {
            $admin = $this->Admins->patchEntity($admin, $this->request->getData());
            // Role and status are never taken from the form body.
            $admin->set('role', Admin::ROLE_ADMIN);
            $admin->set('active', true);
            if ($this->Admins->save($admin)) {
                $this->fetchTable('AuditLogs')->record(
                    'admin.created',
                    $this->currentAdminId(),
                    'admin',
                    (string)$admin->admin_id,
                    [],
                    $this->request->clientIp() ?? '0.0.0.0',
                );
                $this->Flash->success(__(
                    'Admin #{0} has been created. They sign in with {1}.',
                    $admin->admin_id,
                    $admin->admin_email,
                ));

                return $this->redirect(['action' => 'index']);
            }
            $this->Flash->error(__('The admin could not be saved. Please, try again.'));
        }
        $this->set(compact('admin'));
    }

    /**
     * Shuts admins out of this controller entirely, including their own row.
     *
     * The team's decision is that account records belong to the manager, and
     * "including their own" was explicit. edit() is the only action an admin
     * could still reach, because it is the self-service password form, so this
     * is the one place that needed a narrower guard than requireManager().
     *
     * Scoped to the admin role on purpose rather than becoming
     * requireManager(). Plumbers sign in on ROLE_STAFF and still change their
     * own password here, and pulling that out from under the plumber portal
     * would be a change nobody asked for.
     *
     * Reads the stored role rather than the session copy, matching
     * requireOfficeRoles(). A role revoked a minute ago has to bite now, not
     * at the next sign-in. That costs one query, on one page, which is a fair
     * price.
     *
     * The practical consequence is worth stating plainly: an admin who needs a
     * new password now has to ask the manager for one, so the manager sets or
     * knows it. That follows from the decision rather than from this code.
     *
     * @return void
     * @throws \Cake\Http\Exception\ForbiddenException When the account is an admin.
     */
    private function refuseAdminAccountAccess(): void
    {
        $admin = $this->Admins->find()
            ->where(['admin_id' => $this->currentAdminId()])
            ->first();

        if ($admin !== null && $admin->role === Admin::ROLE_ADMIN) {
            throw new ForbiddenException('Account details are managed by the manager.');
        }
    }

    /**
     * Change only the signed-in office account's own password.
     *
     * Reachable by staff and the manager. Admins are turned away by
     * refuseAdminAccountAccess() in beforeFilter(), because the team moved
     * account records to the manager role, their own included.
     *
     * @param string|null $id The account to change, which must be your own.
     * @return \Cake\Http\Response|null|void
     * @throws \Cake\Http\Exception\ForbiddenException When the id is not your own account.
     */
    public function edit(?string $id = null)
    {
        if ($id === null || (int)$id !== $this->currentAdminId()) {
            throw new ForbiddenException('You can only change your own password.');
        }

        $admin = $this->Admins->get($id, contain: []);
        if ($this->request->is(['patch', 'post', 'put'])) {
            $oldPassword = $this->request->getData('current_password');
            if (!is_string($oldPassword) || !password_verify($oldPassword, $admin->admin_password)) {
                $this->Flash->error(__('The current password is incorrect.'));
            } else {
                // Ignore submitted username, role, status and target ID even
                // if a forged request includes them.
                $admin = $this->Admins->patchEntity($admin, [
                    'admin_password' => $this->request->getData('admin_password'),
                    'confirm_password' => $this->request->getData('confirm_password'),
                ]);

                if ($this->Admins->save($admin)) {
                    $this->fetchTable('AuditLogs')->record(
                        'admin.password_changed',
                        $this->currentAdminId(),
                        'admin',
                        (string)$admin->admin_id,
                        [],
                        $this->request->clientIp() ?? '0.0.0.0',
                    );
                    // This session carries on with the new password; any other one ends.
                    $this->Authentication->setIdentity($admin);
                    $this->Flash->success(__('Your password has been changed.'));

                    return $this->redirect(['action' => 'edit', $admin->admin_id]);
                }
                $this->Flash->error(__('The password could not be changed. Please check the fields below.'));
            }
        }
        $this->set(compact('admin'));
    }

    /**
     * Permanent deletion is deliberately unavailable, including old URLs.
     */
    public function delete(?string $id = null)
    {
        throw new ForbiddenException('Office accounts cannot be permanently deleted.');
    }

    /** Manager-only account deactivation, after password confirmation. */
    public function deactivate(?string $id = null): Response
    {
        return $this->setActive($id, false);
    }

    /** Manager-only account reactivation, after password confirmation. */
    public function reactivate(?string $id = null): Response
    {
        return $this->setActive($id, true);
    }

    /**
     * @param string|null $id Target admin ID.
     * @param bool $active The desired account status.
     * @return \Cake\Http\Response
     */
    private function setActive(?string $id, bool $active): Response
    {
        $this->request->allowMethod(['get', 'post']);
        $admin = $this->Admins->get($id);
        if ($admin->role !== Admin::ROLE_ADMIN) {
            throw new ForbiddenException('Only admin accounts can be changed here.');
        }

        $actionLabel = $active ? __('Reactivate') : __('Deactivate');
        $passwordError = null;
        if ($this->request->is('post')) {
            // One counter per manager, shared across sessions and targets.
            // Switching to another admin cannot reset password guesses.
            $rateKey = hash_hmac(
                'sha256',
                'manager_status' . "\0" . $this->currentAdminId(),
                (string)Configure::read('Security.salt'),
            );
            $counters = $this->fetchTable('LoginRateLimits');
            $limit = $counters->consume(
                $rateKey,
                'manager_status',
                self::STATUS_CONFIRM_LIMIT,
                self::STATUS_CONFIRM_WINDOW,
            );

            if (!$limit['allowed']) {
                $passwordError = __('Too many attempts. Please try again in 15 minutes.');
            } else {
                $manager = $this->Admins->get($this->currentAdminId());
                $password = $this->request->getData('manager_password');
                if (!is_string($password) || !password_verify($password, $manager->admin_password)) {
                    $passwordError = __('Your password is incorrect. The account status was not changed.');
                    $this->fetchTable('AuditLogs')->record(
                        'admin.status_confirmation_failed',
                        $this->currentAdminId(),
                        'admin',
                        (string)$admin->admin_id,
                        [],
                        $this->request->clientIp() ?? '0.0.0.0',
                    );
                } else {
                    $counters->clear($rateKey);
                    if ((bool)$admin->active !== $active) {
                        $admin->set('active', $active);
                        $this->Admins->saveOrFail($admin);
                        $this->fetchTable('AuditLogs')->record(
                            $active ? 'admin.reactivated' : 'admin.deactivated',
                            $this->currentAdminId(),
                            'admin',
                            (string)$admin->admin_id,
                            [],
                            $this->request->clientIp() ?? '0.0.0.0',
                        );
                    }

                    $this->Flash->success($active ? __('Admin reactivated.') : __('Admin deactivated.'));

                    return $this->redirect(['action' => 'index']);
                }
            }
        }

        $this->set(compact('admin', 'actionLabel', 'active', 'passwordError'));

        return $this->render('confirm_status');
    }

    /**
     * Managers administer accounts; active office users change only their
     * own password. Logout remains available without an identity.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // logout is allowed without an identity, which reads oddly until you
        // hit it. Without it, logging out when you are already logged out is
        // an authentication failure, so the middleware bounces you to
        // /login?redirect=/admins/logout. You then sign in, the login page
        // honours that redirect, and it logs you straight back out. Round and
        // round. Happens easily: a double click, the back button, or a session
        // that expired while the tab sat open.
        //
        // Logging out when you are not logged in should be a quiet no-op, not
        // an error.
        $this->Authentication->allowUnauthenticated(['logout']);

        $action = $this->request->getParam('action');
        if ($action === 'edit') {
            $this->requireOfficeAccount();
            $this->refuseAdminAccountAccess();
        } elseif ($action !== 'logout') {
            $this->requireManager();
        }
    }

    /**
     * Ends the admin session.
     *
     * @return \Cake\Http\Response|null Redirects to the login screen.
     */
    public function logout(): ?Response
    {
        // POST only, matching the customer logout.
        //
        // On a GET, any page anywhere could log an admin out with a hidden
        // <img src="https://oursite/admins/logout">, because the browser sends
        // the session cookie with that request like any other. A prefetching
        // browser or a link checker does the same thing by accident. Nothing
        // is destroyed by it, but being thrown out mid-task with no
        // explanation is the sort of bug nobody manages to reproduce.
        $this->request->allowMethod(['post']);

        $this->Authentication->logout();

        // Then throw the whole session away, not just the one key.
        //
        // Authentication->logout() only clears the session keys belonging to
        // the authenticators loaded for this request, and which ones those are
        // depends on the URL. On /admins/logout that is AdminAuth alone, so a
        // CustomerAuth left over from earlier survives, and anything else
        // sitting in the session survives with it. The symptom is the worst
        // kind: you appear to be logged out, then a later page finds a
        // leftover key and lets you back in.
        //
        // On a shared office machine, logging out should mean the session is
        // gone. Flash below writes into the fresh one.
        $this->request->getSession()->destroy();

        $this->Flash->success(__('You have been logged out.'));

        // The landing page, same as the customer logout.
        //
        // It used to return to /admins/login, which nothing links to any more.
        // I then pointed it at /login, which broke the page, so it goes to the
        // public home page instead. That is the better destination anyway:
        // somebody who has just logged out has said they are finished, and
        // showing them a login form immediately invites them to sign back into
        // the account they just left. The nav there carries a "Log in" link if
        // they did mean to switch accounts.
        return $this->redirect('/');
    }
}
