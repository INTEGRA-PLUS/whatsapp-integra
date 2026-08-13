<?php

namespace App\Console\Commands;

use App\Models\WhatsAppMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto rechazaría el guardarraíl de la ventana de 24h, y si acertaría.
 *
 * En modo sombra los envíos fuera de ventana se dejan pasar pero quedan
 * marcados. Cruzando esa marca con el estado final del mensaje se responde la
 * única pregunta que importa antes de encenderlo: de los que rechazaría,
 * ¿cuántos habrían fallado de todas formas? Si es el 100%, rechazar no le
 * quita nada a nadie; si no, hay falsos positivos que corregir primero.
 */
class WindowGuardReport extends Command
{
    protected $signature = 'whatsapp:window-guard
        {--days=7 : Días a analizar}';

    protected $description = 'Mide qué rechazaría el guardarraíl de la ventana de 24h antes de encenderlo';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $desde = now()->subDays($days);

        // Las columnas van cualificadas: más abajo esta consulta se une con
        // conversations, instances y companies, y `created_at` o `status` a secas
        // quedarían ambiguos.
        $marcados = WhatsAppMessage::whereRaw("JSON_EXTRACT(whatsapp_messages.metadata, '$.window_guard') = 'shadow_pass'")
            ->where('whatsapp_messages.created_at', '>=', $desde);

        $total = (clone $marcados)->count();

        $this->newLine();
        $this->line("<options=bold>Guardarraíl de ventana de 24h · últimos {$days} días</>");
        $this->line('  Modo actual: ' . config('whatsapp.window_guard.mode'));

        $forzadas = config('whatsapp.window_guard.enforce_companies');
        if ($forzadas) {
            $this->line('  Empresas ya en enforce: ' . implode(', ', $forzadas));
        }

        $this->newLine();

        if ($total === 0) {
            $this->info('Ningún envío fuera de ventana en el periodo. Nada que rechazaría.');
            return self::SUCCESS;
        }

        $fallidos = (clone $marcados)->where('whatsapp_messages.status', 'failed')->count();
        $entregados = $total - $fallidos;
        $precision = round(100 * $fallidos / $total, 1);

        $this->line("  Envíos que se rechazarían: <options=bold>{$total}</>");
        $this->line("  De ésos, fallaron igual:   <options=bold>{$fallidos}</> ({$precision}%)");
        $this->line("  Llegaron pese a todo:      {$entregados}");
        $this->newLine();

        if ($entregados > 0) {
            $this->warn("⚠️  {$entregados} llegaron pese a estar fuera de ventana: son falsos positivos.");
            $this->line('   Revísalos antes de encender el rechazo, o esos avisos dejarían de salir.');
        } else {
            $this->info('✅ Ninguno se salvó: rechazarlos no le quita ni un mensaje entregado al cliente.');
        }

        $this->newLine();
        $this->line('<options=bold>Por empresa</>');

        $porEmpresa = (clone $marcados)
            ->join('whatsapp_conversations as c', 'c.id', '=', 'whatsapp_messages.conversation_id')
            ->join('instances as i', 'i.id', '=', 'c.instance_id')
            ->join('companies as co', 'co.id', '=', 'i.company_id')
            ->groupBy('co.id', 'co.name')
            ->select('co.id', 'co.name', DB::raw('COUNT(*) as n'),
                DB::raw("SUM(whatsapp_messages.status = 'failed') as fallidos"))
            ->orderByDesc('n')
            ->get();

        $this->table(
            ['id', 'empresa', 'rechazaría', 'fallaron igual'],
            $porEmpresa->map(fn ($r) => [$r->id, $r->name, $r->n, $r->fallidos])->all()
        );

        $this->line('Para encender una empresa concreta, sin tocar el resto:');
        $this->line('  WHATSAPP_WINDOW_GUARD_COMPANIES=' . ($porEmpresa->first()->id ?? '44'));

        return self::SUCCESS;
    }
}
