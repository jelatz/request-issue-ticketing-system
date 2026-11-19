<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $query = User::query()
            ->withCount(['submittedTickets', 'ledTickets']);

        if ($search = trim((string) $request->input('search', ''))) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $pattern = "%{$escaped}%";
            $query->where(function ($subQuery) use ($pattern): void {
                $subQuery->whereRaw("name LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("username LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("email LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("department LIKE ? ESCAPE '\\'", [$pattern])
                    ->orWhereRaw("job_title LIKE ? ESCAPE '\\'", [$pattern]);
            });
        }

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        if ($request->filled('is_active')) {
            $isActive = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        $users = $query
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'role', 'is_active']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeIdentityInputs($request);

        $validated = $request->validate($this->userValidationRules());

        $user = User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'department' => $validated['department'] ?? null,
            'job_title' => $validated['job_title'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return back()->with('success', "User {$user->name} created successfully.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->normalizeIdentityInputs($request);

        $validated = $request->validate($this->userValidationRules($user));

        if ($user->id === $request->user()->id) {
            if (!$request->boolean('is_active')) {
                throw ValidationException::withMessages([
                    'is_active' => 'You cannot deactivate your own Superadmin account.',
                ]);
            }

            if ($validated['role'] !== $user->role) {
                throw ValidationException::withMessages([
                    'role' => 'You cannot change the role of your own Superadmin account.',
                ]);
            }
        }

        $payload = [
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'department' => $validated['department'] ?? null,
            'job_title' => $validated['job_title'] ?? null,
            'is_active' => $request->boolean('is_active'),
        ];

        if (is_string($validated['password'] ?? null) && trim($validated['password']) !== '') {
            $payload['password'] = $validated['password'];
        }

        $user->update($payload);

        return back()->with('success', "User {$user->name} updated successfully.");
    }

    /**
     * @return array<string, mixed>
     */
    private function userValidationRules(?User $existingUser = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                $this->uniqueCaseInsensitiveRule('username', $existingUser?->id),
            ],
            'email' => [
                'required',
                'string',
                'email:rfc,filter',
                'max:255',
                $this->uniqueCaseInsensitiveRule('email', $existingUser?->id),
            ],
            'password' => [
                $existingUser ? 'nullable' : 'required',
                'string',
                'min:6',
                $this->nonWhitespacePasswordRule(),
            ],
            'role' => ['required', 'in:superadmin,engineer,user'],
            'department' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'is_active' => $existingUser ? ['required', 'boolean'] : ['boolean'],
        ];
    }

    private function normalizeIdentityInputs(Request $request): void
    {
        $normalized = [];

        foreach (['username', 'email'] as $field) {
            $value = $request->input($field);
            if (is_string($value)) {
                $normalized[$field] = mb_strtolower(trim($value));
            }
        }

        if ($normalized !== []) {
            $request->merge($normalized);
        }
    }

    private function uniqueCaseInsensitiveRule(string $column, ?int $ignoreUserId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($column, $ignoreUserId): void {
            $normalized = mb_strtolower(trim((string) $value));

            $exists = User::query()
                ->whereRaw("LOWER({$column}) = ?", [$normalized])
                ->when($ignoreUserId !== null, fn ($query) => $query->where('id', '!=', $ignoreUserId))
                ->exists();

            if ($exists) {
                $fail(__('validation.unique', ['attribute' => $attribute]));
            }
        };
    }

    private function nonWhitespacePasswordRule(): ValidationRule
    {
        return new class implements ValidationRule {
            public bool $implicit = true;

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (is_string($value) && $value !== '' && trim($value) === '') {
                    $fail('The password cannot consist only of whitespace.');
                }
            }
        };
    }
}
