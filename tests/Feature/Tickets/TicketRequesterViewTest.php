<?php

use App\Enums\TicketPriority;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Visão restrita do solicitante (RF-24): sem identificador na listagem, prioridade, SLA, solicitante
 * e auditoria, em listagem, detalhe, filtros, ordenação, exportação, dashboard e rotas relacionadas.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza']);
    $this->tech = User::factory()->withRole('technician')->create();
    $this->manager = User::factory()->withRole('manager')->create();

    $this->own = Ticket::factory()->create([
        'requester_id' => $this->requester->id,
        'title' => 'Meu chamado',
        'priority' => TicketPriority::Critical,
        'resolution_due_at' => now()->subHour(),
        'sla_resolution_minutes' => 60,
    ]);
    $this->foreign = Ticket::factory()->create(['title' => 'Chamado de outra pessoa']);
});

function requesterProps(TestResponse $response): array
{
    return $response->viewData('page')['props'];
}

test('the listing only carries the fields the requester may see', function () {
    $props = requesterProps($this->actingAs($this->requester)->get('/tickets')->assertOk());

    expect($props['restricted'])->toBeTrue()
        ->and($props['tickets']['total'])->toBe(1)
        ->and($props['tickets']['data'][0]['title'])->toBe('Meu chamado')
        ->and(array_keys($props['tickets']['data'][0]))->not->toContain('requester', 'priority', 'sla')
        ->and(array_keys($props['options']))->not->toContain('requesters', 'priorities', 'slaStates')
        // O corpo inteiro da resposta também não carrega os valores restritos.
        ->and(json_encode($props))->not->toContain('Crítica')->not->toContain('Chamado de outra pessoa');
});

test('support users keep requester, priority and SLA in the listing', function () {
    $props = requesterProps($this->actingAs($this->manager)->get('/tickets')->assertOk());

    expect($props['restricted'])->toBeFalse()
        ->and(array_keys($props['tickets']['data'][0]))->toContain('requester', 'priority', 'sla')
        ->and(array_keys($props['options']))->toContain('requesters', 'priorities', 'slaStates');
});

test('the requester cannot use restricted filters or sorts, even with forged parameters', function (string $query) {
    $this->actingAs($this->requester)
        ->from('/tickets')
        ->get("/tickets?{$query}")
        ->assertRedirect('/tickets')
        ->assertSessionHasErrors();
})->with([
    'solicitante' => 'requester=1',
    'prioridade' => 'priority=critical',
    'SLA' => 'sla=overdue',
    'identificador' => 'number=HD-AAAAAAAA',
    'ordenar por prioridade' => 'sort=priority',
    'ordenar por identificador' => 'sort=number',
]);

test('manipulated parameters never list tickets from other people', function () {
    $foreignNumber = $this->foreign->ticket_number;

    $queries = [
        "q={$foreignNumber}",
        'title='.urlencode('Chamado de outra pessoa'),
        "department={$this->foreign->department_id}",
        "category={$this->foreign->category_id}",
    ];

    foreach ($queries as $query) {
        $props = requesterProps($this->actingAs($this->requester)->get('/tickets?'.$query));

        expect(collect($props['tickets']['data'])->pluck('title')->all())->not->toContain('Chamado de outra pessoa');
    }
});

test('the requester can still filter by status, period and title', function () {
    $this->actingAs($this->requester)
        ->get('/tickets?status='.$this->own->status->value.'&title=Meu&opened_from='.now()->subDay()->toDateString().'&sort=title')
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page->where('tickets.total', 1));
});

test('the details page hides requester, priority and SLA from the requester', function () {
    $this->actingAs($this->requester)
        ->get("/tickets/{$this->own->ticket_number}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('ticket.requester')
            ->missing('ticket.priority')
            ->where('sla', null)
            ->where('can.viewAudit', false));
});

test('the requester cannot read the SLA history nor the audit of their own ticket', function () {
    $this->actingAs($this->requester)->getJson("/tickets/{$this->own->ticket_number}/sla-history")->assertForbidden();
    $this->actingAs($this->requester)->getJson("/tickets/{$this->own->ticket_number}/audit")->assertForbidden();
});

test('the requester still sees the public conversation but never internal notes', function () {
    TicketComment::factory()->create(['ticket_id' => $this->own->id, 'body' => 'Resposta pública']);
    TicketComment::factory()->internal()->create(['ticket_id' => $this->own->id, 'body' => 'Nota restrita']);

    $props = requesterProps($this->actingAs($this->requester)->get("/tickets/{$this->own->ticket_number}"));

    expect(collect($props['comments'])->pluck('body')->all())->toBe(['Resposta pública'])
        ->and(json_encode($props))->not->toContain('Nota restrita');
});

test('the export for the requester has no identifier, requester, priority or SLA columns', function () {
    $content = $this->actingAs($this->requester)->get('/tickets/export')->assertOk()->streamedContent();

    expect($content)->toContain('Título')->toContain('Meu chamado')
        ->not->toContain('Identificador')->not->toContain('Solicitante')->not->toContain('Prioridade')->not->toContain('SLA')
        ->not->toContain($this->own->ticket_number)->not->toContain('Chamado de outra pessoa');
});

test('the requester has no overview while support users keep it with priority and SLA', function () {
    $this->actingAs($this->requester)->get('/dashboard')->assertRedirect('/tickets');

    $support = requesterProps($this->actingAs($this->manager)->get('/dashboard'));

    expect($support['metrics']['byPriority'])->not->toBeNull()
        ->and($support['metrics']['sla'])->not->toBeNull();
});

test('the opening form no longer offers the priority', function () {
    $this->actingAs($this->requester)->get('/tickets/create')->assertOk()->assertInertia(fn (Assert $page) => $page->missing('priorities'));
});
