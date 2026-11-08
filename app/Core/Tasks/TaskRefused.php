<?php

namespace App\Core\Tasks;

use RuntimeException;

/**
 * Thrown inside a TrackedJob that could not begin for a reason the owner has
 * to put right and has been told about (a right the server has not given).
 * `TrackedJob::handle()` records the message on the task, which shows as
 * failed; nothing went wrong in the code, so it never reaches failed_jobs,
 * where "Retry" would only be refused again.
 */
class TaskRefused extends RuntimeException {}
