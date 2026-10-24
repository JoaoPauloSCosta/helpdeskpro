<?php

namespace App\Actions\Tickets;

use App\Enums\CommentVisibility;
use App\Enums\TicketEvent;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketComment;
use App\Models\User;
use App\Support\AttachmentInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Grava os anexos de um envio (RF-23): na abertura do chamado ou em uma mensagem, pública ou interna.
 *
 * Deve rodar dentro da transação que grava o chamado ou a mensagem, depois que o chamado está
 * bloqueado, para o teto por chamado valer também sob concorrência. Como arquivos não participam da
 * transação do banco, quem chama deve passar `$written` por referência e chamar `purge()` se algo falhar
 * depois: nenhum arquivo fica órfão no disco.
 *
 * O nome gravado é gerado aqui (UUID + extensão detectada pelo conteúdo); o nome original é só exibição.
 */
class StoreTicketAttachments
{
    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $written  Caminhos já gravados no disco, para limpeza em caso de falha.
     * @return list<TicketAttachment>
     *
     * @throws ValidationException Teto por chamado excedido.
     */
    public function __invoke(User $author, Ticket $ticket, ?TicketComment $comment, array $files, array &$written): array
    {
        if ($files === []) {
            return [];
        }

        $this->assertWithinTicketQuota($ticket, $files);

        $disk = Storage::disk(config('attachments.disk'));
        $created = [];

        foreach ($files as $file) {
            $inspected = AttachmentInspector::inspect($file);
            // As regras de validação já recusaram o que não presta; conferir de novo aqui é a defesa final.
            abort_if(is_string($inspected), 422, is_string($inspected) ? $inspected : '');

            $path = "tickets/{$ticket->id}/".Str::uuid().".{$inspected['extension']}";
            $disk->putFileAs(dirname($path), $file, basename($path));
            $written[] = $path;

            $created[] = (new TicketAttachment)->forceFill([
                'ticket_id' => $ticket->id,
                'ticket_comment_id' => $comment?->id,
                'uploaded_by' => $author->id,
                'original_name' => self::displayName($file->getClientOriginalName()),
                'storage_path' => $path,
                'mime_type' => $inspected['mime'],
                'size_bytes' => $disk->size($path),
                'sha256' => hash_file('sha256', $file->getRealPath()),
            ]);
        }

        foreach ($created as $attachment) {
            $attachment->save();
        }

        $this->recordEvent($author, $ticket, $comment, $created);

        return $created;
    }

    /** Remove do disco os arquivos de uma operação que não chegou a ser confirmada. */
    public static function purge(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk(config('attachments.disk'))->delete($paths);
        }
    }

    /** Nome para exibição: sem caminho, sem caracteres de controle e com tamanho limitado. */
    public static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = trim($name);

        return mb_substr($name === '' ? 'arquivo' : $name, 0, 150);
    }

    /** Teto por chamado (quantidade e soma), para o disco do servidor não encher. */
    private function assertWithinTicketQuota(Ticket $ticket, array $files): void
    {
        $existing = TicketAttachment::where('ticket_id', $ticket->id);
        $count = (clone $existing)->count();
        $bytes = (int) (clone $existing)->sum('size_bytes');
        $incoming = array_sum(array_map(fn (UploadedFile $file) => (int) $file->getSize(), $files));

        if ($count + count($files) > config('attachments.max_files_per_ticket')) {
            throw ValidationException::withMessages([
                'attachments' => 'Este chamado atingiu o limite de '.config('attachments.max_files_per_ticket').' anexos.',
            ]);
        }

        if ($bytes + $incoming > config('attachments.max_ticket_kb') * 1024) {
            throw ValidationException::withMessages([
                'attachments' => 'Este chamado atingiu o limite de '.round(config('attachments.max_ticket_kb') / 1024).' MB em anexos.',
            ]);
        }
    }

    /** Auditoria do envio (RF-19): quem enviou, em qual mensagem e quais arquivos. Nunca o conteúdo. */
    private function recordEvent(User $author, Ticket $ticket, ?TicketComment $comment, array $created): void
    {
        $ticket->histories()->forceCreate([
            'user_id' => $author->id,
            'event_type' => TicketEvent::AttachmentAdded,
            'old_values' => null,
            'new_values' => [
                'comment_id' => $comment?->id,
                // Sem mensagem, é o anexo da abertura: público. A visibilidade acompanha a da mensagem.
                'visibility' => ($comment?->visibility ?? CommentVisibility::Public)->value,
                'files' => array_map(fn (TicketAttachment $a) => [
                    'id' => $a->id,
                    'name' => $a->original_name,
                    'size' => $a->size_bytes,
                ], $created),
            ],
            'created_at' => now(),
        ]);
    }
}
