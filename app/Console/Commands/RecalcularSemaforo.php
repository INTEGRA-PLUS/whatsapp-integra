<?php

namespace App\Console\Commands;

use App\Extensions\ExtensionRegistry;
use App\Extensions\SentimientoExtension;
use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use Illuminate\Console\Command;

/**
 * Vuelve a calcular el semáforo de las conversaciones que ya existían.
 *
 * Hace falta en dos momentos, y el segundo es el que más:
 *
 * 1. **Al instalar.** La extensión sólo pinta lo que llega después, así que una
 *    bandeja con cien conversaciones abiertas se queda en gris. El gancho
 *    programado la pone al día sola en unos minutos, pero en desarrollo
 *    `composer dev` no levanta el planificador y ahí no corre nunca.
 * 2. **Al cambiar los ajustes.** Subir la sensibilidad no repinta nada por su
 *    cuenta: los colores ya escritos se quedan como estaban, y quien acaba de
 *    mover el ajuste espera ver el efecto, no esperarlo.
 *
 * Sin `--todas` sólo mira lo que nadie ha mirado —lo mismo que la pasada
 * programada, pero ahora—. Con `--todas` repinta la bandeja entera, que es lo
 * que se quiere tras tocar la sensibilidad.
 *
 * **No llama a la IA nunca**, ni con `--todas` ni con la extensión configurada
 * para usarla: repintar mil conversaciones serían mil inferencias de golpe. La
 * IA entra por el mensaje entrante, que es cuando importa.
 */
class RecalcularSemaforo extends Command
{
    protected $signature = 'wa:semaforo-recalcular
        {--empresa= : Id de la empresa. Por defecto, todas las que lo tengan encendido}
        {--todas : Repinta también las que ya tenían color (tras cambiar la sensibilidad)}
        {--limite=1000 : Tope de conversaciones por empresa}';

    protected $description = 'Recalcula el semáforo de emociones de las conversaciones existentes';

    public function handle(ExtensionRegistry $registry): int
    {
        $extension = $registry->find('sentiment_traffic_light');

        if (! $extension instanceof SentimientoExtension) {
            $this->error('La extensión del semáforo no está en el catálogo.');

            return self::FAILURE;
        }

        $instaladas = CompanyExtension::where('slug', 'sentiment_traffic_light')
            ->where('enabled', true)
            ->when($this->option('empresa'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->get();

        if ($instaladas->isEmpty()) {
            $this->warn('Ninguna empresa tiene el semáforo encendido.');

            return self::SUCCESS;
        }

        $todas = (bool) $this->option('todas');
        $limite = max(1, (int) $this->option('limite'));

        foreach ($instaladas as $instalada) {
            $this->porEmpresa($extension, $instalada, $todas, $limite);
        }

        return self::SUCCESS;
    }

    private function porEmpresa(
        SentimientoExtension $extension,
        CompanyExtension $instalada,
        bool $todas,
        int $limite
    ): void {
        $nombre = Company::where('id', $instalada->company_id)->value('name') ?? $instalada->company_id;
        $instanceIds = Instance::where('company_id', $instalada->company_id)->pluck('id');

        if ($instanceIds->isEmpty()) {
            $this->line("  <fg=gray>{$nombre}: sin instancias</>");

            return;
        }

        $conversaciones = WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->where('status', 'open')
            // La corrección a mano manda siempre, también aquí.
            ->whereNull('sentiment_locked_by')
            ->when(! $todas, fn ($q) => $q->whereNull('sentiment_at'))
            ->orderByDesc('last_message_at')
            ->limit($limite)
            ->get();

        if ($conversaciones->isEmpty()) {
            $this->line("  <fg=gray>{$nombre}: nada que recalcular</>");

            return;
        }

        $barra = $this->output->createProgressBar($conversaciones->count());
        $barra->start();

        $cuenta = ['verde' => 0, 'amarillo' => 0, 'rojo' => 0, 'sin_color' => 0];

        foreach ($conversaciones as $conversacion) {
            $lectura = $extension->recalcular($conversacion, $instalada);
            $cuenta[$lectura?->nivel ?? 'sin_color']++;
            $barra->advance();
        }

        $barra->finish();
        $this->newLine();

        $this->line(sprintf(
            '  <info>%s</info>  <fg=green>● %d</> <fg=yellow>● %d</> <fg=red>● %d</>  <fg=gray>%d sin color</>',
            $nombre,
            $cuenta['verde'],
            $cuenta['amarillo'],
            $cuenta['rojo'],
            $cuenta['sin_color']
        ));
    }
}
