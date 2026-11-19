<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InternalSystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InternalSystemController extends Controller
{
    public function index(): Response
    {
        $systems = InternalSystem::query()
            ->withCount('tickets')
            ->orderByRaw('LOWER(name) ASC')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Systems/Index', [
            'systems' => $systems,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->uniqueCaseInsensitiveNameRule()],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $system = InternalSystem::create([
            'name' => $validated['name'],
            'category' => filled($validated['category'] ?? null) ? $validated['category'] : 'Core Platform',
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return back()->with('success', "System {$system->name} created successfully.");
    }

    public function update(Request $request, InternalSystem $system): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', $this->uniqueCaseInsensitiveNameRule($system->id)],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['required', 'boolean'],
        ]);

        $system->update([
            'name' => $validated['name'],
            'category' => filled($validated['category'] ?? null) ? $validated['category'] : 'Core Platform',
            'description' => $validated['description'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', "System {$system->name} updated successfully.");
    }

    private function uniqueCaseInsensitiveNameRule(?int $ignoreSystemId = null): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($ignoreSystemId): void {
            $normalized = mb_strtolower(trim((string) $value));

            $exists = InternalSystem::query()
                ->whereRaw('LOWER(name) = ?', [$normalized])
                ->when($ignoreSystemId !== null, fn ($query) => $query->where('id', '!=', $ignoreSystemId))
                ->exists();

            if ($exists) {
                $fail(__('validation.unique', ['attribute' => $attribute]));
            }
        };
    }
}
