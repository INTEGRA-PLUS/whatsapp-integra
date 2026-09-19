<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Services\MetaWhatsAppService;
use Illuminate\Console\Command;

/**
 * Enseña el catálogo de plantillas de una instancia.
 *
 * Las plantillas viven **sólo en Meta**, por WABA: aquí no hay copia ni tabla
 * que consultar. Hasta ahora eso significaba que para ver lo que tiene una
 * empresa había que entrar a su cuenta, y para copiarle una plantilla a otra,
 * mirarla por encima del hombro.
 *
 * Es de sólo lectura: ni crea ni borra nada.
 */
class VerPlantillas extends Command
{
    protected $signature = 'whatsapp:plantillas
        {instancia : Id de la instancia}
        {--buscar= : Sólo las que contengan este texto en el nombre}
        {--cuerpo : Enseña el texto completo de cada componente}';

    protected $description = 'Lista las plantillas que tiene una instancia en Meta';

    public function __construct(private MetaWhatsAppService $meta)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $instance = Instance::find($this->argument('instancia'));

        if (! $instance || ! $instance->waba_id || ! $instance->access_token) {
            $this->error('Esa instancia no existe o no tiene WABA y token.');

            return self::FAILURE;
        }

        $resultado = $this->meta->listTemplates($instance->waba_id, $instance->access_token, [
            'fields' => 'name,status,category,language,components',
            'limit' => 500,
        ]);

        if (! ($resultado['success'] ?? false)) {
            $this->error('Meta no devolvió el catálogo: '.json_encode($resultado['error'] ?? null));

            return self::FAILURE;
        }

        $buscar = (string) $this->option('buscar');

        $plantillas = collect($resultado['data']['data'] ?? [])
            ->filter(fn (array $t) => $buscar === '' || str_contains(mb_strtolower($t['name'] ?? ''), mb_strtolower($buscar)))
            ->values();

        if ($plantillas->isEmpty()) {
            $this->info('Sin plantillas'.($buscar !== '' ? " que contengan «{$buscar}»" : '').'.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line("  <options=bold>{$instance->name}</> · WABA {$instance->waba_id} · {$plantillas->count()} plantillas");
        $this->newLine();

        foreach ($plantillas as $t) {
            $this->line(sprintf(
                '  <fg=cyan>%s</>  <fg=gray>%s · %s · %s</>',
                $t['name'] ?? '?',
                $t['status'] ?? '?',
                $t['category'] ?? '?',
                $t['language'] ?? '?',
            ));

            if (! $this->option('cuerpo')) {
                continue;
            }

            foreach ((array) ($t['components'] ?? []) as $c) {
                $texto = $c['text'] ?? ($c['format'] ?? '');

                foreach (explode("\n", (string) $texto) as $linea) {
                    $this->line('      <fg=gray>'.($c['type'] ?? '?').'</>  '.$linea);
                }

                foreach ((array) ($c['buttons'] ?? []) as $b) {
                    $this->line('      <fg=gray>BOTÓN</>  '.($b['text'] ?? '').' ('.($b['type'] ?? '').')');
                }
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
