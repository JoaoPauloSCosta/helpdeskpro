<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Models\User;
use App\Support\TicketCsvExport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = User::factory()->withRole('manager')->create();
});

/** Linhas do CSV (sem o BOM), cada uma como lista de células. */
function csvRows(TestResponse $response): array
{
    $content = $response->streamedContent();
    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue();

    $stream = fopen('php://temp', 'w+');
    fwrite($stream, substr($content, 3));
    rewind($stream);

    $rows = [];
    while (($row = fgetcsv($stream, null, ';', '"', '')) !== false) {
        $rows[] = $row;
    }

    return $rows;
}

/** Identificadores exportados, na ordem do arquivo. */
function exportedNumbers(TestResponse $response): array
{
    return array_column(array_slice(csvRows($response), 1), 0);
}

/* ---------- Acesso ---------- */

test('guests are redirected to login', function () {
    $this->get('/tickets/export')->assertRedirect('/login');
});

test('inactive users are denied', function () {
    Ticket::factory()->create();

    $this->actingAs(User::factory()->withRole('manager')->inactive()->create())
        ->get('/tickets/export')
        ->assertForbidden();
});

test('json requests from denied users get 403 without data', function () {
    $ticket = Ticket::factory()->create(['title' => 'Segredo']);

    $response = $this->actingAs(User::factory()->withRole('manager')->inactive()->create())
        ->getJson('/tickets/export');

    $response->assertForbidden();
    expect($response->getContent())->not->toContain('Segredo')->not->toContain($ticket->ticket_number);
});

test('the export route is not shadowed by the ticket detail route', function () {
    Ticket::factory()->create();

    $this->actingAs($this->manager)->get('/tickets/export')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

/* ---------- Escopo de acesso ---------- */

test('users only export the tickets they can see', function () {
    $technician = User::factory()->withRole('technician')->create();
    $requester = User::factory()->withRole('requester')->create();
    $own = Ticket::factory()->create(['requester_id' => $requester->id]);
    $assigned = Ticket::factory()->create(['assigned_to' => $technician->id, 'status' => TicketStatus::InProgress]);
    $other = Ticket::factory()->create(['status' => TicketStatus::InProgress, 'assigned_to' => User::factory()->withRole('technician')->create()->id]);

    expect(exportedNumbers($this->actingAs($requester)->get('/tickets/export')))->toBe([$own->ticket_number])
        // O técnico também enxerga os abertos e sem responsável (autoatribuição, RF-09), como na listagem.
        ->and(exportedNumbers($this->actingAs($technician)->get('/tickets/export')))->toEqualCanonicalizing([$assigned->ticket_number, $own->ticket_number])
        ->and(exportedNumbers($this->actingAs($technician)->get('/tickets/export')))->not->toContain($other->ticket_number)
        ->and(exportedNumbers($this->actingAs($this->manager)->get('/tickets/export')))->toContain($own->ticket_number, $assigned->ticket_number, $other->ticket_number);
});

test('filters cannot widen the scope: filtering by another user adds nothing', function () {
    $requester = User::factory()->withRole('requester')->create();
    $foreign = Ticket::factory()->create();
    Ticket::factory()->create(['requester_id' => $requester->id]);

    $response = $this->actingAs($requester)->get('/tickets/export?'.http_build_query(['requester' => $foreign->requester_id]));

    $response->assertNoContent();
});

/* ---------- Filtros ---------- */

test('every listing filter applies to the export', function (array $query, string $expected) {
    $department = Department::factory()->create();
    $category = TicketCategory::factory()->create();
    $assignee = User::factory()->withRole('technician')->create();

    $match = Ticket::factory()->create([
        'ticket_number' => 'HD-ALVOALVO',
        'title' => 'Impressora quebrada',
        'priority' => TicketPriority::Critical,
        'status' => TicketStatus::InProgress,
        'department_id' => $department->id,
        'category_id' => $category->id,
        'assigned_to' => $assignee->id,
        'created_at' => '2026-10-10 12:00:00',
    ]);
    Ticket::factory()->create(['ticket_number' => 'HD-OUTROXXX', 'title' => 'Outro assunto', 'created_at' => '2026-09-01 12:00:00']);

    $query = array_map(fn ($value) => is_callable($value) ? $value($match) : $value, $query);

    expect(exportedNumbers($this->actingAs($this->manager)->get('/tickets/export?'.http_build_query($query))))->toBe([$expected]);
})->with([
    'search' => [['q' => 'impressora'], 'HD-ALVOALVO'],
    'number' => [['number' => 'alvoalvo'], 'HD-ALVOALVO'],
    'title' => [['title' => 'quebrada'], 'HD-ALVOALVO'],
    'priority' => [['priority' => 'critical'], 'HD-ALVOALVO'],
    'status' => [['status' => 'in_progress'], 'HD-ALVOALVO'],
    'department' => [['department' => fn (Ticket $t) => $t->department_id], 'HD-ALVOALVO'],
    'category' => [['category' => fn (Ticket $t) => $t->category_id], 'HD-ALVOALVO'],
    'requester' => [['requester' => fn (Ticket $t) => $t->requester_id], 'HD-ALVOALVO'],
    'assignee' => [['assignee' => fn (Ticket $t) => $t->assigned_to], 'HD-ALVOALVO'],
    'period' => [['opened_from' => '2026-10-01', 'opened_to' => '2026-10-31'], 'HD-ALVOALVO'],
]);

test('the export covers every filtered result, not only the current page', function () {
    Ticket::factory()->count(40)->create(['status' => TicketStatus::Open]);
    Ticket::factory()->count(3)->create(['status' => TicketStatus::Closed]);

    $all = $this->actingAs($this->manager)->get('/tickets/export?page=2');
    $filtered = $this->actingAs($this->manager)->get('/tickets/export?status=open&page=3');

    expect(csvRows($all))->toHaveCount(1 + 43)
        ->and(csvRows($filtered))->toHaveCount(1 + 40);
});

test('the export follows the listing order', function () {
    $old = Ticket::factory()->create(['created_at' => '2026-01-01 00:00:00', 'title' => 'B']);
    $new = Ticket::factory()->create(['created_at' => '2026-02-01 00:00:00', 'title' => 'A']);

    expect(exportedNumbers($this->actingAs($this->manager)->get('/tickets/export')))->toBe([$new->ticket_number, $old->ticket_number])
        ->and(exportedNumbers($this->actingAs($this->manager)->get('/tickets/export?sort=created_at&direction=asc')))->toBe([$old->ticket_number, $new->ticket_number])
        ->and(exportedNumbers($this->actingAs($this->manager)->get('/tickets/export?sort=title&direction=asc')))->toBe([$new->ticket_number, $old->ticket_number]);
});

test('invalid filters are rejected', function (array $query) {
    Ticket::factory()->create();

    $this->actingAs($this->manager)->getJson('/tickets/export?'.http_build_query($query))->assertUnprocessable();
    $this->actingAs($this->manager)->get('/tickets/export?'.http_build_query($query))->assertRedirect('/tickets');
})->with([
    'status' => [['status' => 'nope']],
    'priority' => [['priority' => 'nope']],
    'range' => [['opened_from' => '2026-10-10', 'opened_to' => '2026-10-01']],
    'sort' => [['sort' => 'password']],
    'assignee' => [['assignee' => 'abc']],
]);

test('no results answer 204 without a file', function () {
    Ticket::factory()->create(['status' => TicketStatus::Open]);

    $response = $this->actingAs($this->manager)->get('/tickets/export?status=closed');

    $response->assertNoContent();
    expect($response->headers->get('content-disposition'))->toBeNull();
});

/* ---------- Conteúdo ---------- */

test('headers and columns match the approved definition', function () {
    $ticket = Ticket::factory()->create([
        'title' => 'Sem acesso ao sistema',
        'priority' => TicketPriority::High,
        'status' => TicketStatus::Resolved,
        'assigned_to' => User::factory()->withRole('technician')->create(['name' => 'Tiago Técnico'])->id,
        'created_at' => '2026-10-10 12:00:00',
        'updated_at' => '2026-10-11 13:30:00',
        'resolved_at' => '2026-10-11 13:00:00',
        'resolution_due_at' => '2026-10-12 12:00:00',
    ]);

    $rows = csvRows($this->actingAs($this->manager)->get('/tickets/export'));

    expect($rows[0])->toBe(TicketCsvExport::HEADERS)
        ->and($rows[0])->toBe(['Identificador', 'Título', 'Solicitante', 'Departamento', 'Categoria', 'Prioridade', 'Status', 'Responsável', 'Situação do SLA', 'Abertura (UTC)', 'Última atualização (UTC)', 'Resolução (UTC)'])
        ->and($rows[1])->toBe([
            $ticket->ticket_number, 'Sem acesso ao sistema', $ticket->requester->name, $ticket->department->name,
            $ticket->category->name, 'Alta', 'Resolvido', 'Tiago Técnico', 'Cumprido',
            '2026-10-10 12:00:00', '2026-10-11 13:30:00', '2026-10-11 13:00:00',
        ]);
});

test('it sends download headers with a safe, fixed file name', function () {
    Ticket::factory()->create();

    $response = $this->actingAs($this->manager)->get('/tickets/export?title=a&filename=../../etc/passwd');

    expect($response->headers->get('content-type'))->toBe('text/csv; charset=UTF-8')
        ->and($response->headers->get('content-disposition'))->toMatch('/^attachment; filename="chamados-\d{8}-\d{6}\.csv"$/')
        ->and($response->headers->get('cache-control'))->toContain('no-store')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

test('accents are preserved and nulls become empty cells', function () {
    Ticket::factory()->create(['title' => 'Ação não concluída: impressão ç', 'assigned_to' => null]);

    $row = csvRows($this->actingAs($this->manager)->get('/tickets/export'))[1];

    expect($row[1])->toBe('Ação não concluída: impressão ç')
        ->and($row[7])->toBe('')
        ->and($row[11])->toBe('');
});

test('quotes, delimiters and line breaks are escaped and keep one row per ticket', function () {
    $ticket = Ticket::factory()->create(['title' => "Linha 1; \"citada\"\nLinha 2, fim"]);
    Ticket::factory()->create();

    $response = $this->actingAs($this->manager)->get('/tickets/export');
    $rows = csvRows($response);

    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->firstWhere(0, $ticket->ticket_number)[1])->toBe("Linha 1; \"citada\"\nLinha 2, fim")
        ->and(array_unique(array_map('count', $rows)))->toBe([12]);
});

test('a ticket with comments appears once and comment text never leaves the system', function () {
    $ticket = Ticket::factory()->create(['description' => 'DESCRICAO-PRIVADA', 'resolution_summary' => 'SOLUCAO-PRIVADA']);
    TicketComment::factory()->count(3)->create(['ticket_id' => $ticket->id, 'body' => 'NOTA-INTERNA-SECRETA', 'visibility' => 'internal']);

    $response = $this->actingAs($this->manager)->get('/tickets/export');
    $content = $response->streamedContent();

    expect(substr_count($content, $ticket->ticket_number))->toBe(1)
        ->and($content)->not->toContain('NOTA-INTERNA-SECRETA')
        ->and($content)->not->toContain('DESCRICAO-PRIVADA')
        ->and($content)->not->toContain('SOLUCAO-PRIVADA');
});

/* ---------- CSV Injection ---------- */

test('text that a spreadsheet would run as a formula is neutralized', function (string $value) {
    expect(TicketCsvExport::neutralize($value))->toBe("'".$value);
})->with(['=SUM(A1:A9)', '+1+1', '-2+3', '@SUM(A1)', "\t=1+1", "\r=1+1", '  =1+1', '=HYPERLINK("http://x","y")']);

test('ordinary text is not altered', function (string $value) {
    expect(TicketCsvExport::neutralize($value))->toBe($value);
})->with(['Impressora quebrada', 'HD-7K4M9Q2X', 'Erro = 500', 'a+b', 'e-mail @ casa', '2026-10-10 12:00:00', '']);

test('dangerous titles and names are neutralized in the generated file', function () {
    $requester = User::factory()->withRole('requester')->create(['name' => '=cmd|\' /C calc\'!A0']);
    Ticket::factory()->create(['title' => '=HYPERLINK("http://evil.test","clique")', 'requester_id' => $requester->id]);
    Ticket::factory()->create(['title' => '@SUM(1+1)', 'category_id' => TicketCategory::factory()->create(['name' => '+cmd'])->id]);

    $rows = csvRows($this->actingAs($this->manager)->get('/tickets/export'));
    $cells = collect($rows)->skip(1)->flatten()->all();

    expect($cells)->toContain("'=HYPERLINK(\"http://evil.test\",\"clique\")", "'@SUM(1+1)", "'+cmd", "'=cmd|' /C calc'!A0");

    foreach ($cells as $cell) {
        expect($cell)->not->toMatch('/^[=+\-@]/');
    }
});

/* ---------- Falhas e somente leitura ---------- */

test('a failure while generating the file is an error, never a truncated success', function () {
    Ticket::factory()->count(3)->create();
    $this->mock(TicketCsvExport::class)->shouldReceive('write')->andThrow(new RuntimeException('falha interna'));

    $response = $this->actingAs($this->manager)->getJson('/tickets/export');

    $response->assertStatus(500);
    expect($response->headers->get('content-disposition'))->toBeNull()
        ->and($response->headers->get('content-type'))->not->toContain('text/csv');
});

test('exporting does not change any ticket', function () {
    Ticket::factory()->count(2)->create();
    $before = Ticket::query()->orderBy('id')->get()->toArray();

    $this->actingAs($this->manager)->get('/tickets/export')->assertOk()->streamedContent();

    expect(Ticket::query()->orderBy('id')->get()->toArray())->toBe($before);
});

test('only GET is accepted', function () {
    foreach (['post', 'put', 'delete'] as $method) {
        $this->actingAs($this->manager)->{$method}('/tickets/export')->assertStatus(405);
    }
});

test('the number of queries does not grow with the number of tickets', function () {
    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->manager)->get('/tickets/export')->assertOk()->streamedContent();

        return count(DB::getQueryLog());
    };

    Ticket::factory()->count(2)->create();
    $count(); // aquecimento: permissões do operador ficam em cache no modelo
    $few = $count();

    Ticket::factory()->count(30)->create();

    expect($count())->toBe($few);
});
