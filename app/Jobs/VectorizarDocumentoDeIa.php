<?php

namespace App\Jobs;

use App\Models\AiDocumento;
use App\Models\AiFragmento;
use App\Services\Embeddings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Le calcula el vector a cada fragmento de un documento.
 *
 * **Se vuelve a despachar a sí mismo hasta acabar**, en lotes pequeños. Un
 * Excel de tres mil filas son tres mil llamadas al modelo: hacerlas en un solo
 * job significa un `timeout` gigante, y un job de veinte minutos que se cae a
 * los diecinueve pierde el trabajo entero. Por lotes, lo que se pierde es un
 * lote.
 *
 * Además el modelo corre en CPU en el mismo servidor que todo lo demás:
 * trocearlo le deja hueco al resto entre tanda y tanda.
 *
 * Mientras tanto el documento sigue en `procesando`, que es lo que la pantalla
 * enseña: hasta que tiene sus vectores no está de verdad listo para buscar.
 */
class VectorizarDocumentoDeIa implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * Cuántos fragmentos por tanda.
     *
     * Son varios lotes de `Embeddings::LOTE` por job: bastantes para no pagar
     * el arranque de un job por cada dieciséis textos, y pocos para que una
     * caída no se lleve medio documento.
     */
    private const POR_TANDA = 96;

    public function __construct(public int $documentoId) {}

    public function handle(): void
    {
        $documento = AiDocumento::find($this->documentoId);

        if (! $documento) {
            return;
        }

        // Si alguien apagó los embeddings a mitad, el documento se queda
        // utilizable: la búsqueda por palabras funciona sin vectores.
        if (! Embeddings::configurado()) {
            $documento->update(['estado' => 'listo', 'motivo' => null]);

            return;
        }

        $pendientes = AiFragmento::where('ai_documento_id', $documento->id)
            ->whereNull('vector')
            ->orderBy('orden')
            ->limit(self::POR_TANDA)
            ->get(['id', 'texto']);

        if ($pendientes->isEmpty()) {
            $documento->update(['estado' => 'listo', 'motivo' => null]);

            Log::channel('whatsapp')->info('🧮 Documento de IA vectorizado', [
                'documento' => $documento->id,
                'empresa' => $documento->company_id,
            ]);

            return;
        }

        $escritos = $this->vectorizar($pendientes);

        // Ninguno salió: el modelo está caído o rechazando. Reintentar en bucle
        // sólo lo machaca, y el documento sirve igual buscando por palabras.
        if ($escritos === 0) {
            $documento->update(['estado' => 'listo', 'motivo' => null]);

            Log::channel('whatsapp')->warning('⚠️ Un documento se quedó sin vectores; se buscará por palabras', [
                'documento' => $documento->id,
                'empresa' => $documento->company_id,
            ]);

            return;
        }

        // La siguiente tanda. Sin `delay`: la cola ya serializa, y esperar sólo
        // alarga el tiempo que el admin ve «Leyendo…».
        self::dispatch($documento->id);
    }

    /** @return int Cuántos vectores se llegaron a escribir. */
    private function vectorizar(Collection $fragmentos): int
    {
        $escritos = 0;

        foreach ($fragmentos->chunk(Embeddings::LOTE) as $lote) {
            $vectores = Embeddings::deVarios($lote->pluck('texto')->all());

            foreach ($lote->values() as $i => $fragmento) {
                if (($vectores[$i] ?? null) === null) {
                    continue;
                }

                // `update` directo y no `save()` sobre el modelo: sólo se
                // seleccionaron `id` y `texto`, y guardar el modelo escribiría
                // el resto de columnas con lo que no se leyó.
                AiFragmento::where('id', $fragmento->id)->update(['vector' => json_encode($vectores[$i])]);
                $escritos++;
            }
        }

        return $escritos;
    }

    public function failed(\Throwable $e): void
    {
        // Sin vectores, pero utilizable: la búsqueda por palabras no los
        // necesita. Dejarlo en «fallido» escondería un documento que sí sirve.
        AiDocumento::where('id', $this->documentoId)->update(['estado' => 'listo']);
    }
}
