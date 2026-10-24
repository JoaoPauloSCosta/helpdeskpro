<?php

namespace App\Rules;

use App\Support\AttachmentInspector;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Um arquivo anexado (RF-23): tamanho, extensão e conteúdo conferidos no servidor. A mensagem de
 * recusa diz qual arquivo falhou e por quê.
 */
class ValidAttachment implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('Envie um arquivo válido.');

            return;
        }

        if ($value->getError() === UPLOAD_ERR_INI_SIZE || $value->getError() === UPLOAD_ERR_FORM_SIZE
            || ($value->isValid() && $value->getSize() > config('attachments.max_file_kb') * 1024)) {
            $limit = round(config('attachments.max_file_kb') / 1024);
            $fail("O arquivo {$value->getClientOriginalName()} passa do limite de {$limit} MB.");

            return;
        }

        $result = AttachmentInspector::inspect($value);

        if (is_string($result)) {
            $fail($result);
        }
    }
}
