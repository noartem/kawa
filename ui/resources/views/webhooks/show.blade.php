<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>Webhook {{ $slug }}</title>

        <script>
            (function () {
                const appearance = window.localStorage.getItem('appearance') || 'system';

                if (appearance === 'dark') {
                    document.documentElement.classList.add('dark');
                    return;
                }

                if (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=manrope:400,500,600,700" rel="stylesheet" />
        @vite(['resources/css/app.css'])

        <style>
            [data-result-state='idle'] {
                border-color: color-mix(in srgb, var(--border) 78%, transparent);
                background: color-mix(in srgb, var(--muted) 70%, transparent);
            }

            [data-result-state='sending'] {
                border-color: color-mix(in srgb, var(--ring) 45%, transparent);
                background: color-mix(in srgb, var(--accent) 78%, transparent);
            }

            [data-result-state='success'] {
                border-color: color-mix(in srgb, oklch(0.7 0.16 154) 35%, var(--border));
                background: color-mix(in srgb, oklch(0.92 0.05 154) 78%, transparent);
            }

            [data-result-state='error'] {
                border-color: color-mix(in srgb, var(--destructive) 40%, var(--border));
                background: color-mix(in srgb, var(--destructive) 10%, transparent);
            }

            .dark [data-result-state='success'] {
                background: color-mix(in srgb, oklch(0.35 0.08 154) 55%, transparent);
            }

            .dark [data-result-state='error'] {
                background: color-mix(in srgb, var(--destructive) 16%, transparent);
            }
        </style>
    </head>
    <body class="min-h-screen bg-background font-sans text-foreground antialiased">
        <div class="relative isolate min-h-screen overflow-hidden">
            <div class="absolute inset-x-0 top-0 -z-10 h-64 bg-[radial-gradient(circle_at_top,rgba(15,23,42,0.08),transparent_60%)] dark:bg-[radial-gradient(circle_at_top,rgba(148,163,184,0.12),transparent_60%)]"></div>
            <div class="absolute inset-x-0 top-24 -z-10 mx-auto h-80 max-w-5xl rounded-full bg-[radial-gradient(circle,rgba(59,130,246,0.10),transparent_62%)] blur-3xl dark:bg-[radial-gradient(circle,rgba(59,130,246,0.16),transparent_62%)]"></div>

            <main class="mx-auto flex min-h-screen w-full max-w-6xl items-center px-4 py-10 sm:px-6 lg:px-8">
                <section class="grid w-full gap-6 lg:grid-cols-[minmax(0,0.95fr)_minmax(20rem,0.75fr)]">
                    <div class="rounded-3xl border border-border/80 bg-card/95 p-6 shadow-sm backdrop-blur sm:p-8">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="space-y-4">
                                <span class="inline-flex items-center rounded-full border border-border bg-muted px-3 py-1 text-[11px] font-semibold tracking-[0.22em] text-muted-foreground uppercase">
                                    Webhook endpoint
                                </span>

                                <div class="space-y-3">
                                    <h1 class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">
                                        {{ $slug }}
                                    </h1>
                                    <p class="max-w-2xl text-sm leading-6 text-muted-foreground sm:text-base">
                                        Send a JSON payload to this endpoint. The flow accepts the request immediately after the payload reaches the runtime.
                                    </p>
                                </div>
                            </div>

                            <div class="rounded-2xl border border-border bg-muted/40 px-4 py-3 text-right">
                                <div class="text-[11px] font-semibold tracking-[0.22em] text-muted-foreground uppercase">
                                    Active run
                                </div>
                                <div class="mt-1 text-lg font-semibold text-foreground">
                                    #{{ $run->id }}
                                </div>
                                <div class="text-sm text-muted-foreground">
                                    {{ ucfirst($run->type) }}
                                </div>
                            </div>
                        </div>

                        <div class="mt-8 grid gap-4 md:grid-cols-2">
                            <div class="rounded-2xl border border-border bg-background/80 p-4">
                                <div class="text-[11px] font-semibold tracking-[0.18em] text-muted-foreground uppercase">
                                    Flow
                                </div>
                                <div class="mt-2 text-sm font-medium text-foreground sm:text-base">
                                    {{ $flow->name }}
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    Flow #{{ $flow->id }}
                                </div>
                            </div>

                            <div class="rounded-2xl border border-border bg-background/80 p-4">
                                <div class="text-[11px] font-semibold tracking-[0.18em] text-muted-foreground uppercase">
                                    Request format
                                </div>
                                <div class="mt-2 text-sm font-medium text-foreground sm:text-base">
                                    <code>application/json</code>
                                </div>
                                <div class="mt-1 text-xs text-muted-foreground">
                                    POST the raw JSON body to this exact URL.
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 rounded-2xl border border-border bg-muted/35 p-4">
                            <div class="text-[11px] font-semibold tracking-[0.18em] text-muted-foreground uppercase">
                                POST URL
                            </div>
                            <code class="mt-2 block break-all text-sm leading-6 text-foreground">
                                {{ $endpoint }}
                            </code>
                        </div>

                        <form id="webhook-form" class="mt-8 grid gap-4">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <label for="payload" class="text-sm font-semibold text-foreground">
                                    JSON payload
                                </label>
                                <span class="text-xs text-muted-foreground">
                                    Empty body is sent as <code>null</code>.
                                </span>
                            </div>

                            <textarea
                                id="payload"
                                name="payload"
                                spellcheck="false"
                                class="min-h-[320px] w-full rounded-2xl border border-input bg-background px-4 py-3 font-mono text-sm leading-6 text-foreground shadow-xs transition outline-none placeholder:text-muted-foreground focus:border-ring focus:ring-4 focus:ring-ring/15"
                            >{{ $samplePayload }}</textarea>

                            <div class="flex flex-wrap items-center gap-3">
                                <button
                                    id="submit-button"
                                    type="submit"
                                    class="inline-flex items-center justify-center rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground transition hover:opacity-92 focus:ring-4 focus:ring-ring/20 focus:outline-none"
                                >
                                    Send webhook
                                </button>
                                <span class="text-xs text-muted-foreground">
                                    The page stays open and prints the immediate HTTP response below.
                                </span>
                            </div>
                        </form>
                    </div>

                    <aside class="grid gap-6">
                        <section class="rounded-3xl border border-border/80 bg-card/95 p-6 shadow-sm backdrop-blur">
                            <div class="text-[11px] font-semibold tracking-[0.22em] text-muted-foreground uppercase">
                                What happens next
                            </div>
                            <ol class="mt-4 grid gap-4 text-sm leading-6 text-muted-foreground">
                                <li class="rounded-2xl border border-border bg-muted/35 px-4 py-3">
                                    <strong class="text-foreground">1.</strong>
                                    The browser sends a signed POST request to the webhook endpoint.
                                </li>
                                <li class="rounded-2xl border border-border bg-muted/35 px-4 py-3">
                                    <strong class="text-foreground">2.</strong>
                                    The payload is forwarded into the active flow runtime as a <code>Webhook</code> event.
                                </li>
                                <li class="rounded-2xl border border-border bg-muted/35 px-4 py-3">
                                    <strong class="text-foreground">3.</strong>
                                    The HTTP request returns immediately after the runtime accepts the handoff.
                                </li>
                            </ol>
                        </section>

                        <section class="rounded-3xl border border-border/80 bg-card/95 p-6 shadow-sm backdrop-blur">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-[11px] font-semibold tracking-[0.22em] text-muted-foreground uppercase">
                                        Response
                                    </div>
                                    <p class="mt-2 text-sm text-muted-foreground">
                                        Awaiting request...
                                    </p>
                                </div>
                                <div id="result-badge" class="rounded-full border border-border bg-background px-3 py-1 text-[11px] font-medium text-muted-foreground">
                                    Idle
                                </div>
                            </div>

                            <pre
                                id="result"
                                data-result-state="idle"
                                class="mt-4 min-h-40 overflow-x-auto rounded-2xl border px-4 py-4 font-mono text-xs leading-6 text-foreground"
                            >Awaiting request...</pre>
                        </section>
                    </aside>
                </section>
            </main>
        </div>

        <script>
            const form = document.getElementById('webhook-form');
            const payloadField = document.getElementById('payload');
            const result = document.getElementById('result');
            const resultBadge = document.getElementById('result-badge');
            const submitButton = document.getElementById('submit-button');

            const setResultState = (state, label, text) => {
                result.dataset.resultState = state;
                resultBadge.textContent = label;
                result.textContent = text;
            };

            form.addEventListener('submit', async (event) => {
                event.preventDefault();

                const rawPayload = payloadField.value.trim();

                try {
                    JSON.parse(rawPayload || 'null');
                } catch (error) {
                    setResultState('error', 'Invalid JSON', `Invalid JSON: ${error.message}`);
                    return;
                }

                submitButton.setAttribute('disabled', 'disabled');
                submitButton.classList.add('opacity-70');
                setResultState('sending', 'Sending', 'Sending...');

                try {
                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                        },
                        body: rawPayload || 'null',
                    });

                    const body = await response.text();
                    const responseText = `${response.status} ${response.statusText}\n${body}`;

                    setResultState(
                        response.ok ? 'success' : 'error',
                        response.ok ? 'Accepted' : 'Failed',
                        responseText,
                    );
                } catch (error) {
                    setResultState(
                        'error',
                        'Network error',
                        error instanceof Error ? error.message : 'Request failed.',
                    );
                } finally {
                    submitButton.removeAttribute('disabled');
                    submitButton.classList.remove('opacity-70');
                }
            });
        </script>
    </body>
</html>
