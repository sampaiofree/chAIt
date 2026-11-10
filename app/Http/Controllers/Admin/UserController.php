<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\AccountEmail;
use App\Rules\InternationalPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);
        $users = User::query()
            ->when($filters['filter'] === 'owners', fn ($query) => $query->whereHas('memberships', fn ($memberships) => $memberships->where('role', WorkspaceRole::Owner->value)))
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $search = '%'.mb_strtolower($filters['search']).'%';
                $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$search])->orWhereRaw('LOWER(email) LIKE ?', [$search]));
            })
            ->with(['memberships.workspace'])
            ->orderBy('name')->orderBy('id')
            ->paginate(20)->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'workspaces' => $user->memberships->filter(fn ($membership) => $membership->workspace !== null)->map(fn ($membership) => [
                    'id' => $membership->workspace->id,
                    'name' => $membership->workspace->name,
                    'role' => $membership->role,
                ])->values(),
            ]);

        return Inertia::render('admin/users', [
            'users' => $users,
            'filters' => $filters,
            'success' => $request->session()->get('success'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateUser($request);
        DB::transaction(fn () => User::query()->create($data));

        return to_route('admin.users.index', $this->filters($request))->with('success', 'Usuário cadastrado com seu workspace.');
    }

    public function edit(Request $request, User $user): Response
    {
        return Inertia::render('admin/user-edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_system_admin' => $user->isSystemAdmin(),
            ],
            'filters' => $this->filters($request),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validateUser($request, $user);
        if (empty($data['password'])) {
            unset($data['password']);
        }
        $emailChanged = strtolower($user->email) !== $data['email'];
        $user->fill($data);
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();

        return to_route('admin.users.index', $this->filters($request))->with('success', 'Usuário atualizado.');
    }

    private function validateUser(Request $request, ?User $user = null): array
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', new AccountEmail($user?->id)],
            'phone' => ['bail', 'required', new InternationalPhone],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::default(), 'confirmed'],
        ]);
    }

    private function filters(Request $request): array
    {
        return [
            'filter' => $request->query('filter') === 'all' ? 'all' : 'owners',
            'search' => is_string($request->query('search')) ? mb_substr(trim($request->query('search')), 0, 255) : '',
        ];
    }
}
