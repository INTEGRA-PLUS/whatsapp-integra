<?php

namespace App\Jobs;

use App\Models\AiDocumento;
use App\Models\AiFragmento;
use App\Services\Embeddings;
use App\Support\Documentos\DocumentoIlegible;
use App\Support\Documentos\ExtraerTexto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Lee el documento que subió una empresa y lo parte en fragmentos.
 *
 * Va en cola y no en la petición porque un PDF de 200 páginas son decenas de
 * segundos: dejar al admin mirando una rueda es el camino a que recargue, vuelva
 * a subir y acabe con el mismo documento tres veces.
 *
 * Mientras tanto la fila existe con estado `procesando`, que es lo que la
 * pantalla enseña. **El estado final siempre se escribe**, también cuando falla:
 * un documento que se queda en `procesando` para siempre es indistinguible de
 * uno que tarda, y nadie sabe si esperar o volver a subirlo.
 */
class ProcesarDocumentoDeIa implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Un solo intento.
     *
     * Reintentar no arregla nada de lo que falla aquí: un PDF escaneado seguirá
     * sin tener texto la tercera vez, y mientras tanto el documento se queda en
     * «procesando» un cuarto de hora sin que el admin entienda por qué.
     */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $documentoId) {}

    public function handle(): void
    {
        $documento = AiDocumento::find($this->documentoId);

        if (! $documento) {
            return;
        }

        $disco = Storage::disk('ai_documentos');

        if (! $disco->exists($documento->ruta)) {
            $this->marcarFallido($documento, 'El archivo ya no está en el servidor.');

            return;
        }

        try {
            $trozos = ExtraerTexto::de(
                $disco->path($documento->ruta),
                $documento->extension,
                $documento->nombre
            );
        } catch (DocumentoIlegible $e) {
            // Este mensaje está escrito para el admin y se le enseña tal cual.
            $this->marcarFallido($documento, $e->getMessage());

            return;
        } catch (\Throwable $e) {
            // El resto sí es cosa nuestra: al admin se le da algo accionable y
            // el detalle técnico se queda en el log.
            Log::channel('whatsapp')->error('⚠️ No se pudo procesar un documento de IA', [
                'documento' => $documento->id,
                'empresa' => $documento->company_id,
                'extension' => $documento->extension,
                'error' => $e->getMessage(),
            ]);

            $this->marcarFallido($documento, 'No se pudo leer el archivo. Vuelve a subirlo o prueba con otro formato.');

            return;
        }

        $this->guardar($documento, $trozos);

        Log::channel('whatsapp')->info('📄 Documento de IA procesado', [
            'documento' => $documento->id,
            'empresa' => $documento->company_id,
            'fragmentos' => count($trozos),
        ]);
    }

    /**
     * Guarda los fragmentos y deja el documento listo, o ninguna de las dos.
     *
     * En una transacción porque un documento en `listo` con la mitad de sus
     * fragmentos es peor que uno fallido: nadie va a volver a procesarlo, y la
     * IA va a contestar con media verdad sin que nada lo señale.
     *
     * @param  list<array{texto: string, origen: ?string}>  $trozos
     */
    private function guardar(AiDocumento $documento, array $trozos): void
    {
        DB::transaction(function () use ($documento, $trozos) {
            // Se borra lo anterior porque este job también corre al reprocesar
            // un documento; sin esto, reprocesar duplica cada fragmento y la
            // búsqueda devuelve el mismo párrafo dos veces.
            AiFragmento::where('ai_documento_id', $documento->id)->delete();

            $ahora = now();
            $filas = [];

            foreach ($trozos as $orden => $trozo) {
                $filas[] = [
                    'ai_documento_id' => $documento->id,
                    // Repetido a propósito: la búsqueda filtra por aquí en cada
                    // mensaje entrante, sin pasar por el documento.
                    'company_id' => $documento->company_id,
                    'origen' => $trozo['origen'],
                    'orden' => $orden,
                    'texto' => $trozo['texto'],
                    'vector' => null,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }

            foreach (array_chunk($filas, 500) as $lote) {
                AiFragmento::insert($lote);
            }

            // Sigue en `procesando` si hay que vectorizar: hasta que tiene
            // sus vectores el documento no está listo para buscar, y decir
            // «Listo» antes de tiempo es prometer respuestas que todavía no
            // salen. Sin modelo configurado se busca por palabras, que no
            // necesita nada más: ahí sí está listo ya.
            $documento->update([
                'estado' => Embeddings::configurado() ? 'procesando' : 'listo',
                'motivo' => null,
                'fragmentos' => count($filas),
            ]);
        });

        if (Embeddings::configurado()) {
            VectorizarDocumentoDeIa::dispatch($documento->id);
        }
    }

    private function marcarFallido(AiDocumento $documento, string $motivo): void
    {
        $documento->update(['estado' => 'fallido', 'motivo' => $motivo, 'fragmentos' => 0]);
    }

    /**
     * Si el job revienta del todo —timeout, memoria— el documento no se queda
     * colgado en «procesando».
     */
    public function failed(\Throwable $e): void
    {
        AiDocumento::where('id', $this->documentoId)->update([
            'estado' => 'fallido',
            'motivo' => 'El archivo es demasiado grande o complejo para procesarlo.',
            'fragmentos' => 0,
        ]);
    }
}
