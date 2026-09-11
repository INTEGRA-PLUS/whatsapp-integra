<?php

namespace App\Extensions;

use App\Extensions\Contracts\RunsOnSchedule;
use App\Models\BusinessHour;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\KanbanColumn;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Notifications\ExtensionAlertNotification;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa de las conversaciones que se quedaron esperando respuesta.
 *
 * El agujero que tapa: hoy nada vigila que un chat contestado a medias siga
 * abierto. El reparto por carga (AgentAssignmentService) decide a quién le toca,
 * pero una vez repartido no hay nadie mirando. Si el agente asignado no vuelve a
 * la bandeja, el cliente espera y la empresa se entera cuando el cliente
 * reclama, o no se entera.
 *
 * Lo que NO hace: no le escribe nada al cliente. Un "seguimos aquí" automático
 * en un chat que lleva media hora parado no es atención, es ruido, y además
 * reinicia la cuenta —el hilo dejaría de estar "sin respuesta" sin que nadie lo
 * haya atendido—. Avisa al equipo y se aparta.
 */
class FollowUpExtension extends Extension implements RunsOnSchedule
{
    /**
     * Tope de conversaciones por pasada y empresa.
     *
     * La primera pasada de una empresa con la bandeja acumulada puede encontrar
     * cientos de hilos viejos y mandar un aluvión de avisos que nadie va a leer.
     * Con el tope se reparte en varias pasadas de cinco minutos y la campana
     * sigue siendo útil.
     */
    private const MAX_POR_PASADA = 50;

    public function slug(): string
    {
        return 'follow_up';
    }

    public function name(): string
    {
        return 'Seguimiento de conversaciones sin respuesta';
    }

    public function description(): string
    {
        return 'Avisa al equipo cuando un cliente lleva demasiado tiempo esperando respuesta.';
    }

    public function detail(): string
    {
        return 'Cada cinco minutos revisa las conversaciones abiertas cuyo último mensaje es del '
            .'cliente y avisa por la campana cuando llevan más del tiempo que configures sin '
            .'respuesta. Puede además aplicarles una etiqueta para que salten a la vista en el CRM.'
            ."\n\n"
            .'No le escribe nada al cliente ni cierra ni reasigna nada: sólo avisa. Tampoco vuelve '
            .'a avisar del mismo chat antes del tiempo de espera que indiques, para que la campana '
            .'no se convierta en ruido.';
    }

    public function icon(): string
    {
        return 'AlarmClock';
    }

    public function category(): string
    {
        return self::CATEGORIA_CONVERSACIONES;
    }

    public function permissions(): array
    {
        return [
            'Leer tus conversaciones abiertas y sus mensajes',
            'Enviar notificaciones a tu equipo',
            'Aplicar etiquetas a las conversaciones',
        ];
    }

    public function hooks(): array
    {
        return ['Cada 5 minutos, en segundo plano'];
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'minutes',
                'type' => 'number',
                'label' => 'Minutos sin respuesta',
                'help' => 'Cuánto puede esperar un cliente antes de que el equipo reciba el aviso.',
                'default' => 30,
                'min' => 5,
                'max' => 1440,
            ],
            [
                'key' => 'notify',
                'type' => 'select',
                'label' => 'A quién avisar',
                'help' => 'Si el chat no tiene agente asignado, el aviso va siempre a los administradores.',
                'default' => 'both',
                'options' => [
                    ['value' => 'assigned', 'label' => 'Sólo al agente asignado'],
                    ['value' => 'admins', 'label' => 'Sólo a los administradores'],
                    ['value' => 'both', 'label' => 'Al agente asignado y a los administradores'],
                ],
            ],
            [
                'key' => 'tag_id',
                'type' => 'select',
                'label' => 'Etiqueta a aplicar',
                'help' => 'Opcional. Marca el chat para que se vea en el CRM sin abrir la campana.',
                'default' => null,
                'source' => 'tags',
                'nullable' => true,
            ],
            [
                'key' => 'business_hours_only',
                'type' => 'boolean',
                'label' => 'Avisar sólo en horario de atención',
                'help' => 'Usa el horario configurado en Horario de atención. Sin horario configurado, avisa siempre.',
                'default' => true,
            ],
            [
                'key' => 'repeat_minutes',
                'type' => 'number',
                'label' => 'No repetir el aviso antes de (minutos)',
                'help' => 'Un chat que sigue sin respuesta no vuelve a avisar hasta que pase este tiempo.',
                'default' => 240,
                'min' => 15,
                'max' => 10080,
            ],
        ];
    }

    public function sanitizeSettings(array $input, int $companyId): array
    {
        $tagId = $input['tag_id'] ?? null;

        return [
            'minutes' => $this->clampInt($input['minutes'] ?? null, 5, 1440, 30),
            'notify' => in_array($input['notify'] ?? null, ['assigned', 'admins', 'both'], true)
                ? $input['notify']
                : 'both',
            // La etiqueta se busca acotada a la empresa: el desplegable sólo
            // ofrecía las suyas, pero mandar un id ajeno a mano etiquetaría las
            // conversaciones de una empresa con la etiqueta de otra.
            'tag_id' => $tagId
                ? Tag::where('company_id', $companyId)->where('id', $tagId)->value('id')
                : null,
            'business_hours_only' => (bool) ($input['business_hours_only'] ?? true),
            'repeat_minutes' => $this->clampInt($input['repeat_minutes'] ?? null, 15, 10080, 240),
        ];
    }

    public function runScheduled(CompanyExtension $installed): void
    {
        $settings = $installed->settings();
        $companyId = (int) $installed->company_id;

        if ($settings['business_hours_only'] && ! $this->dentroDelHorario($companyId)) {
            return;
        }

        $instanceIds = Instance::where('company_id', $companyId)->pluck('id');

        if ($instanceIds->isEmpty()) {
            return;
        }

        $minutos = (int) $settings['minutes'];

        $conversaciones = WhatsAppConversation::with('instance')
            ->whereIn('instance_id', $instanceIds)
            ->where('status', 'open')
            ->whereNotNull('last_message_at')
            ->where('last_message_at', '<=', now()->subMinutes($minutos))
            // Pasadas las 24h el agente ya no puede contestar en texto libre: el
            // aviso llegaría tarde y con una acción imposible detrás. Ese caso es
            // otro problema (plantilla de reenganche), no un seguimiento.
            ->where('last_message_at', '>=', now()->subDay())
            ->orderBy('last_message_at')
            ->limit(self::MAX_POR_PASADA)
            ->get();

        foreach ($conversaciones as $conversacion) {
            if (! $this->esperaRespuesta($conversacion)) {
                continue;
            }

            if ($this->avisadaHacePoco($conversacion, (int) $settings['repeat_minutes'])) {
                continue;
            }

            $this->avisar($conversacion, $companyId, $settings, $minutos);
        }
    }

    /**
     * ¿El último mensaje del hilo es del cliente?
     *
     * `last_message_at` sube con cualquier mensaje, así que por sí sola no
     * distingue "el cliente escribió y nadie contestó" de "el agente contestó y
     * el cliente no ha vuelto". La dirección del último mensaje sí.
     *
     * Las notas internas no cuentan como respuesta: el cliente no las ve.
     */
    private function esperaRespuesta(WhatsAppConversation $conversation): bool
    {
        $ultimo = WhatsAppMessage::where('conversation_id', $conversation->id)
            ->where('is_internal', false)
            ->where('type', '!=', 'system')
            ->orderByDesc('id')
            ->first();

        return $ultimo !== null && $ultimo->direction === 'inbound';
    }

    private function avisadaHacePoco(WhatsAppConversation $conversation, int $repeatMinutes): bool
    {
        // La marca va en `metadata` de la conversación y no en una tabla propia:
        // es un dato de un solo campo que sólo le sirve a esta extensión, y vive
        // y muere con el hilo al que pertenece.
        $ultimo = $conversation->metadata['extension_follow_up_at'] ?? null;

        if (! $ultimo) {
            return false;
        }

        try {
            return Carbon::parse($ultimo)->gt(now()->subMinutes($repeatMinutes));
        } catch (\Throwable) {
            return false;
        }
    }

    private function avisar(
        WhatsAppConversation $conversation,
        int $companyId,
        array $settings,
        int $minutos
    ): void {
        $espera = (int) round($conversation->last_message_at->diffInMinutes(now()));
        $quien = $conversation->name ?: $conversation->phone_number;

        $destinatarios = $this->destinatarios($conversation, $companyId, $settings['notify']);

        if ($destinatarios->isNotEmpty()) {
            Notification::send($destinatarios, new ExtensionAlertNotification(
                'Conversación sin respuesta',
                "«{$quien}» lleva {$espera} minutos esperando respuesta.",
                'Seguimiento',
                $conversation
            ));
        }

        if ($settings['tag_id']) {
            $this->etiquetar($conversation, (int) $settings['tag_id'], $companyId);
        }

        // Se marca aunque no hubiera a quién avisar: si la empresa se quedó sin
        // administradores activos, reintentarlo cada cinco minutos no arregla
        // nada y sí vuelve a recorrer el hilo indefinidamente.
        $conversation->update([
            'metadata' => array_merge($conversation->metadata ?? [], [
                'extension_follow_up_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /** @return Collection<int, User> */
    private function destinatarios(WhatsAppConversation $conversation, int $companyId, string $notify)
    {
        $admins = fn () => User::where('company_id', $companyId)
            ->where('role', 'admin')
            ->where('active', true)
            ->get();

        // Sin agente asignado no hay a quién avisar en persona, así que el aviso
        // sube a los administradores aunque la empresa haya elegido "sólo al
        // agente asignado": un chat huérfano sin respuesta es precisamente el
        // que más falta hace que alguien vea.
        $asignado = $conversation->assigned_to
            ? User::where('company_id', $companyId)
                ->where('id', $conversation->assigned_to)
                ->where('active', true)
                ->get()
            : collect();

        if ($asignado->isEmpty()) {
            return $admins();
        }

        return match ($notify) {
            'assigned' => $asignado,
            'admins' => $admins(),
            default => $asignado->concat($admins())->unique('id')->values(),
        };
    }

    /**
     * Etiquetar arrastra la columna del CRM, igual que hace TagController: si la
     * etiqueta tiene columna y no se mueve el hilo, el tablero muestra la
     * etiqueta en una columna que no le corresponde.
     */
    private function etiquetar(WhatsAppConversation $conversation, int $tagId, int $companyId): void
    {
        $tag = Tag::where('company_id', $companyId)->find($tagId);

        if (! $tag) {
            return;
        }

        $column = KanbanColumn::where('company_id', $companyId)->where('tag_id', $tag->id)->first();

        if ($column) {
            $conversation->update(['kanban_column_id' => $column->id]);
        }

        $conversation->tags()->syncWithoutDetaching([$tag->id]);
    }

    /**
     * Una empresa sin horario configurado no tiene "fuera de horario": se avisa
     * siempre. Lo contrario dejaría la extensión muda sin que nada lo explique.
     */
    private function dentroDelHorario(int $companyId): bool
    {
        $regla = BusinessHour::active()
            ->where('company_id', $companyId)
            ->orderByRaw('instance_id IS NULL')
            ->orderBy('created_at', 'desc')
            ->first();

        return $regla === null || $regla->isWithinHours();
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
