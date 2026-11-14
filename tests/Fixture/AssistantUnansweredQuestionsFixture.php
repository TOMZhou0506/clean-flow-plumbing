<?php
declare(strict_types=1);

namespace App\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * Deliberately empty.
 *
 * Several tests assert that asking an unanswerable question writes exactly one
 * row, and that an answered one writes none. Seeding anything here would make
 * those counts meaningless.
 */
class AssistantUnansweredQuestionsFixture extends TestFixture
{
    /**
     * @var array<array<string, mixed>>
     */
    public array $records = [];
}
