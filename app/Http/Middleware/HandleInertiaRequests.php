<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                // Só controla a exibição da navegação; a autorização real fica no backend.
                'permissions' => fn () => $this->visiblePermissions($request),
            ],
            // Parâmetros do chat para a tela; ausente com o módulo desligado.
            'chat' => fn () => config('chat.enabled') ? [
                'pollInterval' => config('chat.poll_interval'),
                'heartbeatInterval' => config('chat.heartbeat_interval'),
                'messageMaxLength' => config('chat.message_max_length'),
                'subjectMaxLength' => config('chat.subject_max_length'),
            ] : null,
            'flash' => [
                // Identificador do chamado recém-aberto (CHA-02), exibido nos detalhes.
                'ticketCreated' => fn () => $this->flashed($request, 'ticketCreated'),
                // Confirmações gerais, exibidas pela página de destino (ex.: edição na CHA-04).
                'success' => fn () => $this->flashed($request, 'success'),
                'info' => fn () => $this->flashed($request, 'info'),
                'warning' => fn () => $this->flashed($request, 'warning'),
            ],
        ];
    }

    /** Com o módulo do chat desligado, as permissões `chat.*` não chegam à tela (menu e botões somem). */
    private function visiblePermissions(Request $request): array
    {
        $permissions = $request->user()?->effectivePermissions() ?? [];

        return config('chat.enabled')
            ? $permissions
            : array_values(array_filter($permissions, fn (string $slug) => ! str_starts_with($slug, 'chat.')));
    }

    private function flashed(Request $request, string $key): ?string
    {
        return $request->hasSession() ? $request->session()->get($key) : null;
    }
}
