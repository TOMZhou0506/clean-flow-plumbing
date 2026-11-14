<?php
declare(strict_types=1);

namespace App\Controller\Api;

use App\Model\Table\AuditLogsTable;
use Cake\Event\EventInterface;
use Cake\Http\Response;

/**
 * Session state for an API client.
 *
 * I leave credential checking to the Authentication plugin. This controller
 * only reports whether a session exists and ends it on request.
 *
 * @property \App\Model\Table\AuditLogsTable $AuditLogs
 */
class SessionController extends ApiController
{
    /**
     * I declare this rather than assigning it dynamically, because PHP 8.4
     * deprecates dynamic properties.
     *
     * @var \App\Model\Table\AuditLogsTable
     */
    private AuditLogsTable $auditLogs;

    /**
     * Initialize method
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->auditLogs = $this->fetchTable('AuditLogs');
    }

    /**
     * Allows the session check to run without authentication.
     *
     * @param \Cake\Event\EventInterface $event The beforeFilter event.
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);

        // A client calls this on boot to decide whether to render itself or
        // show a sign-in prompt, so an unauthenticated answer is a normal
        // outcome for me rather than an error.
        $this->Authentication->allowUnauthenticated(['show']);
    }

    /**
     * GET /api/session
     *
     * Always 200.
     */
    public function show(): void
    {
        $result = $this->Authentication->getResult();

        if ($result === null || !$result->isValid()) {
            $this->ok([
                'authenticated' => false,
                'user' => null,
            ]);

            return;
        }

        $identity = $this->request->getAttribute('identity');

        // The admins table holds a username and nothing else identifying, so
        // I have no real name or email address to return. If we later add a
        // display name or an email column to admins, I should surface it
        // here.
        $username = (string)$identity->get('admin_username');

        $this->ok([
            'authenticated' => true,
            'user' => [
                // admin_id, not getIdentifier(). See the note on
                // AppController::currentAdminId(): getIdentifier() reads
                // get('id') and this table has no id column, so it was
                // returning null here on every real login.
                'id' => $this->currentAdminId(),
                'username' => $username,
                'name' => $username,
            ],
            'server_time' => gmdate('c'),
        ]);
    }

    /**
     * POST /api/session/logout
     */
    public function logout(): ?Response
    {
        $this->request->allowMethod(['post']);

        $this->auditLogs->record(
            'session.logout',
            $this->currentUserId(),
            null,
            null,
            [],
            $this->clientIp(),
        );

        $this->Authentication->logout();

        $this->ok(['authenticated' => false]);

        return null;
    }
}
