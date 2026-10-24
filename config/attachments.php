<?php

/*
 * Anexos de chamados e mensagens (RF-23). Os limites protegem o disco do servidor e precisam caber
 * nos limites do PHP: `upload_max_filesize` >= max_file_kb e `post_max_size` > max_upload_kb
 * (docker/php.ini: 20M e 25M; XAMPP: 40M e 40M).
 */
return [

    /** Disco privado (config/filesystems.php): nunca exposto por URL pública. */
    'disk' => env('ATTACHMENTS_DISK', 'attachments'),

    /** Tamanho máximo de um arquivo, em KB. */
    'max_file_kb' => (int) env('ATTACHMENTS_MAX_FILE_KB', 10240),

    /** Quantidade máxima de arquivos por envio (abertura ou mensagem). */
    'max_files_per_upload' => (int) env('ATTACHMENTS_MAX_FILES', 5),

    /** Soma máxima dos arquivos de um envio, em KB (deve ficar abaixo de `post_max_size`). */
    'max_upload_kb' => (int) env('ATTACHMENTS_MAX_UPLOAD_KB', 20480),

    /** Teto por chamado, para o disco não encher: quantidade de arquivos e soma, em KB. */
    'max_files_per_ticket' => (int) env('ATTACHMENTS_MAX_FILES_PER_TICKET', 20),
    'max_ticket_kb' => (int) env('ATTACHMENTS_MAX_TICKET_KB', 102400),

    /**
     * Tipos aceitos: extensão => tipos MIME que o CONTEÚDO do arquivo pode ter (detectados no servidor,
     * não os informados pelo navegador). Fora desta lista nada é aceito: SVG, HTML, JavaScript, scripts
     * e executáveis não estão nela.
     */
    'types' => [
        'pdf' => ['application/pdf'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ],

];
