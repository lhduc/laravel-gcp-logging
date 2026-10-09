<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use Lhduc\LaravelGcpLogging\Providers\GoogleLoggingServiceProvider;
use Monolog\Handler\TestHandler;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [GoogleLoggingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Real apps build the HTTP kernel (which syncs middleware groups to the router)
        // before providers boot; Testbench does it lazily and would overwrite our `api` push.
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $app['config']->set('google-logging.project_id', 'test-project');
        $app['config']->set('google-logging.excluded_routes', ['api/health*']);

        // In-memory Monolog handler instead of a real Google client.
        $app['config']->set('logging.channels.google', [
            'driver' => 'monolog',
            'handler' => TestHandler::class,
        ]);
    }

    /** Records written to the `google` channel. */
    protected function googleRecords(): array
    {
        $handlers = logger()->channel('google')->getLogger()->getHandlers();

        return $handlers[0]->getRecords();
    }
}
