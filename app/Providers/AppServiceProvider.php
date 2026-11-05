<?php

namespace App\Providers;

use App\Models\ChatAgent;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Painel de desempenho (RF-25): permissão própria e visão de todos os departamentos. Ver o painel
        // não autoriza alterar chamados.
        Gate::define('viewPerformance', fn (User $user) => $user->is_active
            && $user->hasPermission('reports.view_performance')
            && $user->hasPermission('tickets.view_all_departments'));

        // Chat (RF-26 a RF-36): cada ação de tela exige a permissão e usuário ativo. O acesso a uma sessão
        // específica é decidido por ChatSessionPolicy.
        foreach ([
            'requestChat' => 'chat.request',
            'viewChatQueue' => 'chat.view_queue',
            'acceptNextChat' => 'chat.accept_next',
            'manageChatAvailability' => 'chat.manage_availability',
            'manageChatCapacity' => 'chat.manage_capacity',
            'viewChatHistory' => 'chat.view_history',
            'viewChatMetrics' => 'chat.view_metrics',
        ] as $ability => $permission) {
            Gate::define($ability, fn (User $user) => $user->is_active && $user->hasPermission($permission));
        }

        // D-C3: sair do sistema desliga a disponibilidade no chat. Sessões em andamento continuam.
        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user !== null) {
                ChatAgent::where('user_id', $event->user->getAuthIdentifier())->update(['is_available' => false]);
            }
        });
    }
}
