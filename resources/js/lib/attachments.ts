/** Anexo de chamado ou mensagem (RF-23), como o servidor o envia. O caminho de armazenamento nunca chega à tela. */
export interface TicketAttachment {
    id: number;
    name: string;
    /** Tipo detectado pelo servidor. */
    type: string;
    /** Tamanho em bytes. */
    size: number;
}

/** Limites vindos do servidor (config/attachments.php); a tela só avisa antes, quem valida é o backend. */
export interface AttachmentLimits {
    maxFiles: number;
    maxFileBytes: number;
    maxUploadBytes: number;
    extensions: string[];
}

export const formatSize = (bytes: number): string => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024).toLocaleString('pt-BR')} KB`;

    return `${(bytes / (1024 * 1024)).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} MB`;
};

const extensionOf = (name: string) => name.split('.').pop()?.toLowerCase() ?? '';

/**
 * Separa o que pode ser enviado do que o servidor recusaria, para avisar já na seleção. É só conveniência:
 * o conteúdo do arquivo é conferido no servidor.
 */
export function screenFiles(
    current: File[],
    incoming: File[],
    limits: AttachmentLimits,
): { accepted: File[]; rejected: string[] } {
    const accepted: File[] = [];
    const rejected: string[] = [];
    let total = current.reduce((sum, file) => sum + file.size, 0);

    for (const file of incoming) {
        if (!limits.extensions.includes(extensionOf(file.name))) {
            rejected.push(`${file.name}: tipo não permitido. Aceitos: ${limits.extensions.join(', ').toUpperCase()}.`);
        } else if (file.size === 0) {
            rejected.push(`${file.name}: o arquivo está vazio.`);
        } else if (file.size > limits.maxFileBytes) {
            rejected.push(`${file.name}: passa do limite de ${formatSize(limits.maxFileBytes)} por arquivo.`);
        } else if (current.length + accepted.length >= limits.maxFiles) {
            rejected.push(`${file.name}: o limite é de ${limits.maxFiles} arquivos por envio.`);
        } else if (total + file.size > limits.maxUploadBytes) {
            rejected.push(`${file.name}: a soma dos arquivos passa de ${formatSize(limits.maxUploadBytes)}.`);
        } else {
            accepted.push(file);
            total += file.size;
        }
    }

    return { accepted, rejected };
}
