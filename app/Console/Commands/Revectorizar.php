<?php

namespace App\Console\Commands;

use App\Jobs\VectorizarDocumentoDeIa;
use App\Models\AiDocumento;
use App\Models\AiFragmento;
use App\Services\Embeddings;
use Illuminate\Console\Command;

/**
 * Vuelve a calcular los vectores de los documentos.
 *
 * Hace falta sobre todo por un motivo: **los vectores de dos modelos distintos
 * no se pueden comparar entre sí**. El día que se cambie `EMBEDDINGS_MODEL` hay
 * que pasar por aquí, o la búsqueda seguirá funcionando —sin fallar, sin avisar—
 * devolviendo fragmentos que no tienen nada que ver con la pregunta.
 *
 * También sirve para los documentos que se subieron cuando no había modelo
 * configurado: se quedaron con sus fragmentos y sin vectores, buscándose por
 * palabras.
 */
class Revectorizar extends Command
{
    protected $signature = 'ia:revectorizar
        {--empresa= : Sólo los de esta empresa}
        {--todos : También los que ya tienen vector. Es lo que hay que usar al cambiar de modelo}';

    protected $description = 'Vuelve a calcular los vectores de los documentos de IA';

    public function handle(): int
    {
        if (! Embeddings::configurado()) {
            $this->error('No hay modelo de embeddings configurado (EMBEDDINGS_URL).');

            return self::FAILURE;
        }

        $documentos = AiDocumento::query()
            ->when($this->option('empresa'), fn ($q, $id) => $q->where('company_id', $id))
            ->where('fragmentos', '>', 0)
            ->orderBy('id')
            ->get();

        if ($documentos->isEmpty()) {
            $this->info('No hay documentos que vectorizar.');

            return self::SUCCESS;
        }

        foreach ($documentos as $documento) {
            if ($this->option('todos')) {
                // Se borran los vectores viejos antes de encolar: el job sólo
                // mira los que están a nulo, así que sin esto no haría nada y
                // el comando parecería haber funcionado.
                AiFragmento::where('ai_documento_id', $documento->id)->update(['vector' => null]);
            }

            $documento->update(['estado' => 'procesando']);
            VectorizarDocumentoDeIa::dispatch($documento->id);

            $this->line("  · {$documento->nombre} (empresa {$documento->company_id}, {$documento->fragmentos} fragmentos)");
        }

        $this->info($documentos->count().' documentos encolados. Se vectorizan en segundo plano.');

        return self::SUCCESS;
    }
}
