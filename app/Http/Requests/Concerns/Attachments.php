<?php

namespace App\Http\Requests\Concerns;

use App\Rules\ValidAttachment;
use Illuminate\Http\UploadedFile;

/**
 * Regras e mensagens do campo `attachments` (RF-23), compartilhadas pela abertura do chamado e pelas
 * mensagens. Os limites vêm de config/attachments.php.
 */
class Attachments
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'attachments' => ['nullable', 'array', 'max:'.config('attachments.max_files_per_upload'), function (string $attribute, mixed $value, \Closure $fail) {
                $bytes = collect($value)
                    ->filter(fn ($file) => $file instanceof UploadedFile && $file->isValid())
                    ->sum(fn (UploadedFile $file) => $file->getSize());

                if ($bytes > config('attachments.max_upload_kb') * 1024) {
                    $fail('A soma dos arquivos passa do limite de '.round(config('attachments.max_upload_kb') / 1024).' MB por envio.');
                }
            }],
            'attachments.*' => [new ValidAttachment],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'attachments.array' => 'Os anexos foram enviados em um formato inválido.',
            'attachments.max' => 'Envie no máximo :max arquivos por vez.',
        ];
    }

    /**
     * Arquivos validados do envio, como lista simples.
     *
     * @return list<UploadedFile>
     */
    public static function files(\Illuminate\Http\Request $request): array
    {
        return array_values(array_filter((array) $request->file('attachments'), fn ($file) => $file instanceof UploadedFile));
    }
}
