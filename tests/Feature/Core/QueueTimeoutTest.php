<?php

namespace Tests\Feature\Core;

use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use Tests\TestCase;

/**
 * No job may outlive the queue's patience.
 *
 * A queue hands a reserved job to another worker once `retry_after` seconds
 * have passed, on the assumption that the first worker died. A job allowed to
 * run longer than that (a TrackedJob may run for an hour) is then started a
 * second time while the first copy is still at work — and with one try, the
 * second copy fails as "attempted too many times". That is what happened to
 * the Nova Poshta warehouse sync with `retry_after` at the framework's 90.
 *
 * Read off every queued class in core and in the modules, so a job written
 * tomorrow with a longer timeout fails here rather than in a shop.
 */
class QueueTimeoutTest extends TestCase
{
    public function test_every_job_finishes_before_the_queue_gives_up_on_it(): void
    {
        $jobs = $this->jobs();
        $this->assertNotEmpty($jobs, 'no queued classes were found — the scan is broken');

        foreach (config('queue.connections') as $name => $connection) {
            if (! isset($connection['retry_after'])) {
                continue;
            }

            foreach ($jobs as $class => $timeout) {
                $this->assertLessThan(
                    (int) $connection['retry_after'],
                    $timeout,
                    "{$class} may run {$timeout}s, but the [{$name}] queue gives up after {$connection['retry_after']}s",
                );
            }
        }
    }

    /** @return array<class-string, int> every concrete queued class and its timeout */
    protected function jobs(): array
    {
        $files = [
            ...$this->phpFiles(app_path()),
            ...$this->phpFiles(base_path('modules')),
        ];
        $jobs = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            if (! preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
                || ! preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $class)) {
                continue;
            }

            $name = $namespace[1].'\\'.$class[1];

            if (! class_exists($name) || ! is_subclass_of($name, ShouldQueue::class)) {
                continue;
            }

            $reflection = new ReflectionClass($name);
            $jobs[$name] = (int) ($reflection->getDefaultProperties()['timeout'] ?? 60);
        }

        return $jobs;
    }

    /** @return list<string> */
    protected function phpFiles(string $root): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ($file->isFile() && str_ends_with($path, '.php') && ! str_contains($path, 'migrations') && ! str_contains($path, DIRECTORY_SEPARATOR.'views'.DIRECTORY_SEPARATOR)) {
                $found[] = $path;
            }
        }

        return $found;
    }
}
