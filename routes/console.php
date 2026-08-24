<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('campaigns:run-scheduled')->everyMinute()->withoutOverlapping();

// Segunda vuelta de la plantilla de respaldo de la ventana de 24h: la que quedó
// PENDING pasa a APPROVED aquí, sin esperar a que otro aviso se estrelle contra
// la ventana cerrada. Las ya aprobadas no cuestan una llamada a Meta: el estado
// guardado en la instancia sigue fresco durante horas.
Schedule::command('whatsapp:fallback-template')->hourly()->withoutOverlapping();
