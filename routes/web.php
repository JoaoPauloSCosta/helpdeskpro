<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PerformanceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SlaPolicyController;
use App\Http\Controllers\TicketAssignmentController;
use App\Http\Controllers\TicketAttachmentController;
use App\Http\Controllers\TicketAuditController;
use App\Http\Controllers\TicketClosingController;
use App\Http\Controllers\TicketCommentController;
use App\Http\Controllers\TicketCommentVersionController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketEditController;
use App\Http\Controllers\TicketExportController;
use App\Http\Controllers\TicketPendingController;
use App\Http\Controllers\TicketReopeningController;
use App\Http\Controllers\TicketResolutionController;
use App\Http\Controllers\TicketSelfAssignmentController;
use App\Http\Controllers\TicketSlaHistoryController;
use App\Http\Controllers\TicketStatusController;
use App\Http\Controllers\UserController;
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

// IND-01 — Visão Geral (RF-20): indicadores restritos ao escopo da listagem de chamados.
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    // EXP-01: exportação CSV (RF-21). Antes de /tickets/{ticket}, para "export" não ser lido como identificador.
    Route::get('/tickets/export', TicketExportController::class)->name('tickets.export');
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
    // Download de anexos (RF-23): autorizado a cada requisição pelo chamado e pela visibilidade da mensagem.
    Route::get('/tickets/{ticket}/attachments/{attachment}', [TicketAttachmentController::class, 'show'])
        ->whereNumber('attachment')
        ->middleware('throttle:60,1')
        ->name('tickets.attachments.show');
    // Somente leitura (CHA-13): períodos de pausa e eventos que afetam o SLA do chamado.
    Route::get('/tickets/{ticket}/sla-history', [TicketSlaHistoryController::class, 'index'])->name('tickets.sla-history.index');
    // Somente leitura (CHA-14): eventos de auditoria do chamado, com `audit.view`.
    Route::get('/tickets/{ticket}/audit', [TicketAuditController::class, 'index'])->name('tickets.audit.index');

    // Administração (ADM-01 a ADM-03): autorizada por `users.manage` no controller e nos form requests.
    // Na edição, o usuário é buscado só depois da autorização geral, para não revelar identificadores.
    Route::get('/admin/users', [UserController::class, 'index'])->name('admin.users.index');
    Route::get('/admin/users/create', [UserController::class, 'create'])->name('admin.users.create');
    Route::post('/admin/users', [UserController::class, 'store'])->name('admin.users.store');
    Route::get('/admin/users/{user}/edit', [UserController::class, 'edit'])->whereNumber('user')->name('admin.users.edit');
    Route::patch('/admin/users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('admin.users.update');
    // ADM-04 a ADM-06: autorizadas por `departments.manage` no controller e nos form requests.
    // Na edição, o departamento é buscado só depois da autorização geral, como na de usuários.
    Route::get('/admin/departments', [DepartmentController::class, 'index'])->name('admin.departments.index');
    Route::get('/admin/departments/create', [DepartmentController::class, 'create'])->name('admin.departments.create');
    Route::post('/admin/departments', [DepartmentController::class, 'store'])->name('admin.departments.store');
    Route::get('/admin/departments/{department}/edit', [DepartmentController::class, 'edit'])->whereNumber('department')->name('admin.departments.edit');
    Route::patch('/admin/departments/{department}', [DepartmentController::class, 'update'])->whereNumber('department')->name('admin.departments.update');
    // ADM-07 a ADM-09: autorizadas por `categories.manage` no controller e nos form requests.
    // Na edição, a categoria é buscada só depois da autorização geral, como nas demais.
    Route::get('/admin/categories', [CategoryController::class, 'index'])->name('admin.categories.index');
    Route::get('/admin/categories/create', [CategoryController::class, 'create'])->name('admin.categories.create');
    Route::post('/admin/categories', [CategoryController::class, 'store'])->name('admin.categories.store');
    Route::get('/admin/categories/{category}/edit', [CategoryController::class, 'edit'])->whereNumber('category')->name('admin.categories.edit');
    Route::patch('/admin/categories/{category}', [CategoryController::class, 'update'])->whereNumber('category')->name('admin.categories.update');
    // PER-01 a PER-03: autorizadas por `roles.manage` no controller e nos form requests.
    // Na edição, o papel é buscado só depois da autorização geral, como nas demais.
    Route::get('/admin/roles', [RoleController::class, 'index'])->name('admin.roles.index');
    Route::get('/admin/roles/create', [RoleController::class, 'create'])->name('admin.roles.create');
    Route::post('/admin/roles', [RoleController::class, 'store'])->name('admin.roles.store');
    Route::get('/admin/roles/{role}/edit', [RoleController::class, 'edit'])->whereNumber('role')->name('admin.roles.edit');
    Route::patch('/admin/roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('admin.roles.update');
    // PER-04: consulta com `roles.manage`; gravação também exige RolePolicy::managePermissions().
    Route::get('/admin/roles/permissions', [RolePermissionController::class, 'edit'])->name('admin.roles.permissions.edit');
    Route::put('/admin/roles/{role}/permissions', [RolePermissionController::class, 'update'])->whereNumber('role')->name('admin.roles.permissions.update');
    // PER-05: somente consulta, com `roles.manage`. Os detalhes abrem na própria listagem (`?selected=`).
    Route::get('/admin/permissions', [PermissionController::class, 'index'])->name('admin.permissions.index');

    // RF-25: painel de desempenho dos técnicos, autorizado por `reports.view_performance` no controller.
    Route::get('/performance', [PerformanceController::class, 'index'])->name('performance.index');

    // AUD-01: consulta de auditoria (RF-19), somente leitura, autorizada por `audit.view` no controller.
    // Os detalhes abrem na própria listagem (`?selected=`).
    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

    // SLA-01 a SLA-04: autorizadas por `sla.manage` no controller e nos form requests.
    // Nos detalhes e na edição, a política é buscada só depois da autorização geral.
    Route::get('/sla-policies', [SlaPolicyController::class, 'index'])->name('sla-policies.index');
    Route::get('/sla-policies/create', [SlaPolicyController::class, 'create'])->name('sla-policies.create');
    Route::post('/sla-policies', [SlaPolicyController::class, 'store'])->name('sla-policies.store');
    // Definida depois de /sla-policies/create e restrita a números, para "create" não ser lido como identificador.
    Route::get('/sla-policies/{policy}', [SlaPolicyController::class, 'show'])->whereNumber('policy')->name('sla-policies.show');
    Route::get('/sla-policies/{policy}/edit', [SlaPolicyController::class, 'edit'])->whereNumber('policy')->name('sla-policies.edit');
    Route::patch('/sla-policies/{policy}', [SlaPolicyController::class, 'update'])->whereNumber('policy')->name('sla-policies.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
