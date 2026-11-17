<?php

namespace App\Http\Middleware;

use App\Models\InternalSystem;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'role' => $user->role,
                    'department' => $user->department,
                    'job_title' => $user->job_title,
                    'is_active' => $user->is_active,
                    'is_super_admin' => $user->isSuperAdmin(),
                    'is_engineer' => $user->isEngineer(),
                    'is_staff' => $user->isStaff(),
                ] : null,
                'unread_notifications_count' => $user
                    ? UserNotification::where('user_id', $user->id)->where('is_read', false)->count()
                    : 0,
                'recent_notifications' => $user
                    ? UserNotification::where('user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->limit(8)
                        ->get(['id', 'ticket_id', 'title', 'message', 'action_url', 'is_read', 'created_at'])
                    : [],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'internalSystems' => fn () => $user
                ? InternalSystem::query()
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
        ];
    }
}
