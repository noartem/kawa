<?php

namespace Tests\Feature;

use App\Services\DevLogTailer;
use App\Support\DevLogPathResolver;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

class DevTailLogsCommandTest extends TestCase
{
    public function test_command_falls_back_when_pail_crashes(): void
    {
        $tailer = Mockery::mock(DevLogTailer::class);
        $tailer->shouldReceive('runPail')
            ->once()
            ->andThrow(new \RuntimeException('Syntax error'));
        $tailer->shouldReceive('runFallback')
            ->once();

        $this->app->instance(DevLogTailer::class, $tailer);

        $this->artisan('dev:tail-logs', ['--timeout' => '0'])
            ->expectsOutputToContain('Pail crashed: Syntax error Falling back to plain log tailing.')
            ->assertExitCode(0);
    }

    public function test_log_path_resolver_prefers_today_and_latest_existing_log(): void
    {
        $logsPath = storage_path('framework/testing/dev-tail-logs');

        if (! is_dir($logsPath)) {
            mkdir($logsPath, 0755, true);
        }

        $olderLogPath = $logsPath.'/laravel-2026-03-15.log';
        $newerLogPath = $logsPath.'/laravel-2026-03-14.log';

        touch($olderLogPath, strtotime('2026-03-15 08:00:00'));
        touch($newerLogPath, strtotime('2026-03-15 09:00:00'));

        $resolver = new DevLogPathResolver;

        $paths = $resolver->resolve($logsPath, CarbonImmutable::parse('2026-03-16 10:00:00'));

        $this->assertSame([
            $logsPath.'/laravel-2026-03-16.log',
            $newerLogPath,
        ], $paths);

        unlink($olderLogPath);
        unlink($newerLogPath);
        rmdir($logsPath);
    }
}
