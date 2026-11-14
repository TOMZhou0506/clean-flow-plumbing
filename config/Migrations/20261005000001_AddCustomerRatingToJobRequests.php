<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Lets a customer rate a completed job from 1 to 5 stars.
 *
 * The rating lives on the job request itself. A job has exactly one plumber
 * through job_assignments, so the plumber's average can be worked out by
 * joining the two and nothing more needs storing.
 *
 * Both columns stay empty until the customer rates, which is how "not rated
 * yet" is told apart from a rating. The 1 to 5 range and the rule that a job
 * can only be rated once are enforced when the rating is saved, not here.
 *
 * Each column is only added if it is missing, so the same change can be applied
 * by hand on a server without a command line and recorded afterwards without
 * this migration failing on the second run.
 *
 * Run `bin\cake cache clear_all` afterwards or the ORM will not see the columns.
 */
class AddCustomerRatingToJobRequests extends BaseMigration
{
    public function up(): void
    {
        $table = $this->table('job_requests');

        if (!$table->hasColumn('customer_rating')) {
            $table->addColumn('customer_rating', 'tinyinteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'read_by',
            ]);
        }
        if (!$table->hasColumn('rated_at')) {
            $table->addColumn('rated_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'customer_rating',
            ]);
        }

        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('job_requests');

        if ($table->hasColumn('rated_at')) {
            $table->removeColumn('rated_at');
        }
        if ($table->hasColumn('customer_rating')) {
            $table->removeColumn('customer_rating');
        }

        $table->update();
    }
}
