<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * A question the assistant could not answer.
 *
 * @property int $id
 * @property string $question_hash
 * @property string $question
 * @property string $audience
 * @property int $times_asked
 * @property string $reason
 * @property bool $resolved
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class AssistantUnansweredQuestion extends Entity
{
    /**
     * Nothing is mass assignable.
     *
     * Every field here is set deliberately by the table's record() method from
     * values the application controls. There is no form behind this entity and
     * never should be, so leaving anything accessible would only create a way
     * for a request body to reach it.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
    ];
}
