<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        // Só departamentos ativos (RF-22, regra 2), apenas com os campos que o formulário usa.
        return Inertia::render('Auth/Register', [
            'departments' => Department::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'department_id' => [
                'required',
                'integer',
                Rule::exists('departments', 'id')->where('is_active', true),
            ],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], [
            'department_id.required' => 'Selecione seu departamento.',
            'department_id.integer' => 'Selecione um departamento válido.',
            'department_id.exists' => 'O departamento selecionado não está disponível. Escolha outro.',
        ], [
            'department_id' => 'departamento',
        ]);

        // O papel não vem da requisição (RF-01): todo cadastro público entra como Solicitante.
        // `role_id` e `department_id` não são mass assignable em User, por isso a atribuição explícita;
        // o departamento é só o vínculo organizacional e já foi validado como existente e ativo.
        $user = (new User)->forceFill([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'department_id' => $request->integer('department_id'),
            'role_id' => Role::where('slug', Role::SELF_REGISTRATION_SLUG)->valueOrFail('id'),
        ]);
        $user->save();

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
