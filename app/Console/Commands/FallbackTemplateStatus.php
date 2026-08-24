<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Services\WhatsAppFallbackTemplateService;
use Illuminate\Console\Command;

/**
 * Estado (y alta) de la plantilla de respaldo en el WABA de cada empresa.
 *
 * El servicio ya la crea sola la primera vez que un aviso se topa con la
 * ventana cerrada, pero eso deja el alta a merced del tráfico: la empresa que
 * recibe su primer aviso fuera de ventana un viernes por la noche lo pierde
 * igual porque la plantilla acaba de entrar en revisión. Correr esto tras dar de
 * alta un tenant adelanta la aprobación a antes de que haga falta.
 *
 * En el planificador también sirve de segunda vuelta: una plantilla que quedó
 * PENDING pasa a APPROVED aquí, sin esperar al siguiente aviso.
 */
class FallbackTemplateStatus extends Command
{
    protected $signature = 'whatsapp:fallback-template
        {--company= : Limitar a una empresa (company_id)}
        {--instance= : Limitar a una instancia (id)}
        {--force : Volver a preguntar a Meta aunque el estado guardado esté fresco}
        {--dry : Sólo mostrar lo guardado; no consulta ni crea nada en Meta}';

    protected $description = 'Revisa y aprovisiona la plantilla de respaldo de la ventana de 24h en cada WABA';

    public function handle(WhatsAppFallbackTemplateService $fallback): int
    {
        $query = Instance::with('company')
            ->where('active', true)
            ->whereNotNull('waba_id')
            ->whereNotNull('access_token')
            ->orderBy('company_id')
            ->orderBy('id');

        if ($companyId = $this->option('company')) {
            $query->where('company_id', $companyId);
        }

        if ($instanceId = $this->option('instance')) {
            $query->where('id', $instanceId);
        }

        $instances = $query->get();

        if ($instances->isEmpty()) {
            $this->warn('No hay instancias activas con WABA y token configurados.');
            return self::SUCCESS;
        }

        $rows = [];
        $pendientes = 0;
        $problemas = 0;

        foreach ($instances as $instance) {
            $state = $this->option('dry')
                ? $instance->fallbackTemplateSettings()
                : $fallback->ensure($instance, (bool) $this->option('force'));

            $status = $state['status'] ?? '—';

            if ($status === WhatsAppFallbackTemplateService::STATUS_PENDING) {
                $pendientes++;
            } elseif (!in_array($status, [
                WhatsAppFallbackTemplateService::STATUS_APPROVED,
                WhatsAppFallbackTemplateService::STATUS_DISABLED,
                '—',
            ], true)) {
                $problemas++;
            }

            $rows[] = [
                $instance->company_id,
                $instance->company?->name ?? '—',
                $instance->display_phone_number ?: $instance->name,
                $state['name'] ?? '—',
                $state['language'] ?? '—',
                $this->paint($status),
                $this->shorten($state['last_error'] ?? null),
            ];
        }

        $this->newLine();
        $this->table(
            ['empresa', 'nombre', 'línea', 'plantilla', 'idioma', 'estado', 'detalle'],
            $rows
        );

        if ($this->option('dry')) {
            $this->line('Modo --dry: no se consultó Meta; esto es el último estado guardado.');
            return self::SUCCESS;
        }

        if ($pendientes > 0) {
            $this->warn("{$pendientes} plantilla(s) esperando aprobación de Meta. "
                . 'Vuelve a correr este comando en unos minutos.');
        }

        if ($problemas > 0) {
            $this->error("{$problemas} instancia(s) sin respaldo utilizable: "
                . 'sus avisos fuera de la ventana de 24h se siguen perdiendo.');
            return self::FAILURE;
        }

        if ($pendientes === 0) {
            $this->info('Todas las instancias tienen su plantilla de respaldo lista.');
        }

        return self::SUCCESS;
    }

    private function paint(string $status): string
    {
        return match ($status) {
            WhatsAppFallbackTemplateService::STATUS_APPROVED => "<fg=green>{$status}</>",
            WhatsAppFallbackTemplateService::STATUS_PENDING  => "<fg=yellow>{$status}</>",
            WhatsAppFallbackTemplateService::STATUS_DISABLED => "<fg=gray>{$status}</>",
            '—' => $status,
            default => "<fg=red>{$status}</>",
        };
    }

    private function shorten(?string $text): string
    {
        if (!$text) {
            return '';
        }

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 59) . '…' : $text;
    }
}
