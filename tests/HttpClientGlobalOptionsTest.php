<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

class HttpClientGlobalOptionsTest extends TestCase
{
    private static bool $appSetOptionsBeforeBoot = false;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Simulates an app/package that set global options before ours boots.
        if (self::$appSetOptionsBeforeBoot) {
            $app->make(Factory::class)->globalOptions(['timeout' => 5]);
        }
    }

    private function globalOptions(): array
    {
        return (fn () => $this->globalOptions)->call(Http::getFacadeRoot());
    }

    public function test_package_does_not_touch_global_options(): void
    {
        $this->assertSame([], $this->globalOptions());
    }

    public function test_requests_are_logged_when_app_sets_global_options_later(): void
    {
        Http::fake();
        app()->instance('correlation_id', 'cid-10');

        Http::globalOptions(['timeout' => 5]); // would have wiped the old on_stats hook
        Http::get('http://example.test/x');

        $this->assertSame(['timeout' => 5], $this->globalOptions());
        $this->assertCount(1, $this->googleRecords());
        $this->assertSame('HttpClient', $this->googleRecords()[0]->context['tag']);
    }

    public function test_app_options_set_before_boot_are_kept(): void
    {
        self::$appSetOptionsBeforeBoot = true;

        try {
            $this->refreshApplication();
            Http::fake();
            app()->instance('correlation_id', 'cid-11');

            Http::get('http://example.test/x');

            $this->assertSame(['timeout' => 5], $this->globalOptions());
            $this->assertCount(1, $this->googleRecords());
        } finally {
            self::$appSetOptionsBeforeBoot = false;
        }
    }

    public function test_apps_own_on_stats_callback_still_runs(): void
    {
        Http::fake();
        app()->instance('correlation_id', 'cid-12');
        $called = 0;

        Http::withOptions(['on_stats' => function () use (&$called) {
            $called++;
        }])->get('http://example.test/x');

        $this->assertSame(1, $called);
        $this->assertCount(1, $this->googleRecords());
    }
}
