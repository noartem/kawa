<?php

use Symfony\Component\Process\Process;

afterEach(function (): void {
    foreach ($this->listenerProcesses ?? [] as $listenerProcess) {
        if ($listenerProcess instanceof Process && $listenerProcess->isRunning()) {
            $listenerProcess->stop(0);
        }
    }

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

printf 'APP_PORT=%s VITE_PORT=%s VITE_HMR_CLIENT_PORT=%s\n' "${APP_PORT:-}" "${VITE_PORT:-}" "${VITE_HMR_CLIENT_PORT:-}" >> "$FAKE_COMPOSER_PORT_LOG_PATH"
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

function runBinCommand(string $command, array $environment): Process
{
    $process = Process::fromShellCommandline("./bin/{$command}", base_path(), $environment);

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
            "bin/%s failed.\nOutput:\n%s\nError Output:\n%s\nLog Output:\n%s\nPort Log Output:\n%s\nInvocation Count Output:\n%s",
            $command,
            $process->getOutput(),
            $process->getErrorOutput(),
            $logOutput,
            $portLogOutput,
            $invocationCountOutput,
        ));
    }

    return $process;
}

function runBinStart(array $environment): Process
{
    return runBinCommand('start', $environment);
}

function runBinStop(array $environment): Process
{
    return runBinCommand('stop', $environment);
}

function runBinRestart(array $environment): Process
{
    return runBinCommand('restart', $environment);
}

function readLoggedPorts(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false || $lines === []) {
        throw new RuntimeException('Port log was not created.');
    }

    $line = end($lines);

    if (! is_string($line) || ! preg_match('/APP_PORT=(\d+) VITE_PORT=(\d+) VITE_HMR_CLIENT_PORT=(\d+)/', $line, $matches)) {
        throw new RuntimeException(sprintf('Unexpected port log line: %s', var_export($line, true)));
    }

    return [
        'app_port' => (int) $matches[1],
        'vite_port' => (int) $matches[2],
        'vite_hmr_client_port' => (int) $matches[3],
    ];
}

function startTcpListener(int $port): Process
{
    $process = new Process([
        PHP_BINARY,
        '-r',
        sprintf(
            '$server = stream_socket_server("tcp://127.0.0.1:%d"); if ($server === false) { fwrite(STDERR, "Unable to bind test listener.\\n"); exit(1); } sleep(15);',
            $port,
        ),
    ], base_path());

    $process->start();
    usleep(250000);

    if (! $process->isRunning()) {
        throw new RuntimeException(sprintf('Listener on port %d failed to start: %s', $port, $process->getErrorOutput()));
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
    $this->listenerProcesses = [];
    $this->portLogPath = $this->testDirectory.'/app-port.log';
    $this->invocationCountPath = $this->testDirectory.'/composer-invocations.log';
    [$appSocket, $availableAppPort] = reservePort();
    [$viteSocket, $availableVitePort] = reservePort();
    fclose($appSocket);
    fclose($viteSocket);

    mkdir($this->runDirectory, 0777, true);
    mkdir($this->binDirectory, 0777, true);

    makeFakeComposerScript($this->binDirectory, $this->portLogPath, $this->invocationCountPath);
    makeFakeSetsidScript($this->binDirectory);

    $this->baseEnvironment = [
        'APP_PORT' => (string) $availableAppPort,
        'FAKE_COMPOSER_INVOCATION_COUNT_PATH' => $this->invocationCountPath,
        'FAKE_COMPOSER_PORT_LOG_PATH' => $this->portLogPath,
        'PATH' => $this->binDirectory.':/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'UI_DEV_LOG_FILE' => $this->testDirectory.'/ui-dev.log',
        'UI_DEV_RUN_DIR' => $this->runDirectory,
        'VITE_HMR_CLIENT_PORT' => (string) $availableVitePort,
        'VITE_PORT' => (string) $availableVitePort,
    ];
});

it('reclaims occupied app and vite ports before starting', function (): void {
    $appPort = (int) $this->baseEnvironment['APP_PORT'];
    $vitePort = (int) $this->baseEnvironment['VITE_PORT'];

    $appListener = startTcpListener($appPort);
    $viteListener = startTcpListener($vitePort);

    $this->listenerProcesses = [$appListener, $viteListener];

    $process = runBinStart($this->baseEnvironment);
    $loggedPorts = readLoggedPorts($this->portLogPath);

    expect($process->getOutput())->toContain("APP_PORT {$appPort} is in use, stopping existing listener.");
    expect($process->getOutput())->toContain("VITE_PORT {$vitePort} is in use, stopping existing listener.");
    expect($loggedPorts['app_port'])->toBe($appPort);
    expect($loggedPorts['vite_port'])->toBe($vitePort);
    expect($loggedPorts['vite_hmr_client_port'])->toBe($vitePort);
    expect($appListener->isRunning())->toBeFalse();
    expect($viteListener->isRunning())->toBeFalse();
});

it('keeps the configured app port when it is available', function (): void {
    $process = runBinStart($this->baseEnvironment);
    $loggedPorts = readLoggedPorts($this->portLogPath);

    expect($process->getOutput())->not->toContain('is in use, stopping existing listener');
    expect($loggedPorts['app_port'])->toBe((int) $this->baseEnvironment['APP_PORT']);
    expect($loggedPorts['vite_port'])->toBe((int) $this->baseEnvironment['VITE_PORT']);
});

it('preserves the already-running pid behavior', function (): void {
    $firstRun = runBinStart($this->baseEnvironment);
    $secondRun = runBinStart($this->baseEnvironment);

    $invocations = trim((string) file_get_contents($this->invocationCountPath));

    expect($firstRun->getOutput())->toContain('UI dev stack started');
    expect($secondRun->getOutput())->toContain('UI dev stack is already running');
    expect($invocations)->toBe('1');
});

it('stops listeners tracked from a previous run even when the pid file is stale', function (): void {
    [$appSocket, $trackedAppPort] = reservePort();
    [$viteSocket, $trackedVitePort] = reservePort();
    fclose($appSocket);
    fclose($viteSocket);

    $appListener = startTcpListener($trackedAppPort);
    $viteListener = startTcpListener($trackedVitePort);

    $this->listenerProcesses = [$appListener, $viteListener];

    file_put_contents(
        $this->runDirectory.'/ui-dev.ports',
        sprintf("APP_PORT=%d\nVITE_PORT=%d\nVITE_HMR_CLIENT_PORT=%d\n", $trackedAppPort, $trackedVitePort, $trackedVitePort),
    );
    file_put_contents($this->runDirectory.'/ui-dev.pid', "999999\n");

    $process = runBinStop($this->baseEnvironment);

    expect($process->getOutput())->toContain('UI dev stack stopped.');
    expect($appListener->isRunning())->toBeFalse();
    expect($viteListener->isRunning())->toBeFalse();
    expect(file_exists($this->runDirectory.'/ui-dev.pid'))->toBeFalse();
    expect(file_exists($this->runDirectory.'/ui-dev.ports'))->toBeFalse();
});

it('restarts the dev stack cleanly', function (): void {
    runBinStart($this->baseEnvironment);

    $process = runBinRestart($this->baseEnvironment);
    $invocations = trim((string) file_get_contents($this->invocationCountPath));

    expect($process->getOutput())->toContain('UI dev stack stopped.');
    expect($process->getOutput())->toContain('UI dev stack started');
    expect($invocations)->toBe('2');
});
