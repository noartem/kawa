<?php

namespace App\Services;

use App\Support\DevLogPathResolver;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class DevLogTailer
{
    public function __construct(
        private readonly DevLogPathResolver $pathResolver,
    ) {}

    public function runPail(OutputInterface $output, int $timeout): void
    {
        $process = new Process([
            PHP_BINARY,
            'artisan',
            'pail',
            '--timeout='.$timeout,
        ], base_path());

        $this->runProcess($process, $output);
    }

    public function runFallback(OutputInterface $output, int $timeout): void
    {
        $logsPath = storage_path('logs');
        $logPaths = $this->pathResolver->resolve($logsPath, Carbon::now());

        foreach ($logPaths as $logPath) {
            if (file_exists($logPath)) {
                continue;
            }

            if (! is_dir(dirname($logPath))) {
                mkdir(dirname($logPath), 0755, true);
            }

            touch($logPath);
        }

        $process = new Process([
            'tail',
            '-n',
            '50',
            '-F',
            ...$logPaths,
        ], base_path());

        $this->runProcess($process, $output, $timeout);
    }

    private function runProcess(Process $process, OutputInterface $output, ?int $timeout = null): void
    {
        $process->setTimeout($timeout && $timeout > 0 ? $timeout : null);

        $exitCode = $process->run(
            static function (string $type, string $buffer) use ($output): void {
                $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
            }
        );

        if ($exitCode !== 0) {
            throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }
}
