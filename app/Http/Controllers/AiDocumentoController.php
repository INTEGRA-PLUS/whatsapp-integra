<?php

namespace App\Http\Controllers;

use App\Jobs\ProcesarDocumentoDeIa;
use App\Models\AiDocumento;
use App\Models\Company;
use App\Services\Embeddings;
use App\Support\Documentos\BuscarFragmentos;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Los documentos con los que una empresa entrena a su IA.
 *
 * Primera entrega: subir, leer y ver la lista. Todavía **no cambia ninguna
 * respuesta** — los fragmentos se guardan pero nadie los consulta aún. Va así a
 * propósito: lo que más rompe de todo esto es leer PDFs y Excels del mundo real,
 * y esta entrega lo pone a prueba sin tocar lo que ya funciona.
 *
 * ## Los tres candados, y por qué son tres
 *
 * 1. **Permiso** (`whatsapp_menus.update`), en la ruta, como toda la pantalla.
 * 2. **Plan**: es una función del chat con IA, así que va detrás de
 *    `permiteFlujoIa('ai_chat')` y no de `tieneIa()`. Con el complemento
 *    Esencial no se entra: trae el semáforo y el resumen, que cuestan céntimos,
 *    pero no el chat.
 * 3. **Empresa**, en cada consulta. Aquí el aislamiento es manual y no hay
 *    global scopes: un `AiDocumento::find()` sin comprobar la empresa deja
 *    descargar el reglamento interno de otra ISP.
 */
class AiDocumentoController extends Controller
{
    /** GET /api/settings/ai-flow/documentos */
    public function index(Request $request): JsonResponse
    {
        $company = $this->empresa($request);

        return response()->json(['documentos' => $this->lista($company)]);
    }

    /**
     * POST /api/settings/ai-flow/documentos
     *
     * Guarda el archivo y despacha su lectura. Responde ya, con el documento en
     * `procesando`: leerlo aquí dejaría al admin esperando medio minuto largo.
     */
    public function store(Request $request): JsonResponse
    {
        $company = $this->empresa($request);
        $this->exigirPlan($company);

        // `extensions` y no `mimes`: un DOCX y un XLSX son zips, y el olfateo de
        // contenido devuelve `application/zip` para los dos. Con `mimes` se
        // rechazan ficheros de Office perfectamente válidos, que es peor que el
        // riesgo que evita — aquí la extensión no decide nada peligroso, sólo
        // qué lector se usa, y si el contenido no es lo que dice, ese lector
        // falla y el documento queda en «fallido» con su motivo.
        //
        // Lo que sí depende de esto es que el fichero nunca se ejecuta ni se
        // sirve con un tipo adivinado: vive en un disco privado y se descarga
        // por una ruta que comprueba la empresa.
        $request->validate([
            'archivo' => [
                'required',
                'file',
                'max:'.AiDocumento::MAXIMO_KB,
                'extensions:'.implode(',', AiDocumento::EXTENSIONES),
            ],
        ], [
            'archivo.max' => 'El archivo no puede pasar de 10 MB.',
            'archivo.extensions' => 'Sólo se aceptan PDF, Word, Excel, CSV y texto.',
        ]);

        // El tope se cuenta aquí y no en una regla de validación porque depende
        // de lo que ya hay guardado, y el mensaje tiene que decir qué hacer.
        $cuantos = AiDocumento::where('company_id', $company->id)->count();

        if ($cuantos >= AiDocumento::MAXIMO_POR_EMPRESA) {
            return response()->json([
                'message' => 'Ya tienes '.AiDocumento::MAXIMO_POR_EMPRESA
                    .' documentos. Borra uno para subir otro.',
            ], 422);
        }

        $archivo = $request->file('archivo');
        $extension = strtolower($archivo->getClientOriginalExtension());

        // El nombre en disco lo pone el servidor. El que escribió el cliente no
        // se usa nunca para construir una ruta: es la vía clásica de escribir
        // fuera de la carpeta con un `../` en el nombre.
        $ruta = "empresa-{$company->id}/".Str::uuid()->toString().'.'.$extension;

        Storage::disk('ai_documentos')->put($ruta, file_get_contents($archivo->getRealPath()));

        $documento = AiDocumento::create([
            'company_id' => $company->id,
            // El nombre original sí se guarda: se enseña en la pantalla y viaja
            // en la cita del prompt. Se recorta, no se limpia de rutas, porque
            // aquí es contenido y no un camino de fichero.
            'nombre' => mb_substr($archivo->getClientOriginalName(), 0, 180),
            'extension' => $extension,
            'bytes' => $archivo->getSize(),
            'ruta' => $ruta,
            'estado' => 'procesando',
            'subido_por' => $request->user()->id,
        ]);

        ProcesarDocumentoDeIa::dispatch($documento->id);

        Log::channel('whatsapp')->info('📄 Documento de IA subido', [
            'documento' => $documento->id,
            'empresa' => $company->id,
            'extension' => $extension,
            'kb' => (int) round($documento->bytes / 1024),
            'por' => $request->user()->id,
        ]);

        return response()->json(['documentos' => $this->lista($company)], 201);
    }

    /**
     * DELETE /api/settings/ai-flow/documentos/{documento}
     *
     * Borra de verdad, fichero incluido —lo hace el modelo—. En este proyecto no
     * hay `SoftDeletes` en ninguna parte y esto no va a ser la excepción: un
     * documento que la empresa quitó porque tenía precios viejos no puede
     * seguir en disco.
     */
    public function destroy(Request $request, AiDocumento $documento): JsonResponse
    {
        $company = $this->empresa($request);

        abort_unless($documento->company_id === $company->id, 404);

        $documento->delete();

        return response()->json(['documentos' => $this->lista($company)]);
    }

    /**
     * POST /api/settings/ai-flow/documentos/{documento}/reprocesar
     *
     * Para el que falló por algo pasajero. No vuelve a subir el archivo: sigue
     * en disco.
     */
    public function reprocesar(Request $request, AiDocumento $documento): JsonResponse
    {
        $company = $this->empresa($request);

        abort_unless($documento->company_id === $company->id, 404);
        $this->exigirPlan($company);

        $documento->update(['estado' => 'procesando', 'motivo' => null]);
        ProcesarDocumentoDeIa::dispatch($documento->id);

        return response()->json(['documentos' => $this->lista($company)]);
    }

    /**
     * GET /api/settings/ai-flow/documentos/{documento}/descargar
     *
     * El fichero no se enlaza nunca: se sirve desde aquí, después de comprobar
     * la empresa. Por eso vive en un disco privado y no en `s3_media`, que se
     * publica en una URL abierta.
     */
    public function descargar(Request $request, AiDocumento $documento): StreamedResponse
    {
        $company = $this->empresa($request);

        abort_unless($documento->company_id === $company->id, 404);
        abort_unless(Storage::disk('ai_documentos')->exists($documento->ruta), 404);

        return Storage::disk('ai_documentos')->download($documento->ruta, $documento->nombre);
    }

    /**
     * POST /api/settings/ai-flow/documentos/probar
     *
     * Enseña qué fragmentos saldrían para una pregunta, sin hablar con ningún
     * cliente.
     *
     * Es la pieza que convierte «he subido un PDF y no sé si sirve» en algo que
     * se puede comprobar en diez segundos. Sin esto, la única forma de saber si
     * la búsqueda encuentra el tarifario es esperar a que un cliente real
     * pregunte y leerse la conversación.
     *
     * **No cuenta como uso.** Si contara, el admin infla con sus propias
     * pruebas el número al que luego mira para decidir si un documento sirve.
     */
    public function probar(Request $request)
    {
        $company = $this->empresa($request);
        $this->exigirPlan($company);

        $datos = $request->validate(['pregunta' => 'required|string|max:500']);

        $encontrados = BuscarFragmentos::para(
            $company->id,
            $datos['pregunta'],
            BuscarFragmentos::CUANTOS,
            apuntar: false
        );

        return response()->json([
            'fragmentos' => $encontrados,
            // Para que el admin entienda un resultado pobre: sin modelo de
            // vectores se busca por palabras, y eso explica que «préstamo» no
            // encuentre el párrafo que habla de «crédito».
            'por_significado' => Embeddings::configurado(),
        ]);
    }

    private function empresa(Request $request): Company
    {
        $company = $request->user()?->company;

        abort_unless($company !== null, 403);

        return $company;
    }

    /**
     * 402 y no 403, como en el resto de la pantalla: no es un problema de
     * permisos sino de plan, y el frontend tiene que poder decir «contrátalo» en
     * vez de mandar al admin a pelearse con sus roles.
     */
    private function exigirPlan(Company $company): void
    {
        if (! PlanDeLaEmpresa::de($company)->permiteFlujoIa('ai_chat')) {
            abort(402, 'Los documentos van con la IA que responde los chats, que no está en tu plan.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function lista(Company $company): array
    {
        return AiDocumento::where('company_id', $company->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AiDocumento $d) => [
                'id' => $d->id,
                'nombre' => $d->nombre,
                'extension' => $d->extension,
                'tamano' => $d->tamano(),
                'estado' => $d->estado,
                'motivo' => $d->motivo,
                'fragmentos' => $d->fragmentos,
                'usos' => $d->usos,
                'ultimo_uso' => $d->ultimo_uso_at?->toIso8601String(),
                'subido_el' => $d->created_at?->toIso8601String(),
                // Se calcula aquí y no en el navegador porque el aviso de
                // «revísalo» es una decisión de producto, no de pintado: si
                // mañana pasa de seis meses a tres, se cambia en un sitio.
                'antiguo' => $d->esAntiguo(),
            ])
            ->all();
    }
}
