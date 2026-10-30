<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// D4: violações de chamados parados. Exige o Agendador do Windows rodando `schedule:run` (docs/pendencias.md).
Schedule::command('sla:record-breaches')->everyMinute()->withoutOverlapping(5);

// RF-14 (regra 9): chamados resolvidos e não reabertos em 3 dias são encerrados. Mesmo Agendador do item acima.
Schedule::command('tickets:close-resolved')->everyFifteenMinutes()->withoutOverlapping(10);
