<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\KanbanColumn;
use App\Models\Tag;
use App\Models\WhatsAppConversation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Junta las columnas que se llaman igual dentro de una empresa.
 *
 * El 9-sep-2026 había 22 columnas repetidas en la flota: «CLIENTE» tres veces
 * en CMNET, «COVEÑAS» tres y «MONTERIA» dos en Star NET. Salieron del botón de
 * crear etapa, que no comprobaba el nombre (ya lo comprueba).
 *
 * No son sólo feas. Cada columna es una etiqueta distinta, así que las 11
 * conversaciones de un «COVEÑAS» y las 18 del otro son dos listas separadas que
 * nadie ve juntas, y la etiqueta que acaba viendo el agente en el chat depende
 * de cuál de las dos columnas gemelas se arrastró.
 *
 * Esto es destructivo —no hay SoftDeletes en el proyecto— así que sin
 * `--aplicar` sólo enseña lo que haría.
 */
class FusionarColumnasKanban extends Command
{
    protected $signature = 'kanban:fusionar-columnas
                            {empresa : Nombre o id de la empresa}
                            {--aplicar : Escribir los cambios; sin esto sólo se enseñan}';

    protected $description = 'Junta en una las columnas del tablero que se llaman igual';

    public function handle(): int
    {
        $empresa = $this->buscarEmpresa($this->argument('empresa'));

        if (! $empresa) {
            $this->error('No encuentro esa empresa.');

            return self::FAILURE;
        }

        $this->line("Empresa: <info>{$empresa->name}</info> (id {$empresa->id})");

        $repetidas = KanbanColumn::where('company_id', $empresa->id)
            ->orderBy('position')
            ->get()
            ->groupBy(fn ($c) => $this->normalizar($c->name))
            ->filter(fn ($grupo) => $grupo->count() > 1);

        if ($repetidas->isEmpty()) {
            $this->info('No hay ninguna columna repetida.');

            return self::SUCCESS;
        }

        $fusiones = [];

        foreach ($repetidas as $iguales) {
            // Se queda la que más tarjetas tiene: es la que la gente ha estado
            // usando de verdad, y así se mueven las menos posibles.
            $ordenadas = $iguales->sortByDesc(fn ($c) => $this->tarjetas($c))->values();
            $fusiones[] = ['queda' => $ordenadas->first(), 'absorbidas' => $ordenadas->slice(1)];
        }

        foreach ($fusiones as $f) {
            $this->newLine();
            $this->line("<info>{$f['queda']->name}</info> — se queda la id {$f['queda']->id} con {$this->tarjetas($f['queda'])} tarjetas");

            foreach ($f['absorbidas'] as $c) {
                $this->line("   absorbe la id {$c->id} ({$this->tarjetas($c)} tarjetas) y la borra");
            }
        }

        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->comment('Esto es sólo la vista previa. Añade --aplicar para escribirlo.');
            $this->comment('Ojo: borra columnas y etiquetas, y no hay papelera. Haz copia antes.');

            return self::SUCCESS;
        }

        $movidas = 0;

        DB::transaction(function () use ($fusiones, &$movidas) {
            foreach ($fusiones as $f) {
                foreach ($f['absorbidas'] as $absorbida) {
                    $movidas += $this->absorber($absorbida, $f['queda']);
                }
            }
        });

        $this->newLine();
        $this->info("Hecho: {$movidas} conversaciones movidas.");

        return self::SUCCESS;
    }

    /** Pasa las conversaciones de una columna a otra y borra la vacía. */
    private function absorber(KanbanColumn $absorbida, KanbanColumn $queda): int
    {
        $conversaciones = $absorbida->tag_id
            ? WhatsAppConversation::whereHas('tags', fn ($t) => $t->where('tags.id', $absorbida->tag_id))->get()
            : collect();

        foreach ($conversaciones as $conversacion) {
            // syncWithoutDetaching y no attach: muchas llevan ya las dos
            // etiquetas gemelas, y el pivote tiene índice único.
            if ($queda->tag_id) {
                $conversacion->tags()->syncWithoutDetaching([$queda->tag_id]);
            }
        }

        // La clave foránea de kanban_column_id es ON DELETE SET NULL: si se
        // borrara la columna antes, estas conversaciones se quedarían sin
        // ninguna y desaparecerían del tablero.
        WhatsAppConversation::where('kanban_column_id', $absorbida->id)
            ->update(['kanban_column_id' => $queda->id]);

        if ($absorbida->tag_id) {
            $etiqueta = Tag::find($absorbida->tag_id);
            $etiqueta?->conversations()->detach();
            $etiqueta?->delete();   // el TagObserver borra la columna
        }

        KanbanColumn::where('id', $absorbida->id)->delete();

        return $conversaciones->count();
    }

    private function tarjetas(KanbanColumn $columna): int
    {
        return $columna->tag_id
            ? DB::table('whatsapp_conversation_tag')->where('tag_id', $columna->tag_id)->count()
            : 0;
    }

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
