<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Questions the assistant could not answer.
 *
 * Only the failures are kept. A question we answered taught us nothing we did
 * not already know, and storing it would mean holding customer free text for no
 * purpose. These rows exist for one reason: to show the team what to add to the
 * knowledge base next.
 *
 * Stored once per distinct question with a counter rather than one row per ask.
 * That keeps the table small, makes "what do people actually ask" readable at a
 * glance instead of needing a GROUP BY, and means a bored visitor sending the
 * same thing fifty times adds one row rather than fifty.
 *
 * The text is still free text somebody typed, so it can contain a name, an
 * address or a phone number even though nothing asked for one. It is treated as
 * customer data: manager-only on screen, and worth clearing out once a question
 * has been dealt with.
 */
class CreateAssistantUnansweredQuestions extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('assistant_unanswered_questions', [
            'collation' => 'utf8mb4_unicode_ci',
        ])
            // Hash of the normalised question, so the same thing asked with
            // different capitalisation or spacing lands on one row. Unique, so
            // the upsert is the database's job rather than a read-then-write
            // race between two visitors.
            ->addColumn('question_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('question', 'text', ['null' => false])
            // Which kind of account was asking. Not who: the point is to know
            // whether customers or office staff are hitting the gap, and a
            // single word answers that without identifying anybody.
            ->addColumn('audience', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('times_asked', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 1,
            ])
            // Why we could not answer. Separates "nothing in the knowledge
            // base" from "the API was unreachable", which need completely
            // different fixes and would otherwise look identical in this table.
            ->addColumn('reason', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('resolved', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('created', 'datetime', ['null' => false])
            ->addColumn('modified', 'datetime', ['null' => false])
            ->addIndex(['question_hash'], [
                'name' => 'uq_assistant_question_hash',
                'unique' => true,
            ])
            ->addIndex(['resolved', 'times_asked'], [
                'name' => 'idx_assistant_unresolved_popular',
            ])
            ->create();
    }
}
