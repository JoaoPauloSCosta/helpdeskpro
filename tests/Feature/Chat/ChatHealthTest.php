<?php

use Illuminate\Support\Facades\Http;

/** Etapa 11 — verificação de saúde do chat para monitoramento. */
test('it succeeds when the realtime server answers', function () {
    config(['chat.enabled' => true]);
    Http::fake(['*' => Http::response(['health' => 'OK'])]);

    $this->artisan('chat:health')->expectsOutputToContain('reverb: ok')->assertSuccessful();
});

test('it fails when the realtime server is down', function () {
    config(['chat.enabled' => true]);
    Http::fake(['*' => Http::response('', 500)]);

    $this->artisan('chat:health')->expectsOutputToContain('reverb: FALHA')->assertFailed();
});

test('it does nothing while the module is disabled', function () {
    config(['chat.enabled' => false]);
    Http::fake();

    $this->artisan('chat:health')->assertSuccessful();
    Http::assertNothingSent();
});
