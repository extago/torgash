<?php

namespace Tests\Concerns;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;

/**
 * Requests served the way FrankenPHP's worker mode serves them: ONE
 * application booted once, cloned for every request, Octane's own resets in
 * between — Octane's real `Worker`, with only the socket faked
 * (`FakeWorker`/`FakeClient` ship in the package for exactly this).
 *
 * This is what makes worker mode testable on a machine that has no FrankenPHP:
 * whatever a request leaves behind in that application, the next request
 * finds — the leak the ordinary suite can never see, because php-fpm builds a
 * new application every time.
 *
 * The worker's application is a separate one with its own connection, so it
 * is handed the test's PDO once booted: what the test created inside its
 * transaction is visible to the worker, and rolled back with it.
 */
trait RunsInOctaneWorker
{
    protected ?FakeWorker $octaneWorker = null;

    protected ?FakeClient $octaneClient = null;

    /** The booted worker's application — what every request is cloned from. */
    protected function octaneApp(): Application
    {
        if (! $this->octaneWorker) {
            $this->octaneClient = new FakeClient([]);
            $this->octaneWorker = new FakeWorker(new ApplicationFactory(base_path()), $this->octaneClient);
            $this->octaneWorker->boot();
            $this->backToTheTest();

            $app = $this->octaneWorker->application();
            $pdo = DB::connection()->getPdo();
            $app['db']->connection()->setPdo($pdo)->setReadPdo($pdo);
        }

        return $this->octaneWorker->application();
    }

    /**
     * Serves the requests one after another through the same worker, and
     * returns the responses in order. A request that threw fails the test with
     * the worker's own report.
     *
     * @param  list<Request>  $requests
     * @return list<\Symfony\Component\HttpFoundation\Response>
     */
    protected function serveInWorker(array $requests): array
    {
        $this->octaneApp();

        $client = $this->octaneClient;
        $client->requests = $requests;
        $client->responses = [];
        $client->errors = [];

        try {
            $this->octaneWorker->run();
        } finally {
            $this->backToTheTest();
        }

        $this->assertSame([], $client->errors, 'a request failed inside the worker');

        return $client->responses;
    }

    /**
     * Booting and serving point the global container and the facades at the
     * worker's application (Octane's CurrentApplication): the test's own code
     * after it has to find the test's application again.
     */
    protected function backToTheTest(): void
    {
        Container::setInstance($this->app);
        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();
    }

    protected function workerGet(string $url, array $headers = []): Request
    {
        $request = Request::create($url, 'GET');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
