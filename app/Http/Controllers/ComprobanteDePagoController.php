<?php

namespace App\Http\Controllers;

use App\Events\WhatsAppMessageEvent;
use App\Models\CompanyIntegration;
use App\Models\ComprobanteDePago;
use App\Models\WhatsAppMessage;
use App\Support\Documentos\ImagenDelCliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Las capturas de pago que mandan los clientes, leídas por el modelo y
 * esperando a que una persona las apruebe.
 *
 * Aprobar es lo único que registra el pago en Integra, y todo lo de aquí está
 * para que eso pase una sola vez y sobre la factura correcta:
 *
 * - **Permiso propio**, `pagos.aprobar`: registrar un pago reconecta al
 *   cliente en el router en el acto.
 * - **Reserva atómica** (`pendiente → aprobando` en un solo UPDATE): un doble
 *   clic, o dos asesores a la vez, no pagan dos veces.
 * - **La referencia viaja como `comprobante_pago`**: Integra rechaza una
 *   referencia repetida, así que reintentar tras un corte no duplica.
 * - **Nunca por encima del saldo**: Integra aplica sólo el saldo y el resto se
 *   pierde sin avisar (no queda como saldo a favor).
 * - **Si Integra no contesta a tiempo** queda en `revisar`, no en `pendiente`:
 *   el pago pudo entrar, y el login en MikroTik solo ya tarda 20 s.
 */
class ComprobanteDePagoController extends Controller
{
    /** Integra reconecta al cliente dentro de la misma petición. */
    private const TIMEOUT_PAGO = 60;

    public function index(Request $request)
    {
        $companyId = auth()->user()->company_id;
        $estado = $request->query('estado', 'abiertos');

        $query = ComprobanteDePago::where('company_id', $companyId)
            ->with([
                'conversacion:id,instance_id,name,phone_number,wa_id',
                'mensaje:id,media_url,content,created_at',
                'revisor:id,name',
            ])
            ->latest('id');

        if ($estado === 'abiertos') {
            $query->whereIn('estado', ComprobanteDePago::ABIERTOS);
        } elseif (in_array($estado, [ComprobanteDePago::APROBADO, ComprobanteDePago::RECHAZADO], true)) {
            $query->where('estado', $estado);
        }

        $conteos = ComprobanteDePago::where('company_id', $companyId)
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return Inertia::render('PagosPorAprobar/Index', [
            'comprobantes' => $query->limit(100)->get(),
            'estado' => $estado,
            'conteos' => $conteos,
            'leyendo' => CompanyIntegration::leeComprobantesDe($companyId),
            'vision' => ImagenDelCliente::configurado(),
        ]);
    }

    public function aprobar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'factura_id' => 'required|integer',
            'cuenta' => 'required|integer',
            'metodo_pago' => 'required|integer',
            'monto' => 'required|numeric|min:1',
            'fecha' => 'nullable|date|before_or_equal:today',
            'referencia' => 'nullable|string|max:120',
            'observaciones' => 'nullable|string|max:255',
        ]);

        $user = auth()->user();
        $comprobante = ComprobanteDePago::where('company_id', $user->company_id)->findOrFail($id);

        $integration = CompanyIntegration::where('company_id', $user->company_id)
            ->where('key', CompanyIntegration::KEY_INVOICE_PAYMENTS)
            ->first();

        if (! $integration || ! $integration->enabled || ! $integration->isConnected()) {
            return response()->json(['message' => 'La integración de pagos no está activa.'], 422);
        }

        if (! $comprobante->estaAbierto()) {
            return $this->yaResuelto($comprobante);
        }

        $referencia = trim((string) ($data['referencia'] ?? '')) ?: $comprobante->referencia;
        $comparable = ComprobanteDePago::referenciaComparable($referencia);

        // Dos comprobantes distintos con la misma referencia, aprobados a la
        // vez, pasarían los dos la comprobación de abajo: el candado es por
        // referencia, no por comprobante.
        $candado = Cache::lock('comprobante-ref:'.$user->company_id.':'.($comparable ?? 'id'.$comprobante->id), self::TIMEOUT_PAGO + 30);

        if (! $candado->get()) {
            return response()->json(['message' => 'Ya se está aprobando un comprobante con esa referencia. Espera un momento.'], 409);
        }

        try {
            return $this->aprobarConCandado($comprobante, $integration, $data, $referencia, $comparable);
        } finally {
            $candado->release();
        }
    }

    private function aprobarConCandado(
        ComprobanteDePago $comprobante,
        CompanyIntegration $integration,
        array $data,
        ?string $referencia,
        ?string $comparable
    ): JsonResponse {
        $user = auth()->user();

        if ($duplicado = $this->yaAprobado($comprobante, $comparable)) {
            return response()->json([
                'message' => "Este pago ya se aprobó el {$duplicado->revisado_at?->format('d/m/Y')} (comprobante #{$duplicado->id}, "
                    .($duplicado->factura_codigo ?? 'factura '.$duplicado->factura_id).'). No se registra dos veces.',
            ], 422);
        }

        $estadoAnterior = $comprobante->estado;

        // La reserva: si otro asesor —o el mismo con un doble clic— llegó
        // primero, esto actualiza cero filas y aquí se para.
        $tomado = ComprobanteDePago::whereKey($comprobante->id)
            ->where('company_id', $user->company_id)
            ->whereIn('estado', ComprobanteDePago::ABIERTOS)
            ->update([
                'estado' => ComprobanteDePago::APROBANDO,
                'revisado_por' => $user->id,
                'error' => null,
                'updated_at' => now(),
            ]);

        if ($tomado === 0) {
            return $this->yaResuelto($comprobante->fresh());
        }

        // La reserva fue un UPDATE directo: sin esto el modelo sigue creyendo
        // que está `pendiente`, y devolverlo a `pendiente` no guardaría nada
        // —Eloquent no ve cambio— dejándolo atascado en `aprobando`.
        $comprobante->refresh();

        $client = $integration->client();
        $monto = round((float) $data['monto'], 2);

        try {
            $factura = $client->invoice((int) $data['factura_id']);
        } catch (\RuntimeException $e) {
            return $this->devolver($comprobante, $estadoAnterior, $e->getMessage());
        }

        if ($factura === null) {
            return $this->devolver($comprobante, $estadoAnterior, 'Integra no encuentra esa factura.');
        }

        $porPagar = $factura['factura']['montos']['por_pagar'] ?? null;

        if ($porPagar !== null && (float) $porPagar <= 0) {
            return $this->devolver($comprobante, $estadoAnterior, 'Esa factura ya está pagada.');
        }

        if ($porPagar !== null && $monto > (float) $porPagar + 0.5) {
            return $this->devolver(
                $comprobante,
                $estadoAnterior,
                'El valor ('.$this->pesos($monto).') supera el saldo de la factura ('.$this->pesos((float) $porPagar).').'
                .' Integra aplicaría sólo el saldo y la diferencia se perdería: registra el saldo aquí y el resto en otra factura.'
            );
        }

        $codigo = $factura['factura']['codigo'] ?? null;

        $payload = [
            'cuenta' => (int) $data['cuenta'],
            'metodo_pago' => (int) $data['metodo_pago'],
            'monto' => $monto,
            'fecha' => $data['fecha'] ?? $comprobante->fecha?->toDateString(),
            // Sin referencia leída se inventa una estable: sigue sirviendo para
            // que Integra rechace el mismo comprobante aprobado dos veces.
            'comprobante_pago' => mb_substr($referencia ?: 'WA-'.$comprobante->id, 0, 120),
            'observaciones' => trim((string) ($data['observaciones'] ?? ''))
                ?: "Comprobante recibido por WhatsApp, aprobado por {$user->name} (CRM #{$comprobante->id})",
        ];

        if ($integration->emit_electronic_invoice) {
            $payload['emitir_electronica'] = true;
        }

        try {
            $resultado = $client->registerPayment((int) $data['factura_id'], $payload, self::TIMEOUT_PAGO);
        } catch (\RuntimeException $e) {
            // Código 0 = no hubo respuesta (corte, tiempo agotado). El pago pudo
            // entrar: no se devuelve a pendiente como si nada.
            if ($e->getCode() === 0) {
                $comprobante->update([
                    'estado' => ComprobanteDePago::REVISAR,
                    'factura_id' => (int) $data['factura_id'],
                    'factura_codigo' => $codigo,
                    'error' => 'Integra no respondió a tiempo: el pago pudo quedar registrado. Revísalo en Integra antes de volver a aprobar.',
                ]);

                $this->avisar($comprobante);

                return response()->json([
                    'message' => $comprobante->error,
                    'comprobante' => $comprobante->fresh(),
                ], 504);
            }

            return $this->devolver($comprobante, $estadoAnterior, $e->getMessage());
        }

        $comprobante->update([
            'estado' => ComprobanteDePago::APROBADO,
            'factura_id' => (int) $data['factura_id'],
            'factura_codigo' => $codigo,
            'monto_aprobado' => $resultado['monto_aplicado'] ?? $monto,
            'fecha' => $payload['fecha'],
            'referencia' => $referencia,
            'referencia_normalizada' => $comparable,
            'resultado' => $resultado,
            'revisado_at' => now(),
            'error' => null,
        ]);

        Log::channel('whatsapp')->info('🧾 Comprobante de pago aprobado', [
            'comprobante_id' => $comprobante->id,
            'company_id' => $comprobante->company_id,
            'user_id' => $user->id,
            'factura_id' => $data['factura_id'],
            'monto' => $monto,
            'recibo_caja' => $resultado['recibo_caja'] ?? null,
        ]);

        $nota = $this->nota($comprobante, '🧾 Pago aprobado por '.$user->name.': '
            .$this->pesos((float) ($resultado['monto_aplicado'] ?? $monto))
            .' a la factura '.($codigo ?? '#'.$data['factura_id'])
            .(isset($resultado['recibo_caja']) ? ' · recibo de caja #'.$resultado['recibo_caja'] : '')
            .($referencia ? ' · ref. '.$referencia : ''));

        $this->avisar($comprobante);

        return response()->json([
            'message' => 'Pago registrado en Integra.',
            'result' => $resultado,
            'comprobante' => $comprobante->fresh(),
            'nota' => $nota,
        ]);
    }

    public function rechazar(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['motivo' => 'required|string|max:255']);
        $user = auth()->user();

        $comprobante = ComprobanteDePago::where('company_id', $user->company_id)->findOrFail($id);

        $cambiadas = ComprobanteDePago::whereKey($comprobante->id)
            ->where('company_id', $user->company_id)
            ->whereIn('estado', ComprobanteDePago::ABIERTOS)
            ->update([
                'estado' => ComprobanteDePago::RECHAZADO,
                'revisado_por' => $user->id,
                'revisado_at' => now(),
                'motivo_rechazo' => $data['motivo'],
                'updated_at' => now(),
            ]);

        if ($cambiadas === 0) {
            return $this->yaResuelto($comprobante->fresh());
        }

        $comprobante->refresh();
        $nota = $this->nota($comprobante, '🧾 Comprobante rechazado por '.$user->name.': '.$data['motivo']);
        $this->avisar($comprobante);

        return response()->json(['comprobante' => $comprobante, 'nota' => $nota]);
    }

    /** El mismo pago —por referencia o por ser la misma imagen— ya aprobado. */
    private function yaAprobado(ComprobanteDePago $comprobante, ?string $comparable): ?ComprobanteDePago
    {
        return ComprobanteDePago::where('company_id', $comprobante->company_id)
            ->where('estado', ComprobanteDePago::APROBADO)
            ->where('id', '!=', $comprobante->id)
            ->where(function ($q) use ($comprobante, $comparable) {
                $q->where('imagen_sha256', $comprobante->imagen_sha256 ?? '__sin_imagen__');
                if ($comparable !== null) {
                    $q->orWhere('referencia_normalizada', $comparable);
                }
            })
            ->first();
    }

    /** Suelta la reserva y deja el comprobante como estaba, con el motivo. */
    private function devolver(ComprobanteDePago $comprobante, string $estado, string $error): JsonResponse
    {
        $comprobante->update(['estado' => $estado, 'error' => mb_substr($error, 0, 500)]);

        return response()->json(['message' => $error, 'comprobante' => $comprobante->fresh()], 422);
    }

    private function yaResuelto(ComprobanteDePago $comprobante): JsonResponse
    {
        $texto = match ($comprobante->estado) {
            ComprobanteDePago::APROBADO => 'Este comprobante ya se aprobó.',
            ComprobanteDePago::RECHAZADO => 'Este comprobante ya se rechazó.',
            default => 'Otra persona lo está aprobando en este momento.',
        };

        return response()->json(['message' => $texto, 'comprobante' => $comprobante], 409);
    }

    /** El rastro en el hilo: quién aprobó o rechazó, y qué. */
    private function nota(ComprobanteDePago $comprobante, string $texto): ?WhatsAppMessage
    {
        try {
            $nota = WhatsAppMessage::create([
                'conversation_id' => $comprobante->conversation_id,
                'type' => 'note',
                'content' => $texto,
                'direction' => 'internal',
                'is_internal' => true,
                'status' => 'sent',
                'sent_by' => auth()->id(),
                'sent_at' => now(),
            ]);

            $comprobante->conversacion?->touch();

            try {
                broadcast(new WhatsAppMessageEvent($nota->load('sender'), $comprobante->instance_id, 'new'));
            } catch (\Throwable) {
                // El poll del chat la recoge igual.
            }

            return $nota->load('sender:id,name');
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->warning('⚠️ No se pudo dejar la nota del comprobante', [
                'comprobante_id' => $comprobante->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Refresca la tarjeta bajo la foto en los chats abiertos. */
    private function avisar(ComprobanteDePago $comprobante): void
    {
        try {
            $mensaje = WhatsAppMessage::with(['sender', 'comprobanteDePago'])->find($comprobante->whatsapp_message_id);

            if ($mensaje) {
                broadcast(new WhatsAppMessageEvent($mensaje, $comprobante->instance_id, 'edited'));
            }
        } catch (\Throwable) {
            // Tiempo real caído: el chat lo verá al recargar.
        }
    }

    private function pesos(float $valor): string
    {
        return '$'.number_format($valor, 0, ',', '.');
    }
}
