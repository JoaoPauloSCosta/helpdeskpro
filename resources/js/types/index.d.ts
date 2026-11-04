export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    /** Preferência de som do chat (RF-31). */
    chat_sound_enabled?: boolean;
}

/** LengthAwarePaginator do Laravel serializado pelo Inertia. */
export interface Paginator<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
        /** Slugs das permissões efetivas; só controla a exibição da interface. */
        permissions: string[];
    };
    /** Parâmetros do chat; nulo com o módulo desligado. */
    chat: { pollInterval: number; heartbeatInterval: number; messageMaxLength: number; subjectMaxLength: number } | null;
    flash: {
        /** Identificador do chamado recém-aberto (CHA-02). */
        ticketCreated: string | null;
        success: string | null;
        info: string | null;
        warning: string | null;
    };
};
