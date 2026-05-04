<?php

use Symfony\Component\Process\Process;

afterEach(function (): void {
    if (! isset($this->runDirectory)) {
        return;
    }

    $pidFile = $this->runDirectory.'/ui-dev.pid';

    if (is_file($pidFile)) {
        $pid = (int) trim((string) file_get_contents($pidFile));

        if ($pid > 0) {
            (new Process(['kill', '-TERM', (string) $pid]))->run();
            usleep(250000);
        }
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->testDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());

            continue;
        }

        unlink($file->getPathname());
    }

    rmdir($this->testDirectory);
});

function makeFakeComposerScript(string $directory, string $portLogPath, string $invocationCountPath): void
{
    $script = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

count=0

if [[ -f "$FAKE_COMPOSER_INVOCATION_COUNT_PATH" ]]; then
    count="$(<"$FAKE_COMPOSER_INVOCATION_COUNT_PATH")"
fi

printf '%s\n' "$APP_PORT" >> "$FAKE_COMPOSER_PORT_LOG_PATH"
printf '%s\n' "$((count + 1))" > "$FAKE_COMPOSER_INVOCATION_COUNT_PATH"

sleep 15
BASH;

    file_put_contents($directory.'/composer', $script);
    chmod($directory.'/composer', 0755);
}

function makeFakeSetsidScript(string $directory): void
{
    $script = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

exec "$@"
BASH;

    file_put_contents($directory.'/setsid', $script);
    chmod($directory.'/setsid', 0755);
}

function runBinStart(array $environment): Process
{
    $process = Process::fromShellCommandline('./bin/start', base_path(), $environment);

    $process->run();

    if (! $process->isSuccessful()) {
        $logOutput = is_file($environment['UI_DEV_LOG_FILE'])
            ? (string) file_get_contents($environment['UI_DEV_LOG_FILE'])
            : 'Log file was not created.';
        $portLogOutput = is_file($environment['FAKE_COMPOSER_PORT_LOG_PATH'])
            ? (string) file_get_contents($environment['FAKE_COMPOSER_PORT_LOG_PATH'])
            : 'Port log was not created.';
        $invocationCountOutput = is_file($environment['FAKE_COMPOSER_INVOCATION_COUNT_PATH'])
            ? (string) file_get_contents($environment['FAKE_COMPOSER_INVOCATION_COUNT_PATH'])
            : 'Invocation count file was not created.';

        throw new RuntimeException(sprintf(
            "bin/start failed.\nOutput:\n%s\nError Output:\n%s\nLog Output:\n%s\nPort Log Output:\n%s\nInvocation Count Output:\n%s",
            $process->getOutput(),
            $process->getErrorOutput(),
            $logOutput,
            $portLogOutput,
            $invocationCountOutput,
        ));
    }

    return $process;
}

function reservePort(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');

    if ($socket === false) {
        throw new RuntimeException('Unable to reserve a TCP port for testing.');
    }

    $address = stream_socket_get_name($socket, false);

    if ($address === false) {
        fclose($socket);

        throw new RuntimeException('Unable to inspect the reserved TCP port.');
    }

    $port = (int) substr((string) strrchr($address, ':'), 1);

    return [$socket, $port];
}

beforeEach(function (): void {
    $this->testDirectory = storage_path('framework/testing/bin-start-'.bin2hex(random_bytes(8)));
    $this->runDirectory = $this->testDirectory.'/run';
    $this->binDirectory = $this->testDirectory.'/bin';
    $this->portLogPath = $this->testDirectory.'/app-port.log';
    $this->invocationCountPath = $this->testDirectory.'/composer-invocations.log';
    [$socket, $availablePort] = reservePort();
    fclose($socket);

    mkdir($this->runDirectory, 0777, true);
    mkdir($this->binDirectory, 0777, true);

    makeFakeComposerScript($this->binDirectory, $this->portLogPath, $this->invocationCountPath);
    makeFakeSetsidScript($this->binDirectory);

    $this->baseEnvironment = [
        'APP_PORT' => (string) $availablePort,
        'FAKE_COMPOSER_INVOCATION_COUNT_PATH' => $this->invocationCountPath,
        'FAKE_COMPOSER_PORT_LOG_PATH' => $this->portLogPath,
        'PATH' => $this->binDirectory.':/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'UI_DEV_LOG_FILE' => $this->testDirectory.'/ui-dev.log',
        'UI_DEV_RUN_DIR' => $this->runDirectory,
    ];
});

it('selects a fallback app port when the configured port is occupied', function (): void {
    [$socket, $occupiedPort] = reservePort();

    $environment = [
        ...$this->baseEnvironment,
        'APP_PORT' => (string) $occupiedPort,
    ];

    $process = runBinStart($environment);

    fclose($socket);

    $usedPort = trim((string) file_get_contents($this->portLogPath));

    expect($process->getOutput())->toContain("APP_PORT {$occupiedPort} is in use, using ");
    expect($usedPort)->not->toBe((string) $occupiedPort);
    expect((int) $usedPort)->toBeGreaterThan($occupiedPort);
});

it('keeps the configured app port when it is available', function (): void {
    [$socket, $availablePort] = reservePort();
    fclose($socket);

    runBinStart([
        ...$this->baseEnvironment,
        'APP_PORT' => (string) $availablePort,
    ]);

    $usedPort = trim((string) file_get_contents($this->portLogPath));

    expect($usedPort)->toBe((string) $availablePort);
});

it('preserves the already-running pid behavior', function (): void {
    $firstRun = runBinStart($this->baseEnvironment);
    $secondRun = runBinStart($this->baseEnvironment);

    $invocations = trim((string) file_get_contents($this->invocationCountPath));

    expect($firstRun->getOutput())->toContain('UI dev stack started');
    expect($secondRun->getOutput())->toContain('UI dev stack is already running');
    expect($invocations)->toBe('1');
});
