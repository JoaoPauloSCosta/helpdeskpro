<?php

use App\Actions\Tickets\StoreTicketAttachments;
use App\Enums\CommentVisibility;
use App\Enums\TicketEvent;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketCategory;
use App\Models\TicketComment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Anexos (RF-23): validação pelo conteúdo, armazenamento privado, herança de visibilidade (RN-21),
 * download autorizado a cada requisição e limpeza de arquivos quando a operação falha.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('attachments');

    $this->requester = User::factory()->withRole('requester')->create(['name' => 'Ana Souza', 'department_id' => Department::factory()->create()->id]);
    $this->tech = User::factory()->withRole('technician')->create(['name' => 'Carlos Lima']);
    $this->manager = User::factory()->withRole('manager')->create();
    $this->ticket = Ticket::factory()->create([
        'requester_id' => $this->requester->id,
        'assigned_to' => $this->tech->id,
        'status' => TicketStatus::InProgress,
    ]);
});

/* ---------- Arquivos de teste ---------- */

function pdfFile(string $name = 'laudo.pdf', string $extra = ''): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n{$extra}\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

function pngFile(string $name = 'foto.png'): UploadedFile
{
    return UploadedFile::fake()->image($name, 20, 20);
}

function textFile(string $name = 'notas.txt', string $content = "Linha de texto simples.\n"): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

/** Pacote DOCX/XLSX mínimo; `$extraEntries` permite acrescentar partes (macros, scripts). */
function officeFile(string $name, string $folder, array $extraEntries = []): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'off');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
    $zip->addFromString("{$folder}/document.xml", '<root/>');
    foreach ($extraEntries as $entry => $content) {
        $zip->addFromString($entry, $content);
    }
    $zip->close();

    $file = UploadedFile::fake()->createWithContent($name, file_get_contents($path));
    unlink($path);

    return $file;
}

function commentUrl(Ticket $ticket): string
{
    return "/tickets/{$ticket->ticket_number}/comments";
}

function postComment(User $user, Ticket $ticket, array $files = [], array $extra = [])
{
    return test()->actingAs($user)->post(commentUrl($ticket), ['body' => 'Segue o arquivo.', 'attachments' => $files, ...$extra]);
}

function diskFiles(): array
{
    return Storage::disk('attachments')->allFiles();
}

function downloadUrl(Ticket $ticket, TicketAttachment|int $attachment): string
{
    return "/tickets/{$ticket->ticket_number}/attachments/".($attachment instanceof TicketAttachment ? $attachment->id : $attachment);
}

/* ---------- Envio em mensagens ---------- */

test('an authorized user attaches allowed files to a public comment', function () {
    postComment($this->requester, $this->ticket, [
        pngFile(), pdfFile(), textFile(), officeFile('plano.docx', 'word'), officeFile('dados.xlsx', 'xl'),
    ])->assertSessionHasNoErrors();

    $comment = TicketComment::sole();
    $attachments = TicketAttachment::orderBy('id')->get();

    expect($attachments)->toHaveCount(5)
        ->and($attachments->pluck('original_name')->all())->toBe(['foto.png', 'laudo.pdf', 'notas.txt', 'plano.docx', 'dados.xlsx'])
        ->and($attachments->every(fn ($a) => $a->ticket_comment_id === $comment->id && $a->uploaded_by === $this->requester->id && $a->ticket_id === $this->ticket->id))->toBeTrue()
        ->and($attachments->every(fn ($a) => strlen($a->sha256) === 64 && $a->size_bytes > 0))->toBeTrue()
        ->and($attachments[3]->mime_type)->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document')
        ->and($attachments[4]->mime_type)->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and(diskFiles())->toHaveCount(5);

    foreach ($attachments as $attachment) {
        // Nome de armazenamento gerado pelo sistema, nunca o enviado pelo usuário.
        expect($attachment->storage_path)->toMatch('#^tickets/'.$this->ticket->id.'/[0-9a-f-]{36}\.(png|pdf|txt|docx|xlsx)$#');
        Storage::disk('attachments')->assertExists($attachment->storage_path);
    }
});

test('the storage name never uses the name sent by the user', function () {
    postComment($this->requester, $this->ticket, [pdfFile('../../etc/passwd..\\evil name.pdf')])->assertSessionHasNoErrors();

    $attachment = TicketAttachment::sole();

    expect($attachment->original_name)->toBe('evil name.pdf')
        ->and($attachment->storage_path)->not->toContain('passwd')->not->toContain('evil')
        ->and(diskFiles())->toHaveCount(1);
});

test('an internal note keeps its attachments restricted to the support team and records the event', function () {
    postComment($this->tech, $this->ticket, [pdfFile()], ['visibility' => 'internal'])->assertSessionHasNoErrors();

    $comment = TicketComment::sole();
    $event = $this->ticket->histories()->where('event_type', TicketEvent::AttachmentAdded)->sole();

    expect($comment->visibility)->toBe(CommentVisibility::Internal)
        ->and($event->new_values['visibility'])->toBe('internal')
        ->and($event->new_values['comment_id'])->toBe($comment->id)
        ->and($event->new_values['files'][0]['name'])->toBe('laudo.pdf')
        ->and(json_encode($event->new_values))->not->toContain('tickets/');
});

test('the requester cannot attach to an internal note', function () {
    postComment($this->requester, $this->ticket, [pdfFile()], ['visibility' => 'internal'])->assertForbidden();

    expect(TicketAttachment::count())->toBe(0)->and(diskFiles())->toBe([]);
});

test('a message without files still works and records no attachment event', function () {
    $this->actingAs($this->requester)->post(commentUrl($this->ticket), ['body' => 'Só texto.'])->assertSessionHasNoErrors();

    expect(TicketComment::count())->toBe(1)
        ->and($this->ticket->histories()->where('event_type', TicketEvent::AttachmentAdded)->count())->toBe(0);
});

/* ---------- Recusas ---------- */

test('files that are not allowed are rejected and nothing is stored', function (string $kind) {
    $file = match ($kind) {
        'svg' => textFile('desenho.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        'html' => textFile('pagina.html', '<html><body>oi</body></html>'),
        'js' => textFile('script.js', 'alert(1);'),
        'php' => textFile('shell.php', '<?php system($_GET["c"]);'),
        'exe' => UploadedFile::fake()->createWithContent('programa.exe', "MZ\x90\x00binario"),
        'sh' => textFile('rodar.sh', "#!/bin/sh\nrm -rf /\n"),
        'sem-extensao' => textFile('arquivo', 'texto'),
        'dupla-extensao' => textFile('foto.png.php', 'texto'),
        'png-que-e-html' => textFile('foto.png', '<html><script>alert(1)</script></html>'),
        'pdf-que-e-texto' => textFile('laudo.pdf', 'isto não é um pdf'),
        'txt-com-html' => textFile('notas.txt', '<script>alert(1)</script> texto'),
        'txt-com-php' => textFile('notas.txt', '<?php echo 1;'),
        'csv-binario' => UploadedFile::fake()->createWithContent('dados.csv', "a;b\0\0\x01"),
        'pdf-com-javascript' => pdfFile('laudo.pdf', '2 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj'),
        'pdf-com-acao-automatica' => pdfFile('laudo.pdf', '/OpenAction << /S /Launch >>'),
        'docx-com-macro' => officeFile('plano.docx', 'word', ['word/vbaProject.bin' => 'macro']),
        'xlsx-com-objeto' => officeFile('dados.xlsx', 'xl', ['xl/embeddings/obj1.bin' => 'x']),
        'docx-que-e-zip-qualquer' => UploadedFile::fake()->createWithContent('plano.docx', 'PK'.str_repeat("\0", 30)),
    };

    postComment($this->requester, $this->ticket, [$file])->assertSessionHasErrors('attachments.0');

    expect(TicketAttachment::count())->toBe(0)
        ->and(TicketComment::count())->toBe(0)
        ->and(diskFiles())->toBe([]);
})->with([
    'svg', 'html', 'js', 'php', 'exe', 'sh', 'sem-extensao', 'dupla-extensao', 'png-que-e-html', 'pdf-que-e-texto',
    'txt-com-html', 'txt-com-php', 'csv-binario', 'pdf-com-javascript', 'pdf-com-acao-automatica', 'docx-com-macro',
    'xlsx-com-objeto', 'docx-que-e-zip-qualquer',
]);

test('the extension and the type reported by the browser are not trusted', function () {
    // O navegador diz "image/png" e o nome termina em .png, mas o conteúdo é um PDF.
    $disguised = UploadedFile::fake()->createWithContent('foto.png', "%PDF-1.4\n%%EOF\n");

    postComment($this->requester, $this->ticket, [$disguised])->assertSessionHasErrors('attachments.0');

    expect(diskFiles())->toBe([]);
});

test('files above the size limit are rejected', function () {
    $tooBig = UploadedFile::fake()->create('grande.pdf', config('attachments.max_file_kb') + 1, 'application/pdf');

    postComment($this->requester, $this->ticket, [$tooBig])->assertSessionHasErrors('attachments.0');

    expect(diskFiles())->toBe([]);
});

test('more files than the limit per upload are rejected', function () {
    $files = array_map(fn ($i) => pdfFile("laudo{$i}.pdf"), range(1, config('attachments.max_files_per_upload') + 1));

    postComment($this->requester, $this->ticket, $files)->assertSessionHasErrors('attachments');

    expect(TicketAttachment::count())->toBe(0)->and(diskFiles())->toBe([]);
});

test('the sum of an upload cannot pass the limit', function () {
    config(['attachments.max_upload_kb' => 100]);
    $files = [
        UploadedFile::fake()->createWithContent('a.txt', str_repeat('a', 60 * 1024)),
        UploadedFile::fake()->createWithContent('b.txt', str_repeat('b', 60 * 1024)),
    ];

    postComment($this->requester, $this->ticket, $files)->assertSessionHasErrors('attachments');

    expect(diskFiles())->toBe([]);
});

test('the ticket quota stops uploads and leaves no comment nor file behind', function () {
    config(['attachments.max_files_per_ticket' => 2]);

    postComment($this->requester, $this->ticket, [pdfFile('a.pdf'), pdfFile('b.pdf')])->assertSessionHasNoErrors();
    postComment($this->requester, $this->ticket, [pdfFile('c.pdf')])->assertSessionHasErrors('attachments');

    // O comentário do envio recusado foi desfeito junto, e o arquivo gravado foi removido do disco.
    expect(TicketAttachment::count())->toBe(2)
        ->and(TicketComment::count())->toBe(1)
        ->and(diskFiles())->toHaveCount(2);
});

test('the ticket size quota also applies', function () {
    config(['attachments.max_ticket_kb' => 1]);

    postComment($this->requester, $this->ticket, [textFile('a.txt', str_repeat('a', 2048))])->assertSessionHasErrors('attachments');

    expect(diskFiles())->toBe([]);
});

test('purge removes files already written when an operation fails', function () {
    $disk = Storage::disk('attachments');
    $disk->put('tickets/1/a.pdf', 'x');
    $disk->put('tickets/1/b.pdf', 'x');

    StoreTicketAttachments::purge(['tickets/1/a.pdf', 'tickets/1/b.pdf']);

    expect(diskFiles())->toBe([]);
});

/* ---------- Abertura do chamado ---------- */

test('the requester attaches files when opening a ticket and they are public', function () {
    $category = TicketCategory::factory()->create();

    $this->actingAs($this->requester)->post('/tickets', [
        'title' => 'Computador sem rede',
        'description' => 'Veja a foto.',
        'category_id' => $category->id,
        'submission_token' => (string) Str::uuid(),
        'attachments' => [pngFile(), pdfFile()],
    ])->assertSessionHasNoErrors();

    $ticket = Ticket::where('title', 'Computador sem rede')->sole();
    $attachments = TicketAttachment::where('ticket_id', $ticket->id)->get();

    expect($attachments)->toHaveCount(2)
        ->and($attachments->every(fn ($a) => $a->ticket_comment_id === null))->toBeTrue()
        ->and($ticket->histories()->where('event_type', TicketEvent::AttachmentAdded)->sole()->new_values['visibility'])->toBe('public');

    $this->actingAs($this->requester)->get("/tickets/{$ticket->ticket_number}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('attachments', 2)
            ->where('attachments.0.name', 'foto.png')
            ->missing('attachments.0.storage_path'));
});

test('an invalid file on opening creates no ticket', function () {
    $category = TicketCategory::factory()->create();
    $before = Ticket::count();

    $this->actingAs($this->requester)->post('/tickets', [
        'title' => 'Com anexo ruim',
        'description' => 'x',
        'category_id' => $category->id,
        'submission_token' => (string) Str::uuid(),
        'attachments' => [textFile('x.html', '<html></html>')],
    ])->assertSessionHasErrors('attachments.0');

    expect(Ticket::count())->toBe($before)->and(diskFiles())->toBe([]);
});

/* ---------- Visibilidade e download ---------- */

function publicAndInternalAttachments(): array
{
    $public = TicketComment::factory()->create(['ticket_id' => test()->ticket->id, 'user_id' => test()->tech->id]);
    $internal = TicketComment::factory()->internal()->create(['ticket_id' => test()->ticket->id, 'user_id' => test()->tech->id]);
    $make = function (TicketComment $comment, string $name) {
        $path = "tickets/{$comment->ticket_id}/".Str::uuid().'.pdf';
        Storage::disk('attachments')->put($path, "%PDF-1.4 conteudo de {$name}");

        return (new TicketAttachment)->forceFill([
            'ticket_id' => $comment->ticket_id, 'ticket_comment_id' => $comment->id, 'uploaded_by' => $comment->user_id,
            'original_name' => $name, 'storage_path' => $path, 'mime_type' => 'application/pdf', 'size_bytes' => 40, 'sha256' => str_repeat('a', 64),
        ]);
    };

    $a = $make($public, 'publico.pdf');
    $a->save();
    $b = $make($internal, 'interno.pdf');
    $b->save();

    return [$a, $b];
}

test('a public attachment is downloaded by users with access to the ticket, as a file to save', function () {
    [$public] = publicAndInternalAttachments();

    foreach ([$this->requester, $this->tech, $this->manager] as $user) {
        $response = $this->actingAs($user)->get(downloadUrl($this->ticket, $public))->assertOk();

        expect($response->headers->get('content-disposition'))->toContain('attachment')->toContain('publico.pdf')
            ->and($response->headers->get('x-content-type-options'))->toBe('nosniff')
            ->and($response->headers->get('content-security-policy'))->toContain("default-src 'none'")
            ->and($response->headers->get('cache-control'))->toContain('private')->toContain('no-store')
            ->and($response->headers->get('content-type'))->toContain('application/pdf');
    }
});

test('an internal note attachment is never reachable by the requester, even with the direct address', function () {
    [, $internal] = publicAndInternalAttachments();

    $this->actingAs($this->requester)->get(downloadUrl($this->ticket, $internal))->assertNotFound();

    foreach ([$this->tech, $this->manager] as $user) {
        $this->actingAs($user)->get(downloadUrl($this->ticket, $internal))->assertOk();
    }
});

test('the details page sends internal attachments only to the support team', function () {
    [$public, $internal] = publicAndInternalAttachments();

    $forRequester = $this->actingAs($this->requester)->get("/tickets/{$this->ticket->ticket_number}")->viewData('page')['props'];
    $forTech = $this->actingAs($this->tech)->get("/tickets/{$this->ticket->ticket_number}")->viewData('page')['props'];

    expect(json_encode($forRequester))->toContain('publico.pdf')->not->toContain('interno.pdf')->not->toContain('tickets/')
        ->and(json_encode($forTech))->toContain('publico.pdf')->toContain('interno.pdf')->not->toContain('tickets/')
        ->and(array_keys($forTech['comments'][1]['attachments'][0]))->toBe(['id', 'name', 'type', 'size']);
});

test('changing identifiers or addresses does not reach attachments of another ticket', function () {
    [$public] = publicAndInternalAttachments();
    $other = Ticket::factory()->create(['requester_id' => User::factory()->withRole('requester')->create()->id]);
    $stranger = User::factory()->withRole('requester')->create();

    // Anexo de um chamado pedido pela URL de outro chamado que o usuário também acessa.
    $mine = Ticket::factory()->create(['requester_id' => $this->requester->id]);
    $this->actingAs($this->requester)->get(downloadUrl($mine, $public))->assertNotFound();

    // Chamado de outra pessoa: nem o chamado nem o anexo são acessíveis.
    $this->actingAs($stranger)->get(downloadUrl($this->ticket, $public))->assertForbidden();
    $this->actingAs($this->requester)->get(downloadUrl($other, $public))->assertForbidden();

    // Identificadores inexistentes ou inválidos.
    $this->actingAs($this->requester)->get(downloadUrl($this->ticket, 999999))->assertNotFound();
    $this->actingAs($this->requester)->get("/tickets/{$this->ticket->ticket_number}/attachments/abc")->assertNotFound();
});

test('guests and inactive users cannot download', function () {
    [$public] = publicAndInternalAttachments();

    $this->get(downloadUrl($this->ticket, $public))->assertRedirect('/login');

    $this->requester->forceFill(['is_active' => false])->save();
    $this->actingAs($this->requester)->get(downloadUrl($this->ticket, $public))->assertForbidden();
});

test('a missing file on disk answers not found without exposing paths', function () {
    [$public] = publicAndInternalAttachments();
    Storage::disk('attachments')->delete($public->storage_path);

    $response = $this->actingAs($this->requester)->get(downloadUrl($this->ticket, $public))->assertNotFound();

    expect($response->getContent())->not->toContain('storage')->not->toContain($public->storage_path);
});

test('the attachments disk is private', function () {
    $disk = config('filesystems.disks.attachments');

    expect($disk['root'])->toContain('private')->not->toContain('public')
        ->and($disk)->not->toHaveKey('url')
        ->and($disk)->not->toHaveKey('serve')
        ->and(config('attachments.types'))->not->toHaveKeys(['svg', 'html', 'htm', 'js', 'php', 'exe', 'sh', 'bat']);
});

test('the audit of the ticket lists the sent files for support, never the storage path', function () {
    postComment($this->tech, $this->ticket, [pdfFile('laudo.pdf')], ['visibility' => 'internal'])->assertSessionHasNoErrors();

    $events = $this->actingAs($this->manager)->getJson("/tickets/{$this->ticket->ticket_number}/audit")->assertOk()->json('events');
    $event = collect($events)->firstWhere('type', 'attachment_added');

    expect($event['title'])->toBe('Anexos adicionados à nota interna')
        ->and(json_encode($event))->toContain('laudo.pdf')->not->toContain('tickets/');
});
