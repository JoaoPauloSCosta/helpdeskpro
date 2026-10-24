<?php

namespace App\Providers;

use App\Models\User;
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
    }
}
