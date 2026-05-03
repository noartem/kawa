#!/usr/bin/env bash
set -euo pipefail

if command -v rtk >/dev/null 2>&1; then
    if ! rtk init -g --opencode; then
        printf 'Warning: failed to initialize RTK OpenCode plugin\n' >&2
    fi
else
    printf 'Warning: rtk binary not found; OpenCode plugin not initialized\n' >&2
fi

exec sleep infinity
