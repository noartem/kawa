<?php

it('loads a single vite entry point from the root blade template', function () {
    $template = file_get_contents(resource_path('views/app.blade.php'));

    expect($template)
        ->toContain("@vite('resources/js/app.ts')")
        ->not->toContain("resources/js/pages/{\$page['component']}.vue")
        ->not->toContain('fonts.bunny.net');
});

it('does not append asset preload link headers for the inertia web stack', function () {
    $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

    expect($bootstrap)->not->toContain('AddLinkHeadersForPreloadedAssets::class');
});

it('keeps the vite dev server proxy-friendly and ignores editor temp files', function () {
    $config = file_get_contents(base_path('vite.config.ts'));

    expect($config)
        ->toContain('const devServerOrigin = env.VITE_ORIGIN || undefined;')
        ->toContain('const allowedDevOrigins = [')
        ->toContain('...(env.APP_URL ? [env.APP_URL] : []),')
        ->toContain('VITE_USE_POLLING')
        ->toContain("'**/*.swp'")
        ->toContain("'**/*.swo'")
        ->toContain("'**/*~'")
        ->toContain("'**/.#*'")
        ->toContain('refresh: [')
        ->toContain('cors: {')
        ->not->toContain('refresh: true')
        ->not->toContain('clientPort:');
});
