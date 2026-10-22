<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Convite de um usuário criado pela administração (ADM-02). Reaproveita o fluxo de redefinição de
 * senha do Breeze: mesmo token, mesma rota `password.reset` e mesma validade; só o texto muda.
 * Não é enfileirada, para que o controller saiba se o envio realmente aconteceu.
 */
class UserInvitation extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Sua conta no '.config('app.name'))
            ->greeting('Olá!')
            ->line('Uma conta foi criada para você no '.config('app.name').'.')
            ->line('Para acessar o sistema, defina sua senha pelo botão abaixo.')
            ->action('Definir senha', $url)
            ->line("Este link expira em {$minutes} minutos. Depois disso, use \"Esqueci minha senha\" na tela de login para receber um novo.")
            ->line('Se você não esperava este e-mail, pode ignorá-lo.');
    }
}
