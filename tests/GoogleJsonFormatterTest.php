<?php

namespace Lhduc\LaravelGcpLogging\Tests;

use Lhduc\LaravelGcpLogging\Logging\GoogleJsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

class GoogleJsonFormatterTest extends TestCase
{
    private function format(array $context, string $message = 'm'): array
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'test', Level::Info, $message, $context);

        return (new GoogleJsonFormatter())->format($record);
    }

    public function test_throwable_is_expanded_with_top_three_frames(): void
    {
        $data = $this->format(['exception' => new \RuntimeException('boom', 42)]);

        $exception = $data['context']['exception'];
        $this->assertSame(\RuntimeException::class, $exception['class']);
        $this->assertSame('boom', $exception['message']);
        $this->assertSame(42, $exception['code']);
        $this->assertLessThanOrEqual(3, count($exception['trace']));
    }

    public function test_previous_is_kept_with_shorter_trace_and_capped_depth(): void
    {
        $e = new \RuntimeException('a', 0, new \LogicException('b', 0, new \Exception('c', 0, new \Exception('d'))));

        $exception = $this->format(['exception' => $e])['context']['exception'];

        $this->assertSame('b', $exception['previous']['message']);
        $this->assertLessThanOrEqual(1, count($exception['previous']['trace']));
        $this->assertSame('c', $exception['previous']['previous']['message']);
        $this->assertArrayNotHasKey('previous', $exception['previous']['previous']);
    }

    public function test_long_exception_message_is_truncated(): void
    {
        $data = $this->format(['exception' => new \Exception(str_repeat('x', 5000))]);

        $this->assertLessThanOrEqual(1000, mb_strlen($data['context']['exception']['message']));
    }

    public function test_scalar_context_and_tag_are_unchanged(): void
    {
        $data = $this->format(['tag' => 'Job', 'a' => ['b' => 1]]);

        $this->assertSame('Job', $data['tag']);
        $this->assertSame(['tag' => 'Job', 'a' => ['b' => 1]], $data['context']);
    }

    public function test_correlation_id_prefers_container_then_context(): void
    {
        $this->assertSame('ctx', $this->format(['correlation_id' => 'ctx'])['correlation_id']);

        app()->instance('correlation_id', 'container');
        $this->assertSame('container', $this->format(['correlation_id' => 'ctx'])['correlation_id']);
    }

    public function test_user_lookup_failure_does_not_throw_and_falls_back_to_context(): void
    {
        $this->app->singleton('auth', fn () => throw new \RuntimeException('db down'));

        $data = $this->format(['user_id' => 7]);

        $this->assertSame(7, $data['user_id']);
        $this->assertNull($data['user_email']);
    }
}
