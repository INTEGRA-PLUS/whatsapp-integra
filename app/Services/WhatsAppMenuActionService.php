<?php

namespace App\Services;

use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\WhatsAppBotFlow;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenuOption;
use App\Support\MenuActionResult;
use Illuminate\Support\Facades\Log;

/**
 * Las acciones de negocio del menú: consultar facturas, pagar, reportar una
 * falla y revisar el estado del servicio, todas contra la API de Integra 2.0.
 *
 * Este servicio decide y devuelve texto; no envía nada. El envío, el registro
 * en el chat y la asignación los hace ProcessWhatsAppMenu, que es quien ya
 * sabía hacerlo para las acciones simples.
 *
 * La conexión con Integra se toma de la integración "Pagos a facturas" de la
 * empresa: es el mismo entorno y el mismo token, y obligar a conectar dos veces
 * el mismo Integra sólo produce empresas con una de las dos caducada.
 */
class WhatsAppMenuActionService
{
    /** Cuántos contratos se listan cuando hay que preguntar cuál es. */
    private const MAX_CONTRACT_CHOICES = 8;

    /**
     * Ejecuta la acción recién elegida en el menú.
     */
    public function execute(
        Instance $instance,
        WhatsAppConversation $conversation,
        WhatsAppMenuOption $option
    ): MenuActionResult {
        $client = $this->client($instance->company_id);

        if (!$client) {
            return $this->unavailable($option, $conversation, 'la integración con Integra no está conectada');
        }

        try {
            $contact = $this->resolveContact($client, $conversation);
        } catch (\RuntimeException $e) {
            return $this->integraFailed($option, $conversation, $e);
        }

        if (!$contact) {
            return $this->askIdentification($option->action_type, $option->id, 0);
        }

        return $this->run($client, $instance, $conversation, $option, $option->action_type, $contact, []);
    }

    /**
     * El cliente contestó a lo que le preguntamos. Devuelve la acción al punto
     * en el que se quedó.
     */
    public function resume(
        Instance $instance,
        WhatsAppConversation $conversation,
        WhatsAppBotFlow $flow,
        string $text
    ): MenuActionResult {
        $option = $flow->option;
        $client = $this->client($instance->company_id);

        if (!$client) {
            return $this->unavailable($option, $conversation, 'la integración con Integra no está conectada');
        }

        return match ($flow->step) {
            WhatsAppBotFlow::STEP_IDENTIFICATION => $this->resumeIdentification($client, $instance, $conversation, $flow, $option, $text),
            WhatsAppBotFlow::STEP_CONTRACT => $this->resumeContract($client, $instance, $conversation, $flow, $option, $text),
            WhatsAppBotFlow::STEP_REPORT => $this->createTicket($client, $instance, $conversation, $option, $flow->context ?? [], $text),
            default => MenuActionResult::silent(),
        };
    }

    /**
     * Reparte la acción entre los manejadores. `$state` arrastra lo ya sabido
     * (el contrato que el cliente eligió, por ejemplo) cuando se retoma un
     * flujo a medias.
     */
    private function run(
        IntegraClient $client,
        Instance $instance,
        WhatsAppConversation $conversation,
        ?WhatsAppMenuOption $option,
        string $actionType,
        array $contact,
        array $state
    ): MenuActionResult {
        try {
            return match ($actionType) {
                'consultar_factura' => $this->invoices($instance, $conversation, $option, $contact),
                'pagar_en_linea' => $this->payment($instance, $conversation, $option, $contact),
                'estado_servicio' => $this->serviceStatus($client, $instance, $conversation, $option, $contact, $state),
                'reportar_falla' => $this->reportFailure($client, $instance, $conversation, $option, $contact, $state),
                default => MenuActionResult::silent(),
            };
        } catch (\RuntimeException $e) {
            return $this->integraFailed($option, $conversation, $e);
        }
    }

    // ------------------------------------------------------------------
    // Acciones
    // ------------------------------------------------------------------

    /**
     * Facturas pendientes del cliente.
     *
     * Salen del propio contacto: /contactos/buscar ya devuelve las pendientes
     * resumidas, así que no hace falta una segunda llamada a /facturas.
     */
    private function invoices(
        Instance $instance,
        WhatsAppConversation $conversation,
        ?WhatsAppMenuOption $option,
        array $contact
    ): MenuActionResult {
        $invoices = $this->pendingInvoices($contact);
        $total = $this->totalDue($contact);

        $this->emit($instance, $conversation, 'invoice.queried', [
            'cliente' => $this->clientPayload($contact),
            'facturas_pendientes' => count($invoices),
            'total_por_pagar' => $total,
        ]);

        if (!$invoices) {
            return MenuActionResult::reply(
                "✅ *{$this->contactName($contact)}*, no tienes facturas pendientes. ¡Estás al día!"
            );
        }

        $lines = ["📄 *Tus facturas pendientes*", ''];

        foreach ($invoices as $invoice) {
            $lines[] = $this->invoiceLine($invoice);
        }

        $lines[] = '';
        $lines[] = '*Total por pagar: ' . $this->money($total) . '*';

        return MenuActionResult::reply($this->withNote($lines, $option, $conversation));
    }

    /**
     * Pagar en línea.
     *
     * Aquí no se cobra nada: se avisa a los sistemas de la empresa por el
     * webhook saliente `payment.requested` —que es quien sabe generar el cobro—
     * y se le entrega al cliente el enlace de pago configurado en la opción.
     * Sin enlace configurado el cliente se queda sin nada accionable, así que
     * se le dice el total y que un asesor le pasa el link.
     */
    private function payment(
        Instance $instance,
        WhatsAppConversation $conversation,
        ?WhatsAppMenuOption $option,
        array $contact
    ): MenuActionResult {
        $invoices = $this->pendingInvoices($contact);
        $total = $this->totalDue($contact);

        $this->emit($instance, $conversation, 'payment.requested', [
            'cliente' => $this->clientPayload($contact),
            'total_por_pagar' => $total,
            'facturas' => array_map(fn ($i) => [
                'codigo' => $i['codigo'] ?? null,
                'por_pagar' => (float) ($i['por_pagar'] ?? 0),
                'vencimiento' => $i['vencimiento'] ?? null,
                'contrato_nro' => $i['contrato_nro'] ?? null,
            ], $invoices),
        ]);

        if (!$invoices) {
            return MenuActionResult::reply(
                "✅ *{$this->contactName($contact)}*, no tienes facturas pendientes. No hay nada que pagar por ahora."
            );
        }

        $lines = [
            '💳 *Pago en línea*',
            '',
            'Tienes ' . count($invoices) . ' factura' . (count($invoices) === 1 ? '' : 's')
                . ' pendiente' . (count($invoices) === 1 ? '' : 's') . ' por *' . $this->money($total) . '*.',
        ];

        $url = $option?->setting('payment_url');

        if ($url) {
            $lines[] = '';
            $lines[] = 'Paga aquí 👉 ' . $this->fillPaymentVars($url, $contact, $invoices, $total);
        } else {
            $lines[] = '';
            $lines[] = 'Ya registramos tu solicitud de pago. En un momento te compartimos el enlace.';
        }

        return MenuActionResult::reply($this->withNote($lines, $option, $conversation));
    }

    /**
     * Estado de internet y televisión del contrato.
     *
     * Es la consulta que más soporte ahorra: la mayoría de los "no tengo
     * internet" son un corte por mora, y decirlo al instante evita el radicado
     * que un técnico iba a cerrar sin salir a la calle.
     */
    private function serviceStatus(
        IntegraClient $client,
        Instance $instance,
        WhatsAppConversation $conversation,
        ?WhatsAppMenuOption $option,
        array $contact,
        array $state
    ): MenuActionResult {
        $contract = $this->pickContract($contact, $state);

        if ($contract === null) {
            return $this->contractQuestion($contact, 'estado_servicio', $option?->id);
        }

        if ($contract === []) {
            return MenuActionResult::escalate(
                'No encuentro un servicio activo a tu nombre. Te comunico con un asesor para revisarlo.'
            );
        }

        $status = $client->contractStatus((string) $contract['nro']);
        $segment = $option?->statusSegment() ?? WhatsAppMenuOption::SEGMENT_SUMMARY;

        $this->emit($instance, $conversation, 'service.checked', [
            'cliente' => $this->clientPayload($contact),
            'contrato_nro' => $contract['nro'],
            'segmento' => $segment,
            'internet_activo' => (bool) data_get($status, 'estado.internet.activo'),
        ]);

        if (!$status) {
            return MenuActionResult::escalate(
                'No pude consultar el estado de tu servicio. Te comunico con un asesor.'
            );
        }

        return MenuActionResult::reply($this->withNote(
            $this->segmentLines($segment, $status, $contract),
            $option,
            $conversation
        ));
    }

    /**
     * El trozo del contrato que pidió el cliente.
     *
     * Una sola llamada a Integra trae todo —internet, televisión, plan, fechas
     * y facturas—, así que segmentar no cuesta consultas: cuesta sólo elegir
     * qué contar. Y contar sólo lo que preguntaron es la diferencia entre un
     * mensaje que se lee y un muro de texto que se ignora.
     *
     * @return string[]
     */
    private function segmentLines(string $segment, array $status, array $contract): array
    {
        return match ($segment) {
            'internet' => $this->internetLines($status, $contract),
            'facturas' => $this->contractInvoiceLines($status),
            'plan' => $this->planLines($status),
            'corte' => $this->cutoffLines($status),
            'television' => $this->televisionLines($status),
            'datos' => $this->contractDataLines($status, $contract),
            default => $this->statusLines($status, $contract),
        };
    }

    /**
     * Estado de internet, con el motivo cuando está cortado.
     *
     * Decir sólo "suspendido" deja al cliente igual de perdido que antes: lo
     * que resuelve la consulta es la causa y qué tiene que hacer para salir de
     * ahí. Y si el sistema lo ve activo pero el cliente no navega, hay que
     * decirle expresamente que reporte la falla, o se queda esperando.
     */
    private function internetLines(array $status, array $contract): array
    {
        $active = (bool) data_get($status, 'estado.internet.activo');
        $due = (float) data_get($status, 'facturas_pendientes.total_por_pagar', 0);

        $lines = [
            '🌐 *Estado de tu internet*',
            '',
            'Contrato ' . (data_get($status, 'contrato.nro') ?? $contract['nro'] ?? '—')
                . ' — ' . ($active ? '✅ *Activo*' : '⛔ *Suspendido*'),
        ];

        if (!$active) {
            if ($since = data_get($status, 'contrato.fecha_suspension')) {
                $lines[] = 'Suspendido desde el ' . $this->date($since);
            }

            $lines[] = '';
            $lines[] = $due > 0
                ? 'Tienes *' . $this->money($due) . '* en facturas pendientes. En cuanto registremos el pago, el servicio se reactiva automáticamente.'
                : 'No aparecen facturas pendientes, así que la suspensión tiene otro motivo. Te recomiendo reportarlo desde el menú.';

            return $lines;
        }

        $lines[] = '';
        $lines[] = 'Tu servicio está habilitado desde nuestro lado. Si aun así no tienes conexión, reinicia el router y, si sigue igual, repórtalo desde el menú para que lo revise un técnico.';

        if ($due > 0) {
            $lines[] = '';
            $lines[] = '⚠️ Ojo: tienes *' . $this->money($due) . '* pendientes'
                . (($cut = data_get($status, 'contrato.fecha_corte')) ? '. Fecha de corte: ' . $this->date($cut) : '.');
        }

        return $lines;
    }

    /**
     * Facturas de este contrato, no del cliente entero: quien entra por
     * "estado de mi contrato" pregunta por el servicio que tiene delante, y un
     * cliente con dos contratos no quiere la suma de los dos.
     *
     * @return string[]
     */
    private function contractInvoiceLines(array $status): array
    {
        $items = (array) data_get($status, 'facturas_pendientes.items', []);
        $due = (float) data_get($status, 'facturas_pendientes.total_por_pagar', 0);

        if (!$items && $due <= 0) {
            return ['✅ *Sin facturas pendientes*', '', 'Este contrato está al día. ¡Gracias!'];
        }

        $lines = ['📄 *Facturas pendientes de este contrato*', ''];

        foreach ($items as $invoice) {
            if (is_array($invoice)) {
                $lines[] = $this->invoiceLine($this->flattenInvoice($invoice));
            }
        }

        $lines[] = '';
        $lines[] = '*Total por pagar: ' . $this->money($due) . '*';

        return $lines;
    }

    /**
     * El plan contratado. Responde al "¿de cuántas megas soy?" que llega cada
     * vez que alguien hace un test de velocidad.
     *
     * @return string[]
     */
    private function planLines(array $status): array
    {
        $lines = ['⚡ *Tu plan contratado*', ''];

        $lines[] = 'Plan: *' . (data_get($status, 'contrato.plan.nombre') ?: 'sin plan registrado') . '*';

        $down = data_get($status, 'contrato.plan.descarga');
        $up = data_get($status, 'contrato.plan.subida');

        if ($down || $up) {
            $lines[] = 'Velocidad: ' . $this->speed($down) . ' de bajada · ' . $this->speed($up) . ' de subida';
        }

        if ($tech = data_get($status, 'contrato.tecnologia')) {
            $lines[] = 'Tecnología: ' . $this->technologyLabel($tech);
        }

        if ($price = data_get($status, 'contrato.plan.precio')) {
            $lines[] = 'Valor mensual: ' . $this->money((float) $price);
        }

        if (data_get($status, 'estado.television.tiene_servicio')) {
            $lines[] = '';
            $lines[] = 'Televisión: ' . (data_get($status, 'estado.television.plan') ?: 'incluida');
        }

        $lines[] = '';
        $lines[] = 'La velocidad se mide por cable y con un solo equipo conectado; por WiFi siempre llega menos.';

        return $lines;
    }

    /**
     * Cuándo vence y cuándo cortan. Es la pregunta que más veces contesta un
     * asesor mirando el grupo de corte del cliente.
     *
     * @return string[]
     */
    private function cutoffLines(array $status): array
    {
        $due = (float) data_get($status, 'facturas_pendientes.total_por_pagar', 0);
        $cut = data_get($status, 'contrato.fecha_corte');
        $suspend = data_get($status, 'contrato.fecha_suspension');
        $period = data_get($status, 'contrato.grupo_corte');

        $lines = ['📅 *Fechas de tu servicio*', ''];

        if ($period) {
            $lines[] = 'Periodo de facturación: ' . $period;
        }

        if ($cut) {
            $lines[] = 'Fecha de corte: *' . $this->date($cut) . '*';
        }

        if ($suspend) {
            $lines[] = 'Fecha de suspensión: ' . $this->date($suspend);
        }

        if (!$period && !$cut && !$suspend) {
            $lines[] = 'No tengo fechas de corte registradas para este contrato.';
        }

        $lines[] = '';
        $lines[] = $due > 0
            ? 'Tienes *' . $this->money($due) . '* por pagar' . ($cut ? '. Paga antes del ' . $this->date($cut) . ' para no perder el servicio.' : '.')
            : 'No tienes facturas pendientes, así que no hay riesgo de corte. 👌';

        return $lines;
    }

    /** @return string[] */
    private function televisionLines(array $status): array
    {
        if (!data_get($status, 'estado.television.tiene_servicio')) {
            return [
                '📺 *Televisión*',
                '',
                'Este contrato no tiene televisión contratada. Si quieres añadirla, pide hablar con un asesor desde el menú.',
            ];
        }

        $lines = [
            '📺 *Tu servicio de televisión*',
            '',
            'Estado: ' . (data_get($status, 'estado.television.activo') ? '✅ activo' : '⛔ inactivo'),
        ];

        if ($plan = data_get($status, 'estado.television.plan')) {
            $lines[] = 'Plan: ' . $plan;
        }

        return $lines;
    }

    /**
     * Número de contrato y dirección. Parece menor, pero el número de contrato
     * es lo que le piden al cliente en el corresponsal bancario y casi nadie
     * se lo sabe de memoria.
     *
     * @return string[]
     */
    private function contractDataLines(array $status, array $contract): array
    {
        $lines = [
            '📍 *Datos de tu contrato*',
            '',
            'Número de contrato: *' . (data_get($status, 'contrato.nro') ?? $contract['nro'] ?? '—') . '*',
        ];

        if ($address = data_get($status, 'contrato.direccion')) {
            $lines[] = 'Dirección de instalación: ' . $address;
        }

        if ($service = data_get($status, 'contrato.servicio')) {
            $lines[] = 'Servicio: ' . $service;
        }

        $lines[] = '';
        $lines[] = 'Con el número de contrato puedes pagar en cualquiera de nuestros puntos autorizados.';

        return $lines;
    }

    /**
     * Las facturas del contrato llegan con los montos anidados (`montos.por_pagar`)
     * y las del contacto planas (`por_pagar`). Se igualan aquí para que una sola
     * función sepa pintar una línea de factura.
     */
    private function flattenInvoice(array $invoice): array
    {
        return [
            'codigo' => $invoice['codigo'] ?? null,
            'por_pagar' => $invoice['por_pagar'] ?? data_get($invoice, 'montos.por_pagar', 0),
            'vencimiento' => $invoice['vencimiento'] ?? null,
        ];
    }

    private function speed($value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '—';
        }

        // Integra manda unas veces el número pelado y otras "300 Mbps".
        return preg_match('/[a-z]/i', $value) ? $value : $value . ' Mbps';
    }

    private function technologyLabel(string $tech): string
    {
        return match (strtolower($tech)) {
            'fibra' => 'Fibra óptica',
            'inalambrica' => 'Inalámbrica',
            'cableado_utp' => 'Cable UTP',
            default => ucfirst($tech),
        };
    }

    /**
     * Reportar una falla.
     *
     * Antes de abrir el radicado se mira el estado del contrato: si el servicio
     * está suspendido y hay facturas pendientes, la falla no existe —está
     * cortado— y el radicado sólo le haría perder el viaje a un técnico. En ese
     * caso se le dice al cliente lo que debe y no se crea nada.
     */
    private function reportFailure(
        IntegraClient $client,
        Instance $instance,
        WhatsAppConversation $conversation,
        ?WhatsAppMenuOption $option,
        array $contact,
        array $state
    ): MenuActionResult {
        $contract = $this->pickContract($contact, $state);

        if ($contract === null) {
            return $this->contractQuestion($contact, 'reportar_falla', $option?->id);
        }

        // Sin contrato el radicado se puede crear igual (Integra lo acepta):
        // peor es dejar sin canal a quien sí tiene un problema.
        $status = $contract ? $client->contractStatus((string) $contract['nro']) : null;

        if ($status && $this->suspendedForDebt($status)) {
            $due = (float) data_get($status, 'facturas_pendientes.total_por_pagar', 0);

            $this->emit($instance, $conversation, 'service.checked', [
                'cliente' => $this->clientPayload($contact),
                'contrato_nro' => $contract['nro'],
                'internet_activo' => false,
                'suspendido_por_mora' => true,
                'total_por_pagar' => $due,
            ]);

            $lines = [
                '⚠️ *Tu servicio está suspendido por facturas pendientes*',
                '',
                'Tienes *' . $this->money($due) . '* por pagar. En cuanto registremos el pago, '
                    . 'el servicio se reactiva automáticamente.',
            ];

            if ($url = $option?->setting('payment_url')) {
                $lines[] = '';
                $lines[] = 'Paga aquí 👉 ' . $this->fillPaymentVars(
                    $url,
                    $contact,
                    $this->pendingInvoices($contact),
                    $due
                );
            }

            return MenuActionResult::reply(implode("\n", $lines));
        }

        return MenuActionResult::ask(
            "🛠️ Cuéntame en un mensaje qué está pasando con tu servicio (por ejemplo: *sin internet desde anoche*, *se corta a ratos*, *el televisor no da señal*).",
            WhatsAppBotFlow::STEP_REPORT,
            [
                'action' => 'reportar_falla',
                'option_id' => $option?->id,
                'cliente' => $this->clientPayload($contact),
                'contrato' => $contract ? [
                    'nro' => $contract['nro'] ?? null,
                    'ip' => data_get($contract, 'red.ip'),
                    'mac' => data_get($contract, 'red.mac'),
                ] : null,
            ]
        );
    }

    /**
     * El cliente ya describió la falla: se crea el radicado en Integra con el
     * servicio y la prioridad que configuró el admin en la opción.
     */
    private function createTicket(
        IntegraClient $client,
        Instance $instance,
        WhatsAppConversation $conversation,
        ?WhatsAppMenuOption $option,
        array $context,
        string $report
    ): MenuActionResult {
        $report = trim($report);

        if (mb_strlen($report) < 4) {
            return MenuActionResult::ask(
                'Cuéntame un poco más para poder registrarlo: ¿qué servicio falla y desde cuándo?',
                WhatsAppBotFlow::STEP_REPORT,
                $context
            );
        }

        $servicio = (int) ($option?->setting('radicado_servicio') ?? 0);

        if (!$servicio) {
            Log::channel('whatsapp')->warning('⚠️ Reportar falla sin servicio configurado', [
                'conversation_id' => $conversation->id,
                'menu_option_id' => $option?->id,
            ]);

            return MenuActionResult::escalate(
                'Tomé nota de tu reporte. Te comunico con un asesor para registrarlo.'
            );
        }

        try {
            $radicado = $client->createRadicado([
                'cliente_id' => data_get($context, 'cliente.id'),
                'identificacion' => data_get($context, 'cliente.identificacion'),
                'servicio' => $servicio,
                'prioridad' => (int) ($option?->setting('radicado_prioridad') ?? 2),
                'tecnico' => $option?->setting('radicado_tecnico'),
                'reporte' => $report,
                'contrato' => data_get($context, 'contrato.nro'),
                'ip' => data_get($context, 'contrato.ip'),
                'mac_address' => data_get($context, 'contrato.mac'),
                'telefono' => $conversation->phone_number,
                'medio' => 'WhatsApp',
            ]);
        } catch (\RuntimeException $e) {
            return $this->integraFailed($option, $conversation, $e);
        }

        $codigo = $radicado['codigo'] ?? $radicado['id'] ?? null;

        $this->emit($instance, $conversation, 'ticket.created', [
            'cliente' => data_get($context, 'cliente'),
            'radicado' => $radicado,
            'contrato_nro' => data_get($context, 'contrato.nro'),
            'reporte' => $report,
        ]);

        Log::channel('whatsapp')->info('🎫 Radicado creado desde el menú', [
            'conversation_id' => $conversation->id,
            'radicado' => $codigo,
        ]);

        $lines = [
            '✅ *Reporte registrado*',
            '',
            $codigo ? "Tu radicado es el *#{$codigo}*." : 'Tu reporte quedó registrado.',
            'Nuestro equipo técnico ya lo tiene y te contactará para atenderlo.',
        ];

        return MenuActionResult::reply($this->withNote($lines, $option, $conversation));
    }

    // ------------------------------------------------------------------
    // Pasos del flujo
    // ------------------------------------------------------------------

    /**
     * El cliente respondió al "dime tu documento".
     */
    private function resumeIdentification(
        IntegraClient $client,
        Instance $instance,
        WhatsAppConversation $conversation,
        WhatsAppBotFlow $flow,
        ?WhatsAppMenuOption $option,
        string $text
    ): MenuActionResult {
        $action = (string) $flow->get('action', $flow->action_type);
        $attempts = (int) $flow->get('attempts', 0) + 1;
        $document = preg_replace('/\D+/', '', $text);

        if (mb_strlen((string) $document) >= 4) {
            try {
                $contact = $this->resolveContact($client, $conversation, $document);
            } catch (\RuntimeException $e) {
                return $this->integraFailed($option, $conversation, $e);
            }

            if ($contact) {
                return $this->run($client, $instance, $conversation, $option, $action, $contact, []);
            }
        }

        if ($attempts >= WhatsAppBotFlow::MAX_ATTEMPTS) {
            Log::channel('whatsapp')->info('🙋 Menú: cliente no identificado en Integra', [
                'conversation_id' => $conversation->id,
                'intentos' => $attempts,
            ]);

            return MenuActionResult::escalate(
                'No logro encontrar tu servicio con ese documento. Te comunico con un asesor para ayudarte.'
            );
        }

        return $this->askIdentification($action, $option?->id, $attempts, true);
    }

    /** El cliente eligió cuál de sus contratos. */
    private function resumeContract(
        IntegraClient $client,
        Instance $instance,
        WhatsAppConversation $conversation,
        WhatsAppBotFlow $flow,
        ?WhatsAppMenuOption $option,
        string $text
    ): MenuActionResult {
        $action = (string) $flow->get('action', $flow->action_type);
        $choices = (array) $flow->get('contratos', []);
        $picked = null;

        // "2" (la posición del listado) o el número de contrato tal cual.
        if (preg_match('/^\s*(\d{1,2})\s*$/', $text, $m) && isset($choices[(int) $m[1] - 1])) {
            $picked = $choices[(int) $m[1] - 1];
        } else {
            $typed = trim($text);
            foreach ($choices as $choice) {
                if ((string) $choice === $typed) {
                    $picked = $choice;
                    break;
                }
            }
        }

        if ($picked === null) {
            return MenuActionResult::ask(
                'No reconozco ese número. Respóndeme sólo con el número de la lista (1, 2, …).',
                WhatsAppBotFlow::STEP_CONTRACT,
                $flow->context ?? []
            );
        }

        try {
            $contact = $this->resolveContact($client, $conversation);
        } catch (\RuntimeException $e) {
            return $this->integraFailed($option, $conversation, $e);
        }

        if (!$contact) {
            return $this->askIdentification($action, $option?->id, 0);
        }

        return $this->run($client, $instance, $conversation, $option, $action, $contact, ['contrato_nro' => $picked]);
    }

    /** La pregunta por el documento, con el motivo por el que se pide. */
    private function askIdentification(string $action, ?int $optionId, int $attempts, bool $retry = false): MenuActionResult
    {
        $reason = match ($action) {
            'consultar_factura' => 'consultar tus facturas',
            'pagar_en_linea' => 'generar tu pago',
            'reportar_falla' => 'registrar tu reporte',
            'estado_servicio' => 'revisar tu servicio',
            default => 'ayudarte',
        };

        $text = $retry
            ? "No encontré ningún servicio con ese documento. Escríbelo de nuevo, sólo los números y sin puntos."
            : "Para {$reason} necesito identificarte 🙂\n\nRespóndeme con el *número de documento* con el que está registrado el servicio (sólo números).";

        return MenuActionResult::ask($text, WhatsAppBotFlow::STEP_IDENTIFICATION, [
            'action' => $action,
            'option_id' => $optionId,
            'attempts' => $attempts,
        ]);
    }

    /** La pregunta por el contrato cuando el cliente tiene más de uno. */
    private function contractQuestion(array $contact, string $action, ?int $optionId): MenuActionResult
    {
        $contracts = array_slice($this->contracts($contact), 0, self::MAX_CONTRACT_CHOICES);
        $lines = ['Tienes varios servicios a tu nombre. ¿Sobre cuál quieres que revisemos?', ''];
        $numbers = [];

        foreach (array_values($contracts) as $i => $contract) {
            $numbers[] = (string) ($contract['nro'] ?? '');
            $plan = data_get($contract, 'plan_internet.nombre') ?: ($contract['tipo_contrato'] ?? 'Servicio');
            $where = $contract['direccion'] ?? data_get($contract, 'ubicacion.direccion');
            $lines[] = ($i + 1) . '. Contrato ' . ($contract['nro'] ?? '?') . ' — ' . $plan
                . ($where ? ' (' . $where . ')' : '');
        }

        $lines[] = '';
        $lines[] = 'Respóndeme con el número (1, 2, …).';

        return MenuActionResult::ask(implode("\n", $lines), WhatsAppBotFlow::STEP_CONTRACT, [
            'action' => $action,
            'option_id' => $optionId,
            'contratos' => $numbers,
        ]);
    }

    // ------------------------------------------------------------------
    // Integra
    // ------------------------------------------------------------------

    /** La conexión a Integra de la empresa, o null si no está conectada. */
    private function client(int $companyId): ?IntegraClient
    {
        $integration = CompanyIntegration::where('company_id', $companyId)
            ->where('key', CompanyIntegration::KEY_INVOICE_PAYMENTS)
            ->first();

        if (!$integration || !$integration->isConnected()) {
            return null;
        }

        return new IntegraClient($integration->base_url, $integration->access_token);
    }

    /**
     * Quién es el cliente que escribe.
     *
     * Primero por el documento que ya le habíamos preguntado (queda guardado en
     * la conversación para no volver a pedírselo nunca más), y si no lo hay por
     * su propio número de WhatsApp: llega con indicativo (57300…) e Integra
     * guarda los celulares a 10 dígitos.
     *
     * @param ?string $document Documento que acaba de escribir el cliente.
     * @throws \RuntimeException si Integra no responde.
     */
    private function resolveContact(IntegraClient $client, WhatsAppConversation $conversation, ?string $document = null): ?array
    {
        $known = data_get($conversation->metadata ?? [], 'integra.identificacion');
        $phone = preg_replace('/\D+/', '', (string) $conversation->phone_number);
        $criteria = array_values(array_filter([
            $document,
            $document ? null : $known,
            $document ? null : (strlen($phone) > 10 ? substr($phone, -10) : $phone),
        ]));

        foreach ($criteria as $q) {
            $found = $client->searchContacts((string) $q, 5)['data'] ?? [];

            if ($found) {
                // Varios contactos con el mismo celular (una familia, un
                // negocio) es normal: se toma el primero, que es el que Integra
                // considera mejor coincidencia. Si el cliente escribió su
                // documento, la coincidencia es única por definición.
                $contact = $found[0];
                $this->rememberContact($conversation, $contact);

                return $contact;
            }
        }

        return null;
    }

    /**
     * Guarda en la conversación a qué cliente de Integra corresponde.
     *
     * Sólo la identidad, no las facturas: el saldo cambia cada mes y guardarlo
     * garantizaría contestar cifras viejas.
     */
    private function rememberContact(WhatsAppConversation $conversation, array $contact): void
    {
        $identificacion = $contact['identificacion'] ?? data_get($contact, 'contacto.identificacion');
        $metadata = $conversation->metadata ?? [];

        if (data_get($metadata, 'integra.identificacion') === $identificacion
            && data_get($metadata, 'integra.cliente_id') === ($contact['id'] ?? null)) {
            return;
        }

        $metadata['integra'] = [
            'cliente_id' => $contact['id'] ?? null,
            'identificacion' => $identificacion,
            'nombre' => $this->contactName($contact),
            'linked_at' => now()->toIso8601String(),
        ];

        $conversation->update(['metadata' => $metadata]);
    }

    // ------------------------------------------------------------------
    // Lectura del contacto
    // ------------------------------------------------------------------

    /** @return array<int, array> Contratos vigentes del contacto. */
    private function contracts(array $contact): array
    {
        return array_values(array_filter(
            (array) ($contact['contratos'] ?? []),
            fn ($c) => is_array($c) && ($c['vigente'] ?? true) !== false
        ));
    }

    /**
     * El contrato sobre el que trabaja la acción.
     *
     * Devuelve el contrato, `[]` si el cliente no tiene ninguno y `null`
     * cuando hay varios y hace falta preguntar cuál.
     */
    private function pickContract(array $contact, array $state): ?array
    {
        $contracts = $this->contracts($contact);

        if (!$contracts) {
            return [];
        }

        if ($chosen = ($state['contrato_nro'] ?? null)) {
            foreach ($contracts as $contract) {
                if ((string) ($contract['nro'] ?? '') === (string) $chosen) {
                    return $contract;
                }
            }
        }

        return count($contracts) === 1 ? $contracts[0] : null;
    }

    /** @return array<int, array> */
    private function pendingInvoices(array $contact): array
    {
        return array_values(array_filter(
            (array) ($contact['facturas_pendientes'] ?? []),
            fn ($i) => is_array($i)
        ));
    }

    private function totalDue(array $contact): float
    {
        $total = $contact['total_por_pagar'] ?? data_get($contact, 'resumen.total_por_pagar');

        if ($total !== null) {
            return (float) $total;
        }

        return array_sum(array_map(fn ($i) => (float) ($i['por_pagar'] ?? 0), $this->pendingInvoices($contact)));
    }

    private function contactName(array $contact): string
    {
        $name = $contact['nombre_completo']
            ?? $contact['nombre']
            ?? trim((string) data_get($contact, 'contacto.nombre') . ' ' . (string) data_get($contact, 'contacto.apellidos'));

        return trim((string) $name) ?: 'Hola';
    }

    /** Identidad del cliente tal como viaja en los webhooks salientes. */
    private function clientPayload(array $contact): array
    {
        return [
            'id' => $contact['id'] ?? null,
            'identificacion' => $contact['identificacion'] ?? data_get($contact, 'contacto.identificacion'),
            'nombre' => $this->contactName($contact),
        ];
    }

    /** ¿El servicio está cortado y además hay deuda? */
    private function suspendedForDebt(array $status): bool
    {
        $internetDown = data_get($status, 'estado.internet.activo') === false;
        $due = (float) data_get($status, 'facturas_pendientes.total_por_pagar', 0);

        return $internetDown && $due > 0;
    }

    // ------------------------------------------------------------------
    // Texto
    // ------------------------------------------------------------------

    /** @return string[] */
    private function statusLines(array $status, array $contract): array
    {
        $internetOk = (bool) data_get($status, 'estado.internet.activo');
        $lines = [
            '📡 *Estado de tu servicio*',
            '',
            'Contrato: ' . (data_get($status, 'contrato.nro') ?? $contract['nro'] ?? '—'),
        ];

        if ($plan = data_get($status, 'contrato.plan.nombre')) {
            $lines[] = 'Plan: ' . $plan;
        }

        $lines[] = 'Internet: ' . ($internetOk ? '✅ activo' : '⛔ suspendido');

        if (data_get($status, 'estado.television.tiene_servicio')) {
            $lines[] = 'Televisión: ' . (data_get($status, 'estado.television.activo') ? '✅ activa' : '⛔ inactiva');
        }

        $due = (float) data_get($status, 'facturas_pendientes.total_por_pagar', 0);

        if ($due > 0) {
            $lines[] = '';
            $lines[] = 'Facturas pendientes: *' . $this->money($due) . '*';

            if (!$internetOk) {
                $lines[] = 'Tu servicio se reactiva automáticamente al registrar el pago.';
            }
        } elseif ($internetOk) {
            $lines[] = '';
            $lines[] = 'Todo en orden y sin facturas pendientes 🎉';
        }

        return $lines;
    }

    private function invoiceLine(array $invoice): string
    {
        $line = '• ' . ($invoice['codigo'] ?? 'Factura') . ' — *' . $this->money((float) ($invoice['por_pagar'] ?? 0)) . '*';

        if ($due = ($invoice['vencimiento'] ?? null)) {
            $date = $this->date($due);
            $line .= $this->isOverdue($due) ? " (venció el {$date} ⚠️)" : " (vence el {$date})";
        }

        return $line;
    }

    /**
     * Añade al final el texto que escribió el admin en la opción, si lo hay.
     * Es lo que deja poner el "¿necesitas algo más? escribe MENU" sin tocar
     * código.
     *
     * @param string[] $lines
     */
    private function withNote(array $lines, ?WhatsAppMenuOption $option, WhatsAppConversation $conversation): string
    {
        $note = trim((string) $option?->reply_text);

        if ($note !== '') {
            $lines[] = '';
            $lines[] = $option->menu?->render($note, $conversation) ?? $note;
        }

        return implode("\n", $lines);
    }

    /** Variables del enlace de pago que configura el admin en la opción. */
    private function fillPaymentVars(string $template, array $contact, array $invoices, float $total): string
    {
        $first = $invoices[0] ?? [];

        return strtr($template, [
            '{nit}' => (string) ($contact['identificacion'] ?? data_get($contact, 'contacto.identificacion') ?? ''),
            '{cliente_id}' => (string) ($contact['id'] ?? ''),
            '{nombre}' => $this->contactName($contact),
            '{total}' => (string) round($total),
            '{factura}' => (string) ($first['codigo'] ?? ''),
        ]);
    }

    private function money(float $amount): string
    {
        return '$' . number_format($amount, 0, ',', '.');
    }

    private function date(string $value): string
    {
        try {
            return \Illuminate\Support\Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function isOverdue(string $value): bool
    {
        try {
            return \Illuminate\Support\Carbon::parse($value)->isPast();
        } catch (\Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Fallos
    // ------------------------------------------------------------------

    /**
     * La empresa no tiene Integra conectado. El cliente no tiene por qué
     * enterarse del detalle: recibe el texto de la opción si el admin lo
     * escribió, y si no, el chat pasa a un asesor.
     */
    private function unavailable(?WhatsAppMenuOption $option, WhatsAppConversation $conversation, string $reason): MenuActionResult
    {
        Log::channel('whatsapp')->warning('⚠️ Acción de menú no disponible: ' . $reason, [
            'menu_option_id' => $option?->id,
            'action_type' => $option?->action_type,
        ]);

        $configured = trim((string) $option?->reply_text);

        if ($configured === '') {
            return MenuActionResult::escalate('En este momento no puedo consultarlo. Te comunico con un asesor.');
        }

        // El texto del admin admite las mismas variables que el resto del
        // módulo: es el mismo campo que ya usaban las acciones simples.
        return MenuActionResult::reply(
            $option->menu?->render($configured, $conversation) ?? $configured
        );
    }

    /**
     * Integra respondió con un error. El mensaje de la excepción está pensado
     * para el admin (habla de tokens y scopes), así que al cliente se le
     * contesta genérico y el detalle se queda en el log.
     */
    private function integraFailed(?WhatsAppMenuOption $option, WhatsAppConversation $conversation, \RuntimeException $e): MenuActionResult
    {
        Log::channel('whatsapp')->error('❌ Integra falló durante una acción del menú', [
            'conversation_id' => $conversation->id,
            'menu_option_id' => $option?->id,
            'action_type' => $option?->action_type,
            'code' => $e->getCode(),
            'error' => $e->getMessage(),
        ]);

        return MenuActionResult::escalate(
            'Tuve un problema consultando tu información. Te comunico con un asesor para que te ayude.'
        );
    }

    private function emit(Instance $instance, WhatsAppConversation $conversation, string $event, array $payload): void
    {
        WebhookDispatcher::emit(
            $instance->company_id,
            $event,
            WebhookDispatcher::conversationPayload($conversation, $payload)
        );
    }
}
