<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\KanbanColumn;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reparte las columnas del tablero de una empresa en grupos.
 *
 * Star NET tenía 43 columnas en una sola fila porque ahí dentro había seis
 * tableros: áreas, tipos de falla, embudo comercial, cartera, estado del
 * ticket y quince municipios. Repartirlas a mano desde la interfaz son 43
 * clics y ninguna forma de revisarlo antes ni de deshacerlo.
 *
 * Esto no aplica nada si no se lo pides: sin `--aplicar` sólo enseña lo que
 * haría. Y `--deshacer` devuelve la empresa a un solo tablero.
 */
class AgruparColumnasKanban extends Command
{
    protected $signature = 'kanban:agrupar-columnas
                            {empresa : Nombre o id de la empresa}
                            {--archivo= : JSON con los grupos (ver docs/agrupaciones/)}
                            {--aplicar : Escribir los cambios; sin esto sólo se enseñan}
                            {--deshacer : Quitar los grupos y volver a un solo tablero}';

    protected $description = 'Reparte las columnas del tablero de una empresa en grupos (Estado, Zona, Área…)';

    public function handle(): int
    {
        $empresa = $this->buscarEmpresa($this->argument('empresa'));

        if (! $empresa) {
            $this->error('No encuentro esa empresa.');

            return self::FAILURE;
        }

        $this->line("Empresa: <info>{$empresa->name}</info> (id {$empresa->id})");

        return $this->option('deshacer')
            ? $this->deshacer($empresa)
            : $this->agrupar($empresa);
    }

    private function agrupar(Company $empresa): int
    {
        $ruta = $this->option('archivo');

        if (! $ruta || ! is_file($ruta)) {
            $this->error('Falta --archivo con el JSON de los grupos. Hay ejemplos en docs/agrupaciones/.');

            return self::FAILURE;
        }

        $grupos = json_decode(file_get_contents($ruta), true);

        if (! is_array($grupos)) {
            $this->error('El archivo no es un JSON válido.');

            return self::FAILURE;
        }

        $columnas = KanbanColumn::where('company_id', $empresa->id)->orderBy('position')->get();
        $porNombre = $columnas->keyBy(fn ($c) => $this->normalizar($c->name));

        $plan = [];          // [columna, grupo, bandeja, posición]
        $noEncontradas = [];
        $posicion = 1;

        foreach ($grupos as $grupo => $definicion) {
            $nombres = $definicion['columnas'] ?? [];
            $bandeja = $definicion['bandeja'] ?? null;

            foreach ($nombres as $nombre) {
                $columna = $porNombre->get($this->normalizar($nombre));

                if (! $columna) {
                    $noEncontradas[] = "{$grupo} → {$nombre}";

                    continue;
                }

                $plan[] = [
                    'columna'  => $columna,
                    'grupo'    => $grupo,
                    'bandeja'  => $bandeja !== null && $this->normalizar($bandeja) === $this->normalizar($nombre),
                    'posicion' => $posicion++,
                ];
            }
        }

        $tocadas = collect($plan)->pluck('columna.id');
        $sueltas = $columnas->whereNotIn('id', $tocadas);

        $this->newLine();
        $this->table(
            ['Columna', 'Grupo', 'Bandeja', 'Tarjetas'],
            collect($plan)->map(fn ($p) => [
                $p['columna']->name,
                $p['grupo'],
                $p['bandeja'] ? 'sí' : '',
                $this->tarjetas($p['columna']),
            ])->all()
        );

        if ($sueltas->isNotEmpty()) {
            $this->warn('Se quedan sin agrupar ('.$sueltas->count().'), y aparecerán como un grupo aparte:');
            foreach ($sueltas as $c) {
                $this->line("  · {$c->name} ({$this->tarjetas($c)} tarjetas)");
            }
        }

        if ($noEncontradas) {
            $this->newLine();
            $this->warn('Nombres del archivo que no existen en esta empresa:');
            foreach ($noEncontradas as $n) {
                $this->line("  · {$n}");
            }
        }

        $sinBandeja = collect($plan)->pluck('grupo')->unique()
            ->reject(fn ($g) => collect($plan)->contains(fn ($p) => $p['grupo'] === $g && $p['bandeja']));

        if ($sinBandeja->isNotEmpty()) {
            $this->newLine();
            $this->line('Grupos sin bandeja ('.$sinBandeja->implode(', ').'): sólo mostrarán');
            $this->line('lo que tenga etiqueta de ese grupo, y su suma será menor que el total.');
            $this->line('En «Zona» eso es lo correcto; en «Estado» seguramente no.');
        }

        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->comment('Esto es sólo la vista previa. Añade --aplicar para escribirlo.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan) {
            foreach ($plan as $p) {
                $p['columna']->update([
                    'grupo'      => $p['grupo'],
                    'es_bandeja' => $p['bandeja'],
                    'position'   => $p['posicion'],
                ]);
            }
        });

        $this->newLine();
        $this->info('Hecho: '.count($plan).' columnas agrupadas.');

        return self::SUCCESS;
    }

    private function deshacer(Company $empresa): int
    {
        $columnas = KanbanColumn::where('company_id', $empresa->id)
            ->whereNotNull('grupo')
            ->orderBy('position')
            ->get();

        if ($columnas->isEmpty()) {
            $this->line('No hay ninguna columna agrupada.');

            return self::SUCCESS;
        }

        $this->line("Volverían a un solo tablero {$columnas->count()} columnas.");

        if (! $this->option('aplicar')) {
            $this->comment('Vista previa. Añade --aplicar para escribirlo.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($empresa) {
            KanbanColumn::where('company_id', $empresa->id)
                ->update(['grupo' => null, 'es_bandeja' => false]);

            // La bandeja vuelve a ser la primera visible, que es como estaba
            // antes de agrupar nada.
            $primera = KanbanColumn::where('company_id', $empresa->id)
                ->whereNotNull('tag_id')
                ->orderBy('position')
                ->first();

            $primera?->update(['es_bandeja' => true]);
        });

        $this->info('Deshecho.');

        return self::SUCCESS;
    }

    private function tarjetas(KanbanColumn $columna): int
    {
        return $columna->tag_id
            ? DB::table('whatsapp_conversation_tag')->where('tag_id', $columna->tag_id)->count()
            : 0;
    }

    /** «COVEÑAS», « coveñas » y «Coveñas» son el mismo nombre. */
    private function normalizar(string $texto): string
    {
        $sinTildes = strtr(mb_strtolower(trim($texto)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return preg_replace('/\s+/', ' ', $sinTildes);
    }

    private function buscarEmpresa(string $referencia): ?Company
    {
        if (ctype_digit($referencia)) {
            return Company::find((int) $referencia);
        }

        return Company::where('name', $referencia)->first()
            ?? Company::where('name', 'like', "%{$referencia}%")->first();
    }
}
