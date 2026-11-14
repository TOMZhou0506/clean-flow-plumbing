<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * AuditLog Entity
 *
 * @property int $id
 * @property string|null $user_id
 * @property string $action
 * @property string|null $entity_type
 * @property string|null $entity_id
 * @property string|null $detail
 * @property string|null $ip_address
 * @property \Cake\I18n\DateTime $created
 */
class AuditLog extends Entity
{
    /**
     * Nothing is mass-assignable.
     *
     * I only ever create audit entries through AuditLogsTable::record(),
     * which sets each field explicitly. Leaving these false means no request
     * body can forge an entry, whatever route it arrives on.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'user_id' => false,
        'action' => false,
        'entity_type' => false,
        'entity_id' => false,
        'detail' => false,
        'ip_address' => false,
        'created' => false,
    ];
}
