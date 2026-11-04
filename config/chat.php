<?php

/**
 * Módulo de chat (RF-26 a RF-36, docs/chat/02-system-design-chat.md, seção 13).
 * Prazos de reconexão e inatividade (D-C6) não entram aqui até serem decididos.
 */
return [

    // Desligado por padrão: as rotas e o menu só existem com CHAT_ENABLED=true.
    'enabled' => (bool) env('CHAT_ENABLED', false),

    // Atendimentos simultâneos de um técnico sem capacidade configurada (D-C7) e o máximo aceito na CHAT-06.
    'default_capacity' => 3,
    'max_capacity' => 10,

    // Presença do técnico (D-C3): sem sinal do painel por este tempo, ele deixa de ser elegível.
    'presence_ttl' => 120,
    'heartbeat_interval' => 30,

    // Consulta periódica quando o WebSocket está fora (contrato da Etapa 1, seção 8).
    'poll_interval' => 5,

    'subject_max_length' => 255,
    'message_max_length' => 2000,
];
