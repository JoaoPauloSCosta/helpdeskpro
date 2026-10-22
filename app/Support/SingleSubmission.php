<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Garante que um mesmo envio de formulário (identificado por um token gerado na abertura da tela)
 * produza um único registro, mesmo com cliques repetidos ou reenvios do navegador. Mesmo
 * mecanismo usado na abertura de chamados (CHA-02), para cadastros sem chave natural única.
 */
class SingleSubmission
{
    private const PENDING = 'pending';

    /** Por quanto tempo um envio repetido é reconhecido (segundos). */
    private const TTL = 3600;

    /**
     * Executa `$create` uma única vez por chave e devolve o identificador do registro criado.
     * Um envio repetido depois do sucesso recebe o mesmo identificador, sem criar outro registro.
     *
     * @param  Closure(): (int|string)  $create
     * @return array{0: string, 1: bool} Identificador e se o envio era uma repetição.
     *
     * @throws SubmissionInProgress quando o primeiro envio ainda está sendo processado.
     */
    public function run(string $key, Closure $create): array
    {
        // Cache::add é atômico: só o primeiro envio com esta chave executa a criação.
        if (! Cache::add($key, self::PENDING, self::TTL)) {
            $result = Cache::get($key);

            if (is_string($result) && $result !== self::PENDING) {
                return [$result, true];
            }

            throw new SubmissionInProgress;
        }

        try {
            $id = (string) $create();
        } catch (Throwable $e) {
            // Falhou: o mesmo token pode ser usado em uma nova tentativa.
            Cache::forget($key);

            throw $e;
        }

        Cache::put($key, $id, self::TTL);

        return [$id, false];
    }
}
