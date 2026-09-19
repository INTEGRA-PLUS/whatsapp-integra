<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppConversation;
use App\Services\Integra;
use App\Support\FacturasDelCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Mandarle al cliente su factura desde el chat, en dos clics.
 *
 * Es la petición que más llega por WhatsApp —«mándame la factura»— y hasta
 * ahora el asesor tenía que rebuscar el PDF en el hilo, descargarlo y volverlo
 * a subir, o pedírselo a alguien de facturación.
 *
 * ## Lo que hace y lo que NO hace
 *
 * Prepara el envío; **no envía**. Devuelve la plantilla ya armada —el documento
 * en el encabezado y los cuatro datos en el cuerpo— y quien la manda es el
 * mismo `sendTemplate()` de siempre. Es a propósito: ahí están el guardarraíl
 * de parámetros de Meta, la burbuja en el hilo, el reclamo de la conversación y
 * la cola de entrega. Un segundo camino de envío sería un segundo sitio donde
 * arreglar lo mismo dos veces.
 *
 * ## Por qué sólo con Integra
 *
 * Porque el PDF y los importes salen del ERP. Sin Integra no hay factura que
 * mandar, y la plantilla `facturacion` tampoco existe en su cuenta: es una de
 * las dos que sólo se le ofrecen a quien lo tiene conectado.
 */
class FacturaRapidaController extends Controller
{
    /** La plantilla del catálogo por defecto que se usa para esto. */
    private const PLANTILLA = 'facturacion';

    /**
     * GET /api/chat/conversations/{conversation}/facturas
     *
     * Qué facturas se le pueden mandar a este cliente, la última primero.
     */
    public function index(WhatsAppConversation $conversation): JsonResponse
    {
        $this->autorizar($conversation);

        return response()->json([
            'facturas' => FacturasDelCliente::de($conversation),
        ]);
    }

    /**
     * POST /api/chat/conversations/{conversation}/facturas/{facturaId}/preparar
     *
     * Devuelve la plantilla lista para que la envíe `sendTemplate()`.
     */
    public function preparar(Request $request, WhatsAppConversation $conversation, int $facturaId): JsonResponse
    {
        $company = $this->autorizar($conversation);

        $factura = FacturasDelCliente::una($conversation, $facturaId);

        if ($factura === null) {
            return response()->json([
                'message' => 'Esa factura no está entre las de este cliente.',
            ], 404);
        }

        $detalle = $this->detalleEnIntegra($company->id, $facturaId);

        if ($detalle === null) {
            // Se dice y no se manda. Una factura con el importe en blanco o con
            // el de otra es peor que no mandarla: el cliente la cree y llama.
            return response()->json([
                'message' => 'No se pudo leer el importe de esta factura en Integra. Inténtalo de nuevo en un momento.',
            ], 422);
        }

        $nombreCliente = trim((string) ($conversation->contact?->name ?: $conversation->name)) ?: 'cliente';
        $plantilla = config('whatsapp_default_templates.'.self::PLANTILLA);

        return response()->json([
            'template_name' => self::PLANTILLA,
            'language_code' => $plantilla['language'] ?? 'es_CO',
            'preview' => 'Factura '.$detalle['codigo'].' · '.$detalle['total_texto'],
            'components' => [
                [
                    'type' => 'header',
                    'parameters' => [[
                        'type' => 'document',
                        'document' => ['link' => $factura['url'], 'filename' => $factura['archivo']],
                    ]],
                ],
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $nombreCliente],
                        ['type' => 'text', 'text' => $company->name],
                        ['type' => 'text', 'text' => $detalle['total_texto']],
                        ['type' => 'text', 'text' => $detalle['plazo']],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Importe y vencimiento, de Integra.
     *
     * No se sacan del PDF ni se guardan de cuando se envió: una factura se
     * abona y su saldo cambia, y el número que se le diga al cliente tiene que
     * ser el de hoy.
     *
     * @return array{codigo: string, total_texto: string, plazo: string}|null
     */
    private function detalleEnIntegra(int $companyId, int $facturaId): ?array
    {
        $cliente = Integra::for($companyId);

        if (! $cliente) {
            return null;
        }

        try {
            $detalle = $cliente->invoice($facturaId);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ No se pudo leer la factura en Integra', [
                'factura_id' => $facturaId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($detalle === null) {
            return null;
        }

        $factura = $detalle['factura'] ?? [];

        // Lo que falta por pagar, no el total: si ya abonó la mitad, decirle el
        // total es pedirle de más.
        $porPagar = (float) data_get($factura, 'montos.por_pagar', data_get($factura, 'montos.total', 0));

        $vencimiento = data_get($factura, 'vencimiento');

        return [
            'codigo' => (string) ($factura['codigo'] ?? $facturaId),
            'total_texto' => number_format($porPagar, 0, ',', '.'),
            'plazo' => $vencimiento
                ? 'antes del '.Carbon::parse($vencimiento)->locale('es')->isoFormat('D [de] MMMM')
                : 'lo antes posible',
        ];
    }

    /** La conversación es de esta empresa, y esta empresa tiene Integra. */
    private function autorizar(WhatsAppConversation $conversation)
    {
        $user = auth()->user();
        $conversation->loadMissing('instance', 'contact');

        abort_unless($conversation->instance?->company_id === $user->company_id, 403);
        abort_unless(Integra::connected($user->company_id), 403, 'Esta empresa no tiene Integra conectado.');

        return $user->company;
    }
}
