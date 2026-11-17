<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InternalSystem;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class InternalSystemController extends Controller
{
    public function index(): Response
    {
        $systems = InternalSystem::query()
            ->with('defaultLeadEngineer:id,name,username')
            ->withCount('tickets')
            ->orderBy('name')
            ->get();

        $engineers = User::query()
            ->where('is_active', true)
            ->whereIn('role', [User::ROLE_ENGINEER, User::ROLE_SUPERADMIN])
            ->orderBy('name')
            ->get(['id', 'name', 'username', 'role', 'job_title']);

        return Inertia::render('Admin/Systems/Index', [
            'systems' => $systems,
            'engineers' => $engineers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:internal_systems,code'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'default_lead_engineer_id' => ['nullable', 'exists:users,id'],
            'is_active' => ['boolean'],
        ]);

        $system = InternalSystem::create([
            'name' => $validated['name'],
            'code' => strtoupper(trim($validated['code'])),
            'category' => $validated['category'] ?? null,
            'description' => $validated['description'] ?? null,
            'default_lead_engineer_id' => $validated['default_lead_engineer_id'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return back()->with('success', "System {$system->name} created successfully.");
    }

    public function update(Request $request, InternalSystem $system): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('internal_systems', 'code')->ignore($system->id),
            ],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'default_lead_engineer_id' => ['nullable', 'exists:users,id'],
            'is_active' => ['required', 'boolean'],
        ]);

        $system->update([
            'name' => $validated['name'],
            'code' => strtoupper(trim($validated['code'])),
            'category' => $validated['category'] ?? null,
            'description' => $validated['description'] ?? null,
            'default_lead_engineer_id' => $validated['default_lead_engineer_id'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', "System {$system->name} updated successfully.");
    }
}
