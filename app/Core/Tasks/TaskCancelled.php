<?php

namespace App\Core\Tasks;

use RuntimeException;

/**
 * Thrown inside a TrackedJob when the admin asked it to stop. Caught by
 * `TrackedJob::handle()`, which records the task as cancelled — it is not a
 * failure and never reaches failed_jobs.
 */
class TaskCancelled extends RuntimeException {}
