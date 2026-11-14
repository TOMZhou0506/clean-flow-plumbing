<?php
declare(strict_types=1);

namespace App\Model\Exception;

use RuntimeException;

/**
 * Thrown when a job is asked to proceed before compliance has been met.
 *
 * A dedicated class rather than a generic RuntimeException, so a controller
 * can catch this specific refusal and show the reason to Hannah, while a
 * genuine failure somewhere else still surfaces as an error rather than being
 * quietly reported as a compliance problem.
 *
 * The message is written to be read by an admin, not a developer: it names the
 * stage being blocked and how many requirements are outstanding, because "you
 * cannot do that" without a reason is the thing that makes people work around
 * a system rather than with it.
 */
class ComplianceNotMetException extends RuntimeException
{
}
