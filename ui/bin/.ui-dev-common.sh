#!/usr/bin/env bash

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
RUN_DIR="${UI_DEV_RUN_DIR:-$ROOT_DIR/.run}"
PID_FILE="$RUN_DIR/ui-dev.pid"
LOG_FILE="${UI_DEV_LOG_FILE:-$RUN_DIR/ui-dev.log}"
PORT_FILE="$RUN_DIR/ui-dev.ports"
ENV_FILE="${UI_DEV_ENV_FILE:-$ROOT_DIR/.env}"
ENV_EXAMPLE_FILE="$ROOT_DIR/.env.example"

read_key_from_file() {
    local key="$1"
    local file="$2"
    local line=""
    local value=""

    [[ -r "$file" ]] || return 1

    while IFS= read -r line || [[ -n "$line" ]]; do
        case "$line" in
            ''|'#'*)
                continue
                ;;
        esac

        if [[ "$line" != "$key="* ]]; then
            continue
        fi

        value="${line#*=}"
        value="${value#\"}"
        value="${value%\"}"
        value="${value#\'}"
        value="${value%\'}"

        printf '%s\n' "$value"
        return 0
    done <"$file"

    return 1
}

configured_value() {
    local key="$1"
    local value="${!key:-}"

    if [[ -n "$value" ]]; then
        printf '%s\n' "$value"
        return 0
    fi

    if value="$(read_key_from_file "$key" "$ENV_FILE" || true)"; then
        if [[ -n "$value" ]]; then
            printf '%s\n' "$value"
            return 0
        fi
    fi

    value="$(read_key_from_file "$key" "$ENV_EXAMPLE_FILE" || true)"

    if [[ -n "$value" ]]; then
        printf '%s\n' "$value"
        return 0
    fi

    return 1
}

configured_port() {
    local key="$1"
    local value=""

    value="$(configured_value "$key" || true)"

    if [[ ! "$value" =~ ^[0-9]+$ ]]; then
        return 1
    fi

    printf '%s\n' "$value"
}

is_tcp_port_available() {
    local port="$1"

    php -r '$port = (int) $argv[1]; $server = @stream_socket_server("tcp://0.0.0.0:${port}", $errorCode, $errorMessage); if ($server === false) { exit(1); } fclose($server);' "$port" >/dev/null 2>&1
}

is_pid_active() {
    local pid="$1"
    local state=""

    if [[ -z "$pid" ]] || ! kill -0 "$pid" 2>/dev/null; then
        return 1
    fi

    if [[ -r "/proc/$pid/status" ]]; then
        while IFS=$'\t' read -r key value; do
            if [[ "$key" == 'State:' ]]; then
                state="${value%% *}"
                break
            fi
        done <"/proc/$pid/status"
    fi

    if [[ -z "$state" ]]; then
        return 0
    fi

    if [[ "${state:0:1}" == 'Z' ]]; then
        return 1
    fi

    return 0
}

signal_pid_or_group() {
    local signal="$1"
    local pid="$2"

    kill "-$signal" -- "-$pid" 2>/dev/null || kill "-$signal" "$pid" 2>/dev/null || true
}

wait_for_pid_exit() {
    local pid="$1"
    local attempts="${2:-20}"

    for ((attempt = 0; attempt < attempts; attempt++)); do
        if ! is_pid_active "$pid"; then
            return 0
        fi

        sleep 0.5
    done

    return 1
}

list_listening_pids_for_port() {
    local port="$1"

    UI_DEV_PORT="$port" php <<'PHP'
<?php
declare(strict_types=1);

$port = (int) getenv('UI_DEV_PORT');

if ($port <= 0) {
    exit(0);
}

$socketInodes = [];

foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $file) {
    if (! is_readable($file)) {
        continue;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        continue;
    }

    foreach ($lines as $index => $line) {
        if ($index === 0) {
            continue;
        }

        $parts = preg_split('/\s+/', trim($line));

        if (! isset($parts[1], $parts[3], $parts[9])) {
            continue;
        }

        $localAddress = explode(':', $parts[1]);

        if (count($localAddress) !== 2) {
            continue;
        }

        if (hexdec($localAddress[1]) !== $port || $parts[3] !== '0A') {
            continue;
        }

        $socketInodes[$parts[9]] = true;
    }
}

if ($socketInodes === []) {
    exit(0);
}

$pids = [];

foreach (glob('/proc/[0-9]*/fd/*') ?: [] as $fileDescriptorPath) {
    $target = @readlink($fileDescriptorPath);

    if (! is_string($target)) {
        continue;
    }

    if (! preg_match('/^socket:\[(\d+)\]$/', $target, $matches)) {
        continue;
    }

    if (! isset($socketInodes[$matches[1]])) {
        continue;
    }

    $processDirectory = dirname(dirname($fileDescriptorPath));
    $pid = basename($processDirectory);

    if (ctype_digit($pid)) {
        $pids[(int) $pid] = true;
    }
}

$list = array_keys($pids);
sort($list);

foreach ($list as $pid) {
    echo $pid, PHP_EOL;
}
PHP
}

terminate_port_listener() {
    local label="$1"
    local port="$2"
    local pid=""
    local reclaimed=false

    if [[ -z "$port" ]] || [[ ! "$port" =~ ^[0-9]+$ ]]; then
        return 0
    fi

    if is_tcp_port_available "$port"; then
        return 0
    fi

    printf '%s %s is in use, stopping existing listener.\n' "$label" "$port"

    while IFS= read -r pid; do
        [[ -n "$pid" ]] || continue

        reclaimed=true
        signal_pid_or_group TERM "$pid"
        wait_for_pid_exit "$pid" 10 || true
    done < <(list_listening_pids_for_port "$port")

    if [[ "$reclaimed" == true ]] && ! is_tcp_port_available "$port"; then
        while IFS= read -r pid; do
            [[ -n "$pid" ]] || continue

            signal_pid_or_group KILL "$pid"
        done < <(list_listening_pids_for_port "$port")

        sleep 0.2
    fi

    if ! is_tcp_port_available "$port"; then
        printf 'Failed to reclaim %s %s.\n' "$label" "$port" >&2
        return 1
    fi

    return 0
}

write_runtime_ports() {
    local app_port="$1"
    local vite_port="$2"
    local hmr_client_port="$3"

    {
        printf 'APP_PORT=%s\n' "$app_port"
        printf 'VITE_PORT=%s\n' "$vite_port"
        printf 'VITE_HMR_CLIENT_PORT=%s\n' "$hmr_client_port"
    } >"$PORT_FILE"
}

remove_runtime_files() {
    rm -f "$PID_FILE" "$PORT_FILE"
}

emit_tracked_ports() {
    local key=""
    local port=""

    if [[ -r "$PORT_FILE" ]]; then
        for key in APP_PORT VITE_PORT VITE_HMR_CLIENT_PORT; do
            port="$(read_key_from_file "$key" "$PORT_FILE" || true)"

            if [[ "$port" =~ ^[0-9]+$ ]]; then
                printf '%s\n' "$port"
            fi
        done

        return 0
    fi

    for key in APP_PORT VITE_PORT VITE_HMR_CLIENT_PORT; do
        port="$(configured_port "$key" || true)"

        if [[ -n "$port" ]]; then
            printf '%s\n' "$port"
        fi
    done
}

tracked_ports_in_use() {
    local seen=' '
    local port=""

    while IFS= read -r port; do
        [[ -n "$port" ]] || continue

        if [[ "$seen" == *" $port "* ]]; then
            continue
        fi

        seen+="$port "

        if ! is_tcp_port_available "$port"; then
            return 0
        fi
    done < <(emit_tracked_ports)

    return 1
}

terminate_tracked_ports() {
    local seen=' '
    local port=""

    while IFS= read -r port; do
        [[ -n "$port" ]] || continue

        if [[ "$seen" == *" $port "* ]]; then
            continue
        fi

        seen+="$port "

        if [[ "$port" == "$(configured_port APP_PORT || true)" ]]; then
            terminate_port_listener 'APP_PORT' "$port"
            continue
        fi

        if [[ "$port" == "$(configured_port VITE_HMR_CLIENT_PORT || true)" ]]; then
            terminate_port_listener 'VITE_HMR_CLIENT_PORT' "$port"
            continue
        fi

        terminate_port_listener 'VITE_PORT' "$port"
    done < <(emit_tracked_ports)
}
