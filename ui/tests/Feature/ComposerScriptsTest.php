<?php

it('uses a long-running queue worker in local dev scripts', function () {
    $composer = file_get_contents(base_path('composer.json'));

    expect($composer)
        ->toContain('php artisan queue:work --tries=1')
        ->not->toContain('php artisan queue:listen --tries=1');
});
