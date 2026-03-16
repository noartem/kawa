<?php

namespace App\Console\Commands;

use App\Services\DevLogTailer;
use Illuminate\Console\Command;
use Throwable;

class DevTailLogs extends Command
{
    protected $signature = 'dev:tail-logs
        {--timeout=3600 : The maximum execution time in seconds}';

    protected $description = 'Tail application logs with a resilient fallback when pail fails';

    public function handle(DevLogTailer $tailer): int
    {
        $timeout = (int) $this->option('timeout');

        try {
            $tailer->runPail($this->output, $timeout);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $message = $exception->getMessage();

            $this->components->warn(
                'Pail crashed'.($message !== '' ? ': '.$message : '.').
                ' Falling back to plain log tailing.',
            );
        }

        try {
            $tailer->runFallback($this->output, $timeout);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $message = $exception->getMessage();

            $this->components->error(
                'Unable to tail logs'.($message !== '' ? ': '.$message : '.'),
            );

            return self::FAILURE;
        }
    }
}
