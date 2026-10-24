<?php

use App\Actions\Tickets\ResolveTicket;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Integração da v1.8 (RF-23/24/25) com os três perfis ao mesmo tempo: solicitante, técnico e gestor
 * percorrem o ciclo do chamado (abertura, anexos público e interno, resolução, reabertura) e cada um
 * recebe apenas o que o seu perfil autoriza, em dados, rotas diretas e downloads.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('attachments');

    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza', 'department_id' => Department::factory()->create()->id]);
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->manager = User::factory()->withRole('manager')->create(['name' => 'Marina Souza']);
    $this->stranger = User::factory()->withRole('requester')->create(['name' => 'Bruno Alves']);

    $this->ticket = Ticket::factory()->create([
        'requester_id' => $this->requester->id,
        'assigned_to' => $this->tech->id,
        'status' => TicketStatus::InProgress,
        'priority' => TicketPriority::High,
        'title' => 'Impressora sem toner',
    ]);
});

function v18Pdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

function v18Props(User $user, Ticket $ticket): array
{
    return test()->actingAs($user)->get("/tickets/{$ticket->ticket_number}")->assertOk()->viewData('page')['props'];
}

function v18Download(User $user, Ticket $ticket, TicketAttachment $attachment)
{
    return test()->actingAs($user)->get("/tickets/{$ticket->ticket_number}/attachments/{$attachment->id}");
}

test('the full lifecycle exposes to each profile only what it may see', function () {
    // Solicitante envia um anexo público; o técnico responde com uma nota interna anexada.
    $this->actingAs($this->requester)->post("/tickets/{$this->ticket->ticket_number}/comments", [
        'body' => 'Segue a foto do erro.', 'attachments' => [v18Pdf('erro-do-solicitante.pdf')],
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->tech)->post("/tickets/{$this->ticket->ticket_number}/comments", [
        'body' => 'Contato com o fornecedor.', 'visibility' => 'internal', 'attachments' => [v18Pdf('contrato-fornecedor.pdf')],
    ])->assertSessionHasNoErrors();

    $public = TicketAttachment::where('original_name', 'erro-do-solicitante.pdf')->sole();
    $internal = TicketAttachment::where('original_name', 'contrato-fornecedor.pdf')->sole();

    $forRequester = json_encode(v18Props($this->requester, $this->ticket));
    $forTech = json_encode(v18Props($this->tech, $this->ticket));
    $forManager = json_encode(v18Props($this->manager, $this->ticket));

    // Visualização: nome, mensagem e metadados do anexo interno só chegam à equipe.
    expect($forRequester)->toContain('erro-do-solicitante.pdf')
        ->not->toContain('contrato-fornecedor.pdf')->not->toContain('Contato com o fornecedor.')
        ->and($forTech)->toContain('contrato-fornecedor.pdf')
        ->and($forManager)->toContain('contrato-fornecedor.pdf');

    // Download direto: solicitante recebe 404 do anexo interno; estranhos ao chamado, 403.
    v18Download($this->requester, $this->ticket, $internal)->assertNotFound();
    v18Download($this->requester, $this->ticket, $public)->assertOk();
    foreach ([$this->tech, $this->manager] as $user) {
        v18Download($user, $this->ticket, $internal)->assertOk();
        v18Download($user, $this->ticket, $public)->assertOk();
    }
    v18Download($this->stranger, $this->ticket, $public)->assertForbidden();
    v18Download($this->stranger, $this->ticket, $internal)->assertForbidden();

    // Resolução (técnico) e reabertura (solicitante) pelo fluxo real.
    app(ResolveTicket::class)($this->tech, $this->ticket, TicketStatus::InProgress, 'Toner substituído.');
    $this->actingAs($this->requester)->post("/tickets/{$this->ticket->ticket_number}/reopening", [
        'justification' => 'A impressora voltou a falhar.', 'expected_status' => TicketStatus::Resolved->value,
    ])->assertSessionHasNoErrors();

    expect($this->ticket->fresh()->status)->toBe(TicketStatus::Open);

    // Depois da reabertura o solicitante continua sem auditoria, SLA, prioridade e anexo interno.
    $this->actingAs($this->requester)->get("/tickets/{$this->ticket->ticket_number}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.viewAudit', false)
            ->where('sla', null)
            ->missing('ticket.priority')
            ->missing('ticket.requester'));
    $this->actingAs($this->requester)->getJson("/tickets/{$this->ticket->ticket_number}/audit")->assertForbidden();
    $this->actingAs($this->requester)->getJson("/tickets/{$this->ticket->ticket_number}/sla-history")->assertForbidden();
    v18Download($this->requester, $this->ticket, $internal)->assertNotFound();

    // O técnico mantém a auditoria e o gestor também, com os anexos registrados.
    foreach ([$this->tech, $this->manager] as $user) {
        $this->actingAs($user)->getJson("/tickets/{$this->ticket->ticket_number}/audit")->assertOk();
    }
});

test('the requester listing carries no audit, SLA or priority data and the overview redirects to it', function () {
    app(ResolveTicket::class)($this->tech, $this->ticket, TicketStatus::InProgress, 'Toner substituído.');

    $this->actingAs($this->requester)->get('/dashboard')->assertRedirect('/tickets');

    foreach (['/tickets'] as $url) {
        $props = json_encode($this->actingAs($this->requester)->get($url)->assertOk()->viewData('page')['props']);

        expect($props)->not->toContain('Alta')->not->toContain('sla_policy')->not->toContain('viewAudit":true');
    }
});

test('the audit history and the performance panel stay closed to the requester on every route', function () {
    $this->actingAs($this->requester)->get('/audit')->assertForbidden();
    $this->actingAs($this->requester)->get('/performance')->assertForbidden();
    $this->actingAs($this->requester)->getJson("/tickets/{$this->ticket->ticket_number}/audit")->assertForbidden();

    // Técnico: audita os chamados que enxerga, mas não vê o painel gerencial nem a auditoria global.
    $this->actingAs($this->tech)->getJson("/tickets/{$this->ticket->ticket_number}/audit")->assertOk();
    $this->actingAs($this->tech)->get('/performance')->assertForbidden();
    $this->actingAs($this->tech)->get('/audit')->assertForbidden();

    $this->actingAs($this->manager)->get('/performance')->assertOk();
    $this->actingAs($this->manager)->get('/audit')->assertOk();
});

test('a message keeps its visibility: there is no route that changes it after creation', function () {
    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']))
        ->filter(fn ($route) => str_contains($route->uri(), 'comments') || str_contains($route->uri(), 'attachments'));

    expect($writes)->toHaveCount(0);

    // E o anexo sempre deriva da mensagem: o servidor não grava visibilidade própria.
    expect(Schema::hasColumn('ticket_attachments', 'visibility'))->toBeFalse();
});
