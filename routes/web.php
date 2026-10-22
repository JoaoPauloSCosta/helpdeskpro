<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TicketAssignmentController;
use App\Http\Controllers\TicketClosingController;
use App\Http\Controllers\TicketCommentController;
use App\Http\Controllers\TicketCommentVersionController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketEditController;
use App\Http\Controllers\TicketPendingController;
use App\Http\Controllers\TicketReopeningController;
use App\Http\Controllers\TicketResolutionController;
use App\Http\Controllers\TicketSelfAssignmentController;
use App\Http\Controllers\TicketSlaHistoryController;
use App\Http\Controllers\TicketStatusController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// A entrada do sistema é o login; quem já está autenticado vai direto ao dashboard.
Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

// Página de referência dos componentes (docs/design-system.md). Apenas em desenvolvimento.
if (app()->environment('local')) {
    Route::get('/design-system', fn () => Inertia::render('DesignSystem'))->name('design-system');
}

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
    // Definida depois de /tickets/create, para "create" não ser lido como identificador.
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
    Route::get('/tickets/{ticket}/edit', [TicketEditController::class, 'edit'])->name('tickets.edit');
    Route::patch('/tickets/{ticket}', [TicketEditController::class, 'update'])->name('tickets.update');
    Route::put('/tickets/{ticket}/assignee', [TicketAssignmentController::class, 'update'])->name('tickets.assignee.update');
    Route::post('/tickets/{ticket}/self-assignment', [TicketSelfAssignmentController::class, 'store'])->name('tickets.self-assignment.store');
    Route::put('/tickets/{ticket}/status', [TicketStatusController::class, 'update'])->name('tickets.status.update');
    Route::post('/tickets/{ticket}/pending', [TicketPendingController::class, 'store'])->name('tickets.pending.store');
    Route::post('/tickets/{ticket}/resolution', [TicketResolutionController::class, 'store'])->name('tickets.resolution.store');
    Route::post('/tickets/{ticket}/reopening', [TicketReopeningController::class, 'store'])->name('tickets.reopening.store');
    Route::post('/tickets/{ticket}/closing', [TicketClosingController::class, 'store'])->name('tickets.closing.store');
    Route::post('/tickets/{ticket}/comments', [TicketCommentController::class, 'store'])->name('tickets.comments.store');
    // Somente leitura (CHA-12): o comentário é buscado dentro do chamado, depois da autorização.
    Route::get('/tickets/{ticket}/comments/{comment}/versions', [TicketCommentVersionController::class, 'index'])
        ->whereNumber('comment')
        ->name('tickets.comments.versions.index');
    // Somente leitura (CHA-13): períodos de pausa e eventos que afetam o SLA do chamado.
    Route::get('/tickets/{ticket}/sla-history', [TicketSlaHistoryController::class, 'index'])->name('tickets.sla-history.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
