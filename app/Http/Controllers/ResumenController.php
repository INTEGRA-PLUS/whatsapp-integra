<?php

namespace App\Http\Controllers;

use App\Extensions\ResumenExtension;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\ResumenIaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El botón «Resumir» de la cabecera del chat.
 *
 * @see ResumenExtension Por qué es a petición y no automático.
 */
class ResumenController extends Controller
{
    public function __construct(private ResumenIaClient $ia) {}

    /**
     * Devuelve el resumen guardado, o lo genera si no hay o se quedó viejo.
     */
    public function resumir(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();

        // El preámbulo obligatorio del proyecto: `whatsapp_conversations` no
        // tiene `company_id`, así que se acota por las instancias de la empresa.
        // Sin esto, cualquiera puede resumir —y por tanto leer— la conversación
        // de otra empresa mandando un id a mano.
        $instanceIds = Instance::where('company_id', $user->company_id)->pluck('id');

        $conversation = WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->where('id', $conversationId)
            ->firstOrFail();

        $installed = CompanyExtension::where('company_id', $user->company_id)
            ->where('slug', (new ResumenExtension)->slug())
            ->where('enabled', true)
            ->first();

        if (! $installed) {
            return response()->json([
                'message' => 'La extensión de resumen no está encendida.',
            ], 409);
        }

        $ajustes = $installed->settings ?? [];
        $cuantos = max(10, min(200, (int) ($ajustes['mensajes'] ?? 40)));

        $ultimo = WhatsAppMessage::where('conversation_id', $conversation->id)->max('id');

        if (! $ultimo) {
            return response()->json(['message' => 'La conversación no tiene mensajes.'], 422);
        }

        // Si el resumen que hay cubre hasta el último mensaje, se devuelve tal
        // cual: no se gasta una inferencia en reescribir lo mismo. `refrescar`
        // es la salida para el caso en que el resultado no convenció.
        $alDia = $conversation->summary
            && (int) $conversation->summary_until_message_id === (int) $ultimo;

        if ($alDia && ! $request->boolean('refrescar')) {
            return response()->json($this->carga($conversation, true));
        }

        if (! ResumenIaClient::configured()) {
            return response()->json([
                'message' => 'El servicio de resumen no está configurado en la plataforma.',
            ], 503);
        }

        // Los últimos N por id y luego del derecho: al modelo hay que darle la
        // conversación en el orden en que ocurrió, no al revés.
        $mensajes = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->orderByDesc('id')
            ->limit($cuantos)
            ->get()
            ->reverse()
            ->values()
            ->all();

        if ($mensajes === []) {
            return response()->json(['message' => 'La conversación no tiene texto que resumir.'], 422);
        }

        $instance = Instance::find($conversation->instance_id);

        $resultado = $this->ia->resumir(
            $instance,
            $conversation,
            $mensajes,
            (string) ($ajustes['tono'] ?? 'telegrama')
        );

        if (! $resultado) {
            return response()->json([
                'message' => 'No se pudo generar el resumen. Inténtalo de nuevo en un momento.',
            ], 502);
        }

        $conversation->forceFill([
            'summary' => $resultado['resumen'],
            'summary_highlights' => [
                'puntos' => $resultado['puntos'],
                'pendientes' => $resultado['pendientes'],
            ],
            'summary_at' => now(),
            'summary_until_message_id' => $ultimo,
            'summary_by' => $user->id,
        ])->save();

        return response()->json($this->carga($conversation->refresh(), false));
    }

    /** @return array<string, mixed> */
    private function carga(WhatsAppConversation $conversation, bool $cacheado): array
    {
        $highlights = $conversation->summary_highlights ?? [];

        return [
            'resumen' => $conversation->summary,
            'puntos' => $highlights['puntos'] ?? [],
            'pendientes' => $highlights['pendientes'] ?? [],
            'generado_en' => optional($conversation->summary_at)->toIso8601String(),
            // Para que el frontend pueda decir «ya lo tenías» en vez de fingir
            // que acaba de pensarlo.
            'cacheado' => $cacheado,
        ];
    }
}
