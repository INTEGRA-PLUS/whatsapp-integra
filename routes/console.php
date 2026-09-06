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

// Salud de las instancias contra Meta, una vez al día a las 7:00.
//
// No es paranoia: el 2026-09-05 se encontraron cinco empresas cuyo token o
// número ya no existían en Meta, la más antigua desde hacía seis meses, todas
// mostrando "Activa" en verde. Se descubrieron de casualidad. Diario basta —
// una instancia no se cae y se recupera sola en el mismo día— y el comando sólo
// avisa cuando el estado CAMBIA, para que la alerta no se vuelva ruido.
Schedule::command('whatsapp:health-check')->dailyAt('07:00')->withoutOverlapping();
