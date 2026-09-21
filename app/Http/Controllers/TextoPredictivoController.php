<?php

namespace App\Http\Controllers;

use App\Extensions\TextoPredictivoExtension;
use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\TextoPredictivoIaClient;
use App\Support\ContadorDeIa;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Las sugerencias de respuesta que aparecen sobre el cuadro de redacción.
 *
 * Síncrono y sin cola, a diferencia del semáforo: al otro lado hay un asesor
 * mirando la pantalla con el cliente esperando, no un job de fondo. Una
 * sugerencia que llega por websocket treinta segundos después llega cuando la
 * frase ya está escrita.
 *
 * @see TextoPredictivoExtension Por qué sugiere y nunca envía.
 */
class TextoPredictivoController extends Controller
{
    /**
     * Cuánto vive una tanda de sugerencias.
     *
     * La clave incluye el último mensaje y lo que el asesor llevara escrito, así
     * que un acierto de caché es literalmente «la misma pregunta»: cerrar el
     * chat y volver a abrirlo no puede costar una segunda inferencia.
     */
    private const CACHE_SEGUNDOS = 900;

    /**
     * Una inferencia cada diez segundos por conversación.
     *
     * No es un límite de abuso, es el botón. Pulsar «otra vez» tres veces
     * seguidas porque la primera tardó son tres inferencias pagadas para la
     * misma pregunta, y el asesor sólo va a leer la última.
     */
    private const ESPERA_ENTRE_INFERENCIAS = 10;

    public function __construct(private TextoPredictivoIaClient $ia) {}

    public function sugerir(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();

        // El preámbulo obligatorio del proyecto: `whatsapp_conversations` no
        // tiene `company_id`, así que se acota por las instancias de la empresa.
        // Sin esto, cualquiera puede pedir sugerencias sobre —y por tanto leer,
        // porque la sugerencia parafrasea— la conversación de otra empresa
        // mandando un id a mano.
        $instanceIds = Instance::where('company_id', $user->company_id)->pluck('id');

        $conversation = WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->where('id', $conversationId)
            ->firstOrFail();

        $installed = CompanyExtension::where('company_id', $user->company_id)
            ->where('slug', (new TextoPredictivoExtension)->slug())
            ->where('enabled', true)
            ->first();

        if (! $installed) {
            return response()->json([
                'message' => 'La extensión de texto predictivo no está encendida.',
            ], 409);
        }

        // El plan se comprueba AQUÍ y no sólo al instalar, por lo mismo que en
        // el resumen: el candado de `ExtensionController` impide instalar lo que
        // no se contrató, pero no toca lo que ya estaba instalado. Sin esta
        // línea, una empresa que pierde el complemento sigue gastando tokens
        // cada vez que alguien abre un chat.
        //
        // 402 y no 403: no es un problema de permisos sino de plan, y el
        // frontend tiene que poder decir «contrata el complemento» en vez de
        // «no tienes acceso».
        if (! PlanDeLaEmpresa::de(Company::findOrFail($user->company_id))->tieneIa()) {
            return response()->json([
                'message' => 'El texto predictivo no está incluido en tu plan.',
            ], 402);
        }

        // Con la ventana vencida no se puede enviar texto libre: lo único que
        // sale es una plantilla aprobada, que ya elige el asesor de una lista.
        // Sugerir aquí es pagar una inferencia por tres frases que la API de
        // Meta va a rechazar.
        if (! $conversation->isWindowOpen()) {
            return response()->json([
                'message' => 'La ventana de 24 horas está cerrada: sólo se puede enviar una plantilla.',
                'code' => 'window_closed',
            ], 409);
        }

        $ajustes = $installed->settings();
        $cuantas = max(1, min(5, (int) ($ajustes['cuantas'] ?? 3)));
        $cuantos = max(6, min(40, (int) ($ajustes['mensajes'] ?? 16)));

        $borrador = trim((string) $request->input('borrador', ''));

        // Los últimos N por id, del más reciente hacia atrás: el flujo les da la
        // vuelta antes de armar el prompt.
        //
        // Sólo lo que se dijeron de verdad: los avisos de sistema —«conversación
        // cerrada por Yohan»— son `internal`, y el modelo los leía como parte de
        // la charla y sugería responderles.
        $mensajes = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->orderByDesc('id')
            ->limit($cuantos)
            ->get()
            ->all();

        if ($mensajes === []) {
            return response()->json([
                'message' => 'La conversación no tiene texto sobre el que sugerir.',
            ], 422);
        }

        // La huella de la pregunta. Si no ha cambiado ni el último mensaje ni lo
        // que el asesor lleva escrito, la respuesta es la misma y ya está
        // pagada.
        $clave = 'predictivo:sug:'.$conversation->id
            .':'.$mensajes[0]->id
            .':'.substr(md5($borrador), 0, 8)
            .':'.$cuantas;

        if (! $request->boolean('refrescar') && ($guardadas = Cache::get($clave)) !== null) {
            return response()->json([
                'sugerencias' => $guardadas,
                'cacheado' => true,
            ]);
        }

        if (! TextoPredictivoIaClient::configured()) {
            return response()->json([
                'message' => 'El servicio de texto predictivo no está configurado en la plataforma.',
            ], 503);
        }

        // `Cache::add` es atómico: sirve de reloj y de candado a la vez, así que
        // dos pestañas del mismo asesor pidiendo a la vez gastan una inferencia,
        // no dos.
        if (! Cache::add('predictivo:gasto:'.$conversation->id, true, self::ESPERA_ENTRE_INFERENCIAS)) {
            return response()->json([
                'message' => 'Dale un momento: ya se están preparando las sugerencias.',
            ], 429);
        }

        $instance = Instance::find($conversation->instance_id);

        $sugerencias = $this->ia->sugerir(
            $instance,
            $conversation,
            $mensajes,
            $cuantas,
            (string) ($ajustes['instrucciones'] ?? ''),
            $borrador
        );

        // Se apunta el gasto aunque no haya salido ninguna sugerencia: la
        // inferencia se pidió y se pagó igual. Contar sólo los aciertos haría
        // que el flujo más roto pareciera el más barato.
        //
        // `conversacionNueva: false` a propósito: esto no abre una conversación
        // de IA, acompaña a una que ya existe. Lo que se vende por conversación
        // es el chat con IA, no cada vez que un asesor mira tres borradores.
        ContadorDeIa::apuntar($user->company_id, 'predictivo');

        // La tanda vacía también se guarda, y con el mismo tiempo. Si el modelo
        // no ve nada que sugerir para este mensaje, no lo va a ver mejor treinta
        // segundos después: sin esto, un chat en el que no hay nada que sugerir
        // pide una inferencia cada vez que alguien lo abre.
        Cache::put($clave, $sugerencias, self::CACHE_SEGUNDOS);

        return response()->json([
            'sugerencias' => $sugerencias,
            'cacheado' => false,
        ]);
    }
}
