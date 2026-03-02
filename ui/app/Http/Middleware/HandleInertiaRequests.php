<?php

namespace App\Http\Middleware;

use App\Models\Flow;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => app()->getLocale(),
            'locales' => config('app.supported_locales', ['en', 'ru']),
            'csrf_token' => fn () => csrf_token(),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'recentFlows' => fn () => $request->user()
                ? Flow::query()
                    ->select(['id', 'name', 'slug', 'status', 'last_started_at', 'last_finished_at'])
                    ->forUser($request->user())
                    ->latest('updated_at')
                    ->limit(4)
                    ->get()
                : [],
        ];
    }
}
