<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// D4: violações de chamados parados. Exige o Agendador do Windows rodando `schedule:run` (docs/pendencias.md).
Schedule::command('sla:record-breaches')->everyMinute()->withoutOverlapping(5);
