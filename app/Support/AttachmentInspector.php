<?php

namespace App\Support;

use finfo;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Confere um arquivo enviado pelo CONTEÚDO, não pelo nome nem pelo tipo informado pelo navegador
 * (RF-23, regras 6 e 8). Aceita só os tipos de config/attachments.php. Recusa também, mesmo dentro de um
 * tipo permitido, conteúdo que carrega script: PDF com JavaScript ou ação automática, Office com macros,
 * texto que parece HTML, SVG ou código.
 *
 * Retorna o tipo detectado ou o motivo da recusa; nunca grava nada.
 */
class AttachmentInspector
{
    /** Quanto do início de um texto é examinado em busca de marcação ou código. */
    private const TEXT_SNIFF_BYTES = 4096;

    /**
     * @return array{extension: string, mime: string}|string Motivo da recusa, em português, quando for texto.
     */
    public static function inspect(UploadedFile $file): array|string
    {
        $name = $file->getClientOriginalName();

        if (! $file->isValid() || ! is_file($file->getRealPath())) {
            return "Não foi possível receber o arquivo {$name}. Tente novamente.";
        }

        $types = config('attachments.types');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! isset($types[$extension])) {
            return "O arquivo {$name} não é permitido. Tipos aceitos: ".self::acceptedList().'.';
        }

        $path = $file->getRealPath();
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';

        // Texto sem estrutura reconhecível pode vir como `application/octet-stream`; ele só passa se, além
        // disso, for texto de verdade (sem bytes de controle nem marcação), conferido abaixo.
        $plainText = in_array($extension, ['txt', 'csv'], true) && $mime === 'application/octet-stream';

        if (! $plainText && ! in_array($mime, $types[$extension], true)) {
            return "O conteúdo do arquivo {$name} não corresponde ao tipo .{$extension}.";
        }

        $problem = match ($extension) {
            'pdf' => self::pdfProblem($path),
            'txt', 'csv' => self::textProblem($path),
            'png', 'jpg', 'jpeg', 'webp' => @getimagesize($path) === false ? 'não é uma imagem válida' : null,
            'docx' => self::officeProblem($path, 'word/'),
            'xlsx' => self::officeProblem($path, 'xl/'),
            default => 'tipo não suportado',
        };

        if ($problem !== null) {
            return "O arquivo {$name} foi recusado: {$problem}.";
        }

        return ['extension' => $extension === 'jpeg' ? 'jpg' : $extension, 'mime' => self::canonicalMime($extension, $mime)];
    }

    /** Extensões aceitas, para as mensagens e para o campo de arquivo da tela. */
    public static function acceptedList(): string
    {
        return strtoupper(implode(', ', array_keys(config('attachments.types'))));
    }

    /** Para Office, o MIME gravado é sempre o do formato, mesmo que o conteúdo tenha sido detectado como zip. */
    private static function canonicalMime(string $extension, string $detected): string
    {
        return match ($extension) {
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            default => $detected,
        };
    }

    /** PDF com JavaScript ou ação executada ao abrir. */
    private static function pdfProblem(string $path): ?string
    {
        $content = (string) file_get_contents($path);

        if (! str_starts_with(ltrim(substr($content, 0, 1024)), '%PDF-')) {
            return 'não é um PDF válido';
        }

        return preg_match('~/(JavaScript|JS|Launch|OpenAction|AA|EmbeddedFile)(?![A-Za-z])~', $content) === 1
            ? 'contém script, ação automática ou arquivo embutido'
            : null;
    }

    /** Texto: sem bytes nulos e sem aparência de HTML, SVG, script ou código. */
    private static function textProblem(string $path): ?string
    {
        $head = (string) file_get_contents($path, false, null, 0, self::TEXT_SNIFF_BYTES);

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $head) === 1) {
            return 'não é um arquivo de texto';
        }

        return preg_match('~<\s*(script|html|svg|iframe|object|embed|!doctype|\?php|\?=|\?xml)|javascript\s*:~i', $head) === 1
            ? 'contém marcação ou código executável'
            : null;
    }

    /** DOCX/XLSX: pacote zip do formato, sem macros nem objetos incorporados. */
    private static function officeProblem(string $path, string $partFolder): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return 'não é um documento válido';
        }

        try {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return 'não é um documento válido';
            }

            $hasPart = false;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = strtolower((string) $zip->getNameIndex($i));

                if (str_starts_with($entry, $partFolder)) {
                    $hasPart = true;
                }

                if (str_contains($entry, 'vbaproject') || str_contains($entry, 'macros/') || str_contains($entry, 'embeddings/') || str_ends_with($entry, '.js')) {
                    return 'contém macros ou objetos incorporados';
                }
            }

            return $hasPart ? null : 'não é um documento válido';
        } finally {
            $zip->close();
        }
    }
}
