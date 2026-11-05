<?php

namespace App\Console\Commands;

use App\Enums\ChatSessionStatus;
use App\Models\ChatAgent;
use App\Models\ChatSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verificação de saúde do chat para monitoramento (docs/chat/12-producao.md): sai com código 1 se o chat está
 * ligado e o servidor de tempo real não responde; informa fila, plantão e filas de trabalho. Pode ser usada
 * como comando de health check do contêiner ou em um cron com alerta.
 */
class ChatHealth extends Command
{
    protected $signature = 'chat:health';

    protected $description = 'Confere o servidor de tempo real e mostra a situação da fila do chat';

    public function handle(): int
    {
        if (! config('chat.enabled')) {
            $this->line('Chat desligado (CHAT_ENABLED=false).');

            return self::SUCCESS;
        }

        $reverb = $this->reverbIsUp();

        $this->line('reverb: '.($reverb ? 'ok' : 'FALHA'));
        $this->line('aguardando: '.ChatSession::where('status', ChatSessionStatus::Waiting)->count());
        $this->line('em atendimento: '.ChatSession::where('status', ChatSessionStatus::Active)->count());
        $this->line('tecnicos de plantao: '.ChatAgent::onDuty()->count());
        $this->line('jobs pendentes: '.DB::table('jobs')->count());
        $this->line('jobs com falha: '.DB::table('failed_jobs')->count());

        return $reverb ? self::SUCCESS : self::FAILURE;
    }

    /** O mesmo endereço que a aplicação usa para publicar eventos; `/up` é o health check do Reverb. */
    private function reverbIsUp(): bool
    {
        $options = config('broadcasting.connections.reverb.options', []);

        try {
            return Http::timeout(3)->get("{$options['scheme']}://{$options['host']}:{$options['port']}/up")->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
