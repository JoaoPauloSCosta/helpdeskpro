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
            ],
            'flash' => [
                // Identificador do chamado recém-aberto (CHA-02), exibido nos detalhes.
                'ticketCreated' => fn () => $this->flashed($request, 'ticketCreated'),
                // Confirmações gerais, exibidas pela página de destino (ex.: edição na CHA-04).
                'success' => fn () => $this->flashed($request, 'success'),
                'info' => fn () => $this->flashed($request, 'info'),
            ],
        ];
    }

    private function flashed(Request $request, string $key): ?string
    {
        return $request->hasSession() ? $request->session()->get($key) : null;
    }
}
