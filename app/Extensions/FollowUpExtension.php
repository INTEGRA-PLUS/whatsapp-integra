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
use Illuminate\Support\Facades\Cache;
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

    /**
     * Tope de conversaciones viejas que se examinan en el resumen diario.
     *
     * Averiguar si un hilo espera respuesta obliga a mirar su último mensaje, y
     * eso no sale gratis: en la empresa más grande de hoy (964 conversaciones
     * abiertas de más de un día) la consulta tarda 1,5 s. Es asumible una vez
     * al día y no lo sería cada cinco minutos, que es justo por lo que este
     * resumen va por su cuenta.
     */
    private const MAX_ATRASADAS = 5000;

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
            .'Si hay varias esperando, llega **un solo aviso** encabezado por la que más lleva, no '
            .'uno por chat: una campana con veinte avisos iguales no se lee.'
            ."\n\n"
            .'Y una vez al día resume las que se quedaron atrás: las que llevan más de 24 h sin '
            .'respuesta, que ya no salen en el aviso de arriba porque pasado ese plazo Meta no deja '
            .'escribirles en texto libre.'
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
                'key' => 'abandonadas',
                'type' => 'boolean',
                'label' => 'Resumen diario de las que se quedaron atrás',
                'help' => 'Una vez al día, cuántos clientes escribieron hace más de 24 h y siguen sin respuesta.',
                'default' => true,
            ],
            [
                'key' => 'abandonadas_minimo',
                'type' => 'number',
                'label' => 'No avisar si son menos de',
                'help' => 'Cuántas conversaciones atrasadas tiene que haber para que valga la pena el aviso.',
                'default' => 10,
                'min' => 1,
                'max' => 500,
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
            'abandonadas' => (bool) ($input['abandonadas'] ?? true),
            'abandonadas_minimo' => $this->clampInt($input['abandonadas_minimo'] ?? null, 1, 500, 10),
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

        $this->avisarDeLasDeHoy($companyId, $instanceIds, $settings);
        $this->avisarDeLasQueSeQuedaronAtras($companyId, $instanceIds, $settings);
    }

    /**
     * Las que todavía se pueden atender: esperan desde hace más del umbral y
     * menos de 24 h.
     *
     * @param  Collection<int, int>  $instanceIds
     * @param  array<string, mixed>  $settings
     */
    private function avisarDeLasDeHoy(int $companyId, Collection $instanceIds, array $settings): void
    {
        $conversaciones = WhatsAppConversation::with('instance')
            ->whereIn('instance_id', $instanceIds)
            ->where('status', 'open')
            ->whereNotNull('last_message_at')
            ->where('last_message_at', '<=', now()->subMinutes((int) $settings['minutes']))
            // Pasadas las 24h el agente ya no puede contestar en texto libre: el
            // aviso llegaría tarde y con una acción imposible detrás. Ese caso lo
            // recoge el resumen diario, que sí sabe qué proponer.
            ->where('last_message_at', '>=', now()->subDay())
            ->orderBy('last_message_at')
            ->limit(self::MAX_POR_PASADA)
            ->get();

        $pendientes = [];

        foreach ($conversaciones as $conversacion) {
            if (! $this->esperaRespuesta($conversacion)) {
                continue;
            }

            if ($this->avisadaHacePoco($conversacion, (int) $settings['repeat_minutes'])) {
                continue;
            }

            $pendientes[] = $conversacion;
        }

        if ($pendientes === []) {
            return;
        }

        $this->repartirAvisos($pendientes, $companyId, (string) $settings['notify']);

        foreach ($pendientes as $conversacion) {
            if ($settings['tag_id']) {
                $this->etiquetar($conversacion, (int) $settings['tag_id'], $companyId);
            }

            $this->marcarAvisada($conversacion);
        }
    }

    /**
     * Un aviso por persona, no uno por chat.
     *
     * Mandar una notificación por conversación parecía lo más informativo y era
     * lo contrario: el 23-sep-2026 las tres empresas que usan esto tenían el
     * 100 % de sus avisos de seguimiento sin leer —17 de 17 en una, 27 de 30 en
     * otra—, porque la campana llegaba llena de líneas idénticas y dejaba de
     * mirarse. Un aviso que nadie abre no avisa de nada.
     *
     * Se agrupa por destinatario y no por empresa: a un asesor le toca lo suyo,
     * y al administrador, todo. Y lo encabeza **el que más lleva esperando**,
     * que es el número que distingue un día malo de uno normal; la cantidad,
     * por sí sola, en una bandeja con cincuenta hilos abiertos no distingue
     * nada.
     *
     * @param  list<WhatsAppConversation>  $pendientes
     */
    private function repartirAvisos(array $pendientes, int $companyId, string $notify): void
    {
        $admins = User::where('company_id', $companyId)
            ->where('role', 'admin')
            ->where('active', true)
            ->get();

        // Los asignados, de una consulta y no de una por conversación: con el
        // tope de 50 por pasada eran hasta cien viajes a la base de datos.
        $asignados = User::where('company_id', $companyId)
            ->where('active', true)
            ->whereIn('id', array_filter(array_map(fn ($c) => $c->assigned_to, $pendientes)))
            ->get()
            ->keyBy('id');

        $porPersona = [];

        foreach ($pendientes as $conversacion) {
            foreach ($this->destinatarios($conversacion, $admins, $asignados, $notify) as $usuario) {
                $porPersona[$usuario->id]['usuario'] = $usuario;
                $porPersona[$usuario->id]['conversaciones'][] = $conversacion;
            }
        }

        foreach ($porPersona as $reparto) {
            $suyas = $reparto['conversaciones'];

            // De la que más lleva esperando a la que menos: el aviso habla de la
            // primera y el enlace de la campana lleva a ella.
            usort($suyas, fn ($a, $b) => $a->last_message_at <=> $b->last_message_at);

            $reparto['usuario']->notify($this->avisoDeEspera($suyas));
        }
    }

    /** @param  list<WhatsAppConversation>  $conversaciones */
    private function avisoDeEspera(array $conversaciones): ExtensionAlertNotification
    {
        $peor = $conversaciones[0];
        $quien = $peor->name ?: $peor->phone_number;
        $espera = $this->tiempoEsperando($peor->last_message_at);
        $cuantas = count($conversaciones);

        if ($cuantas === 1) {
            return new ExtensionAlertNotification(
                'Conversación sin respuesta',
                "«{$quien}» lleva {$espera} esperando respuesta.",
                'Seguimiento',
                $peor
            );
        }

        return new ExtensionAlertNotification(
            "{$cuantas} conversaciones sin respuesta",
            "La que más lleva es «{$quien}», {$espera} esperando. Te abrimos ese chat; "
                .'las otras '.($cuantas - 1).' están en la bandeja.',
            'Seguimiento',
            $peor
        );
    }

    /**
     * Las que ya se cayeron del radar: más de 24 h sin respuesta.
     *
     * El aviso de arriba no las mira a propósito, y por eso nadie las miraba:
     * el 23-sep-2026 una sola empresa tenía 93 clientes que escribieron y nunca
     * recibieron contestación, el más antiguo de hacía 51 días, y otra uno de
     * hacía 127. No aparecían en ninguna pantalla ni en ninguna campana.
     *
     * Va una vez al día y no cada cinco minutos porque cuesta: hay que mirar el
     * último mensaje de cada hilo abierto, y son 1,5 s en la empresa más grande.
     * Una vez al día también es lo que merece — esto no se arregla en la
     * siguiente media hora, se arregla dedicándole un rato.
     *
     * @param  Collection<int, int>  $instanceIds
     * @param  array<string, mixed>  $settings
     */
    private function avisarDeLasQueSeQuedaronAtras(int $companyId, Collection $instanceIds, array $settings): void
    {
        if (! ($settings['abandonadas'] ?? true)) {
            return;
        }

        // La marca de "ya toca mañana" va en caché y no en la instalación: es
        // estado, no configuración, y guardarlo en `settings` lo borraría el
        // primer guardado desde la pantalla. Si la caché se vacía llega un
        // resumen de más, que no rompe nada.
        $clave = 'extension:follow_up:atrasadas:'.$companyId;

        if (Cache::has($clave)) {
            return;
        }

        Cache::put($clave, true, now()->addHours(23));

        $ids = WhatsAppConversation::whereIn('instance_id', $instanceIds)
            ->where('status', 'open')
            ->whereNotNull('last_message_at')
            ->where('last_message_at', '<', now()->subDay())
            ->orderByDesc('last_message_at')
            ->limit(self::MAX_ATRASADAS)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $esperando = $this->lasQueEsperanRespuesta($ids);
        $cuantas = $esperando->count();

        if ($cuantas < (int) ($settings['abandonadas_minimo'] ?? 10)) {
            return;
        }

        $peor = WhatsAppConversation::whereIn('id', $esperando)
            ->orderBy('last_message_at')
            ->first();

        if (! $peor) {
            return;
        }

        $admins = User::where('company_id', $companyId)
            ->where('role', 'admin')
            ->where('active', true)
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        $quien = $peor->name ?: $peor->phone_number;
        $espera = $this->tiempoEsperando($peor->last_message_at);
        // Con el tope alcanzado el número se queda corto, y decirlo redondo
        // sería mentir por omisión justo en el dato del que va el aviso.
        $cifra = $ids->count() >= self::MAX_ATRASADAS ? "Al menos {$cuantas}" : "{$cuantas}";

        Notification::send($admins, new ExtensionAlertNotification(
            'Conversaciones que se quedaron atrás',
            "{$cifra} clientes escribieron y siguen sin respuesta. El más antiguo, «{$quien}», "
                ."lleva {$espera}. Pasadas 24 h WhatsApp no deja escribirles en texto libre: "
                .'hay que reengancharlos con una plantilla, o cerrar el chat.',
            'Seguimiento',
            $peor
        ));
    }

    /**
     * De un montón de conversaciones, las que esperan respuesta del negocio.
     *
     * Una consulta para todas y no una por hilo: es el mismo patrón del
     * `max(id)` agrupado que usa la bandeja del chat para pintar el borde de
     * "esperando respuesta", y la única forma de que esto no sea un N+1 de mil
     * viajes.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    private function lasQueEsperanRespuesta(Collection $ids): Collection
    {
        return WhatsAppMessage::whereIn('conversation_id', $ids)
            ->where('direction', 'inbound')
            ->whereIn('id', function ($sub) use ($ids) {
                $sub->selectRaw('max(id)')
                    ->from('whatsapp_messages')
                    ->where('is_internal', false)
                    ->whereIn('direction', ['inbound', 'outbound'])
                    ->whereIn('conversation_id', $ids)
                    ->groupBy('conversation_id');
            })
            ->pluck('conversation_id');
    }

    /**
     * «1 h 40 min» se entiende de un vistazo; «100 minutos» hay que dividirlo.
     * Es el mismo formato que la lista del chat, para que el aviso y la
     * pantalla no cuenten lo mismo de dos maneras distintas.
     */
    private function tiempoEsperando(Carbon $desde): string
    {
        $minutos = max(1, (int) round($desde->diffInMinutes(now())));

        if ($minutos < 60) {
            return $minutos.' min';
        }

        $horas = intdiv($minutos, 60);

        if ($horas < 24) {
            $resto = $minutos % 60;

            return $resto ? "{$horas} h {$resto} min" : "{$horas} h";
        }

        $dias = intdiv($horas, 24);

        return $dias === 1 ? '1 día' : "{$dias} días";
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

    /**
     * Se marca aunque no hubiera a quién avisar: si la empresa se quedó sin
     * administradores activos, reintentarlo cada cinco minutos no arregla nada
     * y sí vuelve a recorrer el hilo indefinidamente.
     */
    private function marcarAvisada(WhatsAppConversation $conversation): void
    {
        $conversation->update([
            'metadata' => array_merge($conversation->metadata ?? [], [
                'extension_follow_up_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /**
     * @param  Collection<int, User>  $admins
     * @param  Collection<int, User>  $asignados
     * @return Collection<int, User>
     */
    private function destinatarios(
        WhatsAppConversation $conversation,
        Collection $admins,
        Collection $asignados,
        string $notify
    ): Collection {
        // Sin agente asignado no hay a quién avisar en persona, así que el aviso
        // sube a los administradores aunque la empresa haya elegido "sólo al
        // agente asignado": un chat huérfano sin respuesta es precisamente el
        // que más falta hace que alguien vea.
        $asignado = $conversation->assigned_to
            ? collect([$asignados->get($conversation->assigned_to)])->filter()->values()
            : collect();

        if ($asignado->isEmpty()) {
            return $admins;
        }

        return match ($notify) {
            'assigned' => $asignado,
            'admins' => $admins,
            default => $asignado->concat($admins)->unique('id')->values(),
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
