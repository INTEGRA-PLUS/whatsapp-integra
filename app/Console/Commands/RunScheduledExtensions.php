<?php

namespace App\Console\Commands;

use App\Extensions\Contracts\RunsOnSchedule;
use App\Extensions\ExtensionRegistry;
use App\Models\CompanyExtension;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * El gancho programado de las extensiones: una pasada cada cinco minutos.
 *
 * Un solo comando para todas y no uno por extensión porque el planificador de
 * Laravel es un archivo compartido: cada extensión nueva obligaría a editarlo, y
 * el punto del módulo es que añadir una extensión no toque nada fuera de su
 * propia clase.
 *
 * Cinco minutos es el grano más fino que tiene sentido: lo que corre aquí se
 * mide en decenas de minutos (un chat sin respuesta), y bajar a cada minuto
 * multiplicaría por cinco las consultas para adelantar el aviso un minuto.
 */
class RunScheduledExtensions extends Command
{
    protected $signature = 'extensions:run
        {--slug= : Corre sólo esta extensión}
        {--company= : Corre sólo para esta empresa (id)}';

    protected $description = 'Ejecuta las extensiones programadas de cada empresa que las tenga encendidas';

    public function handle(ExtensionRegistry $registry): int
    {
        $slugs = collect($registry->all())
            ->filter(fn ($extension) => $extension instanceof RunsOnSchedule)
            ->keys();

        if ($this->option('slug')) {
            $slugs = $slugs->intersect([$this->option('slug')]);
        }

        if ($slugs->isEmpty()) {
            $this->info('No hay extensiones programadas que correr.');

            return self::SUCCESS;
        }

        $instaladas = CompanyExtension::whereIn('slug', $slugs)
            ->where('enabled', true)
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', $id))
            ->orderBy('company_id')
            ->get();

        $corridas = 0;

        foreach ($instaladas as $instalada) {
            /** @var RunsOnSchedule $extension */
            $extension = $registry->find($instalada->slug);

            // Cada empresa en su propio try: la pasada recorre todas las
            // empresas de la plataforma, y una con los datos torcidos no puede
            // dejar sin ejecutar a las treinta y nueve que van detrás.
            try {
                $extension->runScheduled($instalada);
                $corridas++;
            } catch (\Throwable $e) {
                Log::channel('whatsapp')->warning('⚠️ Falló una extensión programada', [
                    'slug' => $instalada->slug,
                    'company_id' => $instalada->company_id,
                    'error' => $e->getMessage(),
                ]);

                $this->warn("«{$instalada->slug}» falló en la empresa {$instalada->company_id}: {$e->getMessage()}");
            }
        }

        $this->info("Extensiones programadas ejecutadas: {$corridas}.");

        return self::SUCCESS;
    }
}
