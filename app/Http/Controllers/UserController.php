<?php

namespace App\Http\Controllers;

use App\Actions\Users\CreateUser;
use App\Actions\Users\UpdateUser;
use App\Actions\Users\UserEditConflict;
use App\Enums\AdminEvent;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Requests\Users\UserIndexRequest;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class UserController extends Controller
{
    private const PER_PAGE = 15;

    /**
     * ADM-01 — Listagem de usuários (RF-02). Usuários inativos continuam listados para
     * preservar as referências históricas; estar na lista não autoriza nada.
     */
    public function index(UserIndexRequest $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $operator = $request->user();
        $filters = $request->filters();
        $sort = $request->sort();
        $direction = $request->direction();

        // Colunas explícitas: senha, tokens e demais campos sensíveis nunca saem do banco.
        $users = User::query()
            ->select(['users.id', 'users.name', 'users.email', 'users.role_id', 'users.department_id', 'users.is_active', 'users.created_at'])
            // As permissões do papel alimentam a autorização da edição (ADM-03) sem consultas por linha.
            ->with(['role:id,name', 'role.permissions:id,slug', 'department:id,name'])
            ->tap(fn (Builder $query) => $this->applyFilters($query, $filters))
            ->tap(fn (Builder $query) => $this->applySort($query, $sort, $direction))
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->name,
                'department' => $user->department?->name,
                'is_active' => $user->is_active,
                'created_at' => $user->created_at?->toIso8601String(),
                // Só a exibição da ação; o backend revalida ao abrir e ao salvar.
                'can_edit' => $operator->can('update', $user),
            ]);

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
            'filters' => $filters,
            'sort' => ['key' => $sort, 'direction' => $direction],
            'options' => $this->filterOptions(),
            'can' => [
                // A ação só aparece quando a criação (ADM-02) existir e for autorizada.
                'create' => Route::has('admin.users.create') && $operator->can('create', User::class),
            ],
        ]);
    }

    /**
     * ADM-02 — Criar usuário (RF-02). Só oferece papéis que o operador pode conceder e
     * departamentos ativos; o StoreUserRequest valida tudo de novo.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Admin/Users/Create', [
            'roles' => $this->roleOptions($request->user()),
            'departments' => Department::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Department $department) => ['value' => (string) $department->id, 'label' => $department->name])
                ->all(),
            'statuses' => $this->statusOptions(),
            'limits' => [
                'name' => StoreUserRequest::NAME_MAX_LENGTH,
                'email' => StoreUserRequest::EMAIL_MAX_LENGTH,
            ],
        ]);
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        try {
            $user = $createUser($request->user(), $request->safe()->only(['name', 'email', 'role_id', 'department_id', 'status']));
        } catch (UniqueConstraintViolationException) {
            // Envio simultâneo com o mesmo e-mail: a restrição do banco é a garantia final.
            throw ValidationException::withMessages(['email' => 'Já existe um usuário cadastrado com este e-mail.']);
        }

        // A listagem abre filtrada no usuário recém-criado.
        $redirect = to_route('admin.users.index', ['q' => $user->email]);

        // Usuário inativo não recebe convite: depois de ativado, pode usar "Esqueci minha senha".
        if (! $user->is_active) {
            return $redirect->with('success', "Usuário {$user->name} criado como inativo. Nenhum convite foi enviado.");
        }

        // O convite vem depois da gravação: uma falha no envio não desfaz o cadastro.
        try {
            $user->notify(new UserInvitation(Password::broker()->createToken($user)));
        } catch (Throwable $e) {
            report($e);

            return $redirect
                ->with('success', "Usuário {$user->name} criado.")
                ->with('warning', 'Não foi possível enviar o e-mail para definição de senha. O usuário pode usar "Esqueci minha senha" na tela de login.');
        }

        return $redirect->with('success', "Usuário {$user->name} criado. Enviamos um e-mail para {$user->email} com o link para definir a senha.");
    }

    /**
     * ADM-03 — Editar usuário (RF-02). A permissão geral é verificada antes da busca, para que
     * quem não administra usuários não descubra quais identificadores existem.
     */
    public function edit(Request $request, string $user): Response
    {
        Gate::authorize('viewAny', User::class);

        $operator = $request->user();
        $target = User::with(['role.permissions:id,slug', 'department:id,name,is_active'])->findOrFail($user);

        Gate::authorize('update', $target);

        $changeAccess = $operator->can('changeAccess', $target);

        return Inertia::render('Admin/Users/Edit', [
            // Somente o necessário para identificar o usuário; nunca senha, hash ou tokens.
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'role' => $target->role?->name,
                'is_active' => $target->is_active,
                'is_self' => $operator->is($target),
                'created_at' => $target->created_at?->toIso8601String(),
            ],
            'values' => [
                'name' => $target->name,
                'email' => $target->email,
                'role_id' => $target->role_id !== null ? (string) $target->role_id : '',
                'department_id' => $target->department_id !== null ? (string) $target->department_id : '',
                'status' => $target->is_active ? 'active' : 'inactive',
            ],
            'options' => [
                'roles' => $changeAccess ? $this->roleOptions($operator) : [],
                'departments' => Department::query()
                    ->where(fn ($query) => $query->where('is_active', true)->orWhere('id', $target->department_id))
                    ->orderBy('name')
                    ->get(['id', 'name', 'is_active'])
                    ->map(fn (Department $department) => [
                        'value' => (string) $department->id,
                        'label' => $department->is_active ? $department->name : "{$department->name} (inativo)",
                    ])
                    ->all(),
                'statuses' => $this->statusOptions(),
            ],
            'limits' => [
                'name' => StoreUserRequest::NAME_MAX_LENGTH,
                'email' => StoreUserRequest::EMAIL_MAX_LENGTH,
            ],
            'can' => ['changeAccess' => $changeAccess],
            'version' => $target->editVersion(),
        ]);
    }

    public function update(UpdateUserRequest $request, UpdateUser $updateUser): RedirectResponse
    {
        $target = $request->target();

        try {
            $events = $updateUser($request->user(), $target, $request->changes(), $request->validated('version'));
        } catch (UserEditConflict) {
            throw ValidationException::withMessages([
                'conflict' => 'Este usuário foi alterado por outra pessoa enquanto você editava. '
                    .'Recarregue os dados atuais antes de tentar novamente.',
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Já existe um usuário cadastrado com este e-mail.']);
        }

        if ($events === []) {
            return to_route('admin.users.edit', $target)->with('info', 'Nenhuma alteração para salvar.');
        }

        $message = match (true) {
            in_array(AdminEvent::UserDeactivated, $events, true) => "Usuário {$target->name} inativado. O histórico foi preservado.",
            in_array(AdminEvent::UserActivated, $events, true) => "Usuário {$target->name} reativado.",
            default => "Alterações de {$target->name} salvas.",
        };

        return to_route('admin.users.index', ['q' => $target->email])->with('success', $message);
    }

    private function roleOptions(User $operator): array
    {
        return StoreUserRequest::grantableRoles($operator)
            ->map(fn (Role $role) => [
                'value' => (string) $role->id,
                'label' => $role->name,
                'description' => $role->description,
            ])
            ->all();
    }

    private function statusOptions(): array
    {
        return collect(UserIndexRequest::STATUSES)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->values()
            ->all();
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['q'])) {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';

            $query->where(fn (Builder $query) => $query
                ->where('users.name', 'like', $like)
                ->orWhere('users.email', 'like', $like));
        }

        foreach (['role' => 'role_id', 'department' => 'department_id'] as $key => $column) {
            if (isset($filters[$key])) {
                $filters[$key] === 'none'
                    ? $query->whereNull("users.$column")
                    : $query->where("users.$column", (int) $filters[$key]);
            }
        }

        if (isset($filters['status'])) {
            $query->where('users.is_active', $filters['status'] === 'active');
        }
    }

    private function applySort(Builder $query, string $sort, string $direction): void
    {
        match ($sort) {
            'name' => $query->orderBy('users.name', $direction),
            'email' => $query->orderBy('users.email', $direction),
            'created_at' => $query->orderBy('users.created_at', $direction),
        };

        // Critério de desempate estável para a paginação.
        $query->orderBy('users.id', $direction);
    }

    /** Opções dos filtros, sempre a partir de dados reais. */
    private function filterOptions(): array
    {
        return [
            'roles' => Role::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Role $role) => ['value' => (string) $role->id, 'label' => $role->name])
                ->all(),
            'departments' => Department::query()
                ->orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (Department $department) => [
                    'value' => (string) $department->id,
                    'label' => $department->is_active ? $department->name : "{$department->name} (inativo)",
                ])
                ->all(),
            'statuses' => $this->statusOptions(),
        ];
    }
}
