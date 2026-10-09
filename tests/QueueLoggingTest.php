<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

class QueueLoggingTest extends TestCase
{
    private function job(?string $correlationId): Job
    {
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['correlation_id' => $correlationId, 'uuid' => 'u-1']);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\Demo');
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('getRawBody')->andReturn('{}');

        return $job;
    }

    public function test_payload_carries_the_current_correlation_id(): void
    {
        app()->instance('correlation_id', 'cid-5');

        $payload = (fn () => $this->createPayloadArray(new \stdClass(), 'default'))->call(
            new class(app()) extends \Illuminate\Queue\SyncQueue {
                public function __construct($app)
                {
                    $this->container = $app;
                }
            }
        );

        $this->assertSame('cid-5', $payload['correlation_id']);
    }

    public function test_processing_a_job_sets_then_resets_the_correlation_id(): void
    {
        Event::dispatch(new JobProcessing('sync', $this->job('cid-6')));
        $this->assertSame('cid-6', app('correlation_id'));

        // A following job without an id must not inherit the previous one.
        Event::dispatch(new JobProcessing('sync', $this->job(null)));
        $this->assertFalse(app()->bound('correlation_id'));
    }

    public function test_job_events_are_logged(): void
    {
        Event::dispatch(new JobProcessed('sync', $this->job('cid-7')));
        Event::dispatch(new JobFailed('sync', $this->job('cid-8'), new \RuntimeException('nope')));

        $records = $this->googleRecords();
        $this->assertCount(2, $records);
        $this->assertSame('INFO', $records[0]->level->getName());
        $this->assertSame('cid-7', $records[0]->context['correlation_id']);
        $this->assertSame('ERROR', $records[1]->level->getName());
        $this->assertSame('nope', $records[1]->context['error']);
        $this->assertInstanceOf(\RuntimeException::class, $records[1]->context['exception']);
    }

    public function test_logging_failure_does_not_break_job_processing(): void
    {
        // Swap in a handler that throws on every write.
        app('log')->forgetChannel('google');
        config(['logging.channels.google.handler' => \Lhduc\LaravelGcpLogging\Tests\ThrowingHandler::class]);

        Event::dispatch(new JobProcessed('sync', $this->job('cid-9')));

        $this->assertTrue(true);
    }
}
