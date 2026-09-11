<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class WhatsAppConversation extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'instance_id',
        'contact_id',
        'wa_id',
        'bsuid',
        'phone_number',
        'name',
        'profile_pic_url',
        'last_message',
        'last_message_at',
        'status',
        'kanban_column_id',
        'assigned_to',
        'closed_by',
        'closed_at',
        'unread_count',
        'metadata',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
        'opt_out_requested_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $appends = ['initials'];

    /**
     * Forma canónica de un teléfono: solo dígitos.
     *
     * Meta siempre manda el número limpio, pero los demás caminos (la API de
     * Integra, un agente escribiendo a mano, una importación) aceptaban lo que
     * llegara: "57300 825 3303", "57 +1 (386) 7957322", "1-5993172978920".
     */
    public static function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', (string) $phone) ?: '';
    }

    /**
     * Destinatario tal y como lo manda un sistema externo, en forma canónica.
     *
     * Un teléfono se limpia a dígitos; un BSUID se devuelve intacto. Pasarlo por
     * `normalizePhone` a secas convertía "CO.1402615141764490" en
     * "1402615141764490": se abría un hilo fantasma, el envío iba a un número
     * inexistente y el ERP se quedaba sin poder avisar a ningún cliente que
     * oculte su número.
     */
    public static function normalizeRecipient(?string $value): string
    {
        $value = trim((string) $value);

        return self::isBsuid($value) ? $value : self::normalizePhone($value);
    }

    /**
     * ¿Es un BSUID (Business-Scoped User ID) en vez de un teléfono?
     *
     * Desde que Meta permite ocultar el número tras un nombre de usuario, el
     * webhook identifica a esos clientes con un id con ámbito de negocio con
     * forma "CO.1402615141764490" (país + punto + alfanumérico). No es un
     * teléfono y no se puede tocar: normalizarlo a dígitos ("1402615141764490")
     * lo dejaría indistinguible de un número real y Meta lo rechazaría al
     * responder.
     */
    public static function isBsuid(?string $value): bool
    {
        // Los BSUID de cartera llevan "ENT" entre el país y el número
        // ("US.ENT.11815799212886844830"), así que el punto entra en el cuerpo.
        return (bool) preg_match('/^[A-Za-z]{2}\.[A-Za-z0-9.]+$/', (string) $value);
    }

    /**
     * Identificador con el que se le responde a este hilo.
     *
     * Se prefiere el teléfono cuando se conoce: Meta le da precedencia si se
     * mandan los dos, y hay envíos que sólo funcionan con número (las plantillas
     * de autenticación). El BSUID es el respaldo para quien oculta el suyo.
     * Todos los envíos deben pasar por aquí: leer `phone_number` a pelo deja sin
     * destinatario a esos clientes.
     */
    public function recipientId(): string
    {
        // Fuera de WhatsApp la identidad del cliente es `wa_id` y no hay más:
        // un IGSID ni se normaliza ni tiene BSUID. Sin esta salida temprana la
        // función devolvía cadena vacía y el mensaje salía **sin destinatario**,
        // que es un envío perdido con un error de Meta que no dice por qué.
        if ($this->canal() !== Instance::CANAL_WHATSAPP) {
            return (string) $this->wa_id;
        }

        $phone = self::normalizePhone($this->phone_number);

        if ($phone !== '') {
            return $phone;
        }

        if ($this->bsuid) {
            return $this->bsuid;
        }

        // Hilos anteriores a la columna `bsuid`, que lo guardaron en `wa_id`.
        return self::isBsuid($this->wa_id) ? $this->wa_id : (string) $this->phone_number;
    }

    /**
     * ¿Se puede alcanzar a este cliente por teléfono?
     *
     * Lo que decide si un envío que exige número (plantilla de autenticación,
     * llamada, alta de contacto en Integra) tiene sentido siquiera intentarlo.
     */
    public function hasPhone(): bool
    {
        return self::normalizePhone($this->phone_number) !== '';
    }

    /**
     * Hilo de una instancia para un teléfono, creándolo si no existe.
     *
     * Sustituye al `firstOrCreate(['wa_id' => $phone])` que había repartido por
     * media docena de sitios. Ese patrón partía en dos la conversación de un
     * mismo cliente: si el hilo se creó con el número escrito de una forma y el
     * webhook llegaba con otra, el mensaje entrante abría un hilo nuevo y la
     * respuesta del cliente quedaba invisible en el chat y en el CRM.
     */
    public static function resolveFor(
        int $instanceId,
        ?string $phone,
        array $defaults = [],
        ?string $bsuid = null
    ): self {
        // Hay llamantes que no distinguen: reciben "el identificador del cliente"
        // y lo pasan como teléfono. Si resulta ser un BSUID se trata como tal en
        // vez de intentar normalizarlo a dígitos.
        if ($bsuid === null && self::isBsuid($phone)) {
            $bsuid = $phone;
            $phone = null;
        }

        $digits = self::normalizePhone($phone);

        if ($digits === '' && $phone !== null && $phone !== '' && ! $bsuid) {
            // Nada que normalizar: se conserva el comportamiento antiguo para no
            // perder el mensaje, pero queda el rastro de quién manda basura.
            Log::warning('Teléfono sin dígitos al resolver conversación', [
                'instance_id' => $instanceId,
                'phone' => $phone,
            ]);
            $digits = (string) $phone;
        }

        // 1. Por BSUID. Es la única identidad que Meta garantiza en todos los
        //    webhooks y la única que sobrevive a que el cliente oculte su número,
        //    así que se busca primero: si el hilo ya existe se reconoce aunque
        //    esta vez el teléfono no venga (o venga y antes no viniera).
        if ($bsuid) {
            $conversation = static::where('instance_id', $instanceId)
                ->where('bsuid', $bsuid)
                ->first();

            if ($conversation) {
                return $conversation->absorbIdentity($bsuid, $digits);
            }
        }

        // 2. Por teléfono. `phone_number` entra en la búsqueda porque un hilo
        //    creado por BSUID guarda el número ahí en cuanto Meta lo revela, sin
        //    tocar `wa_id` (que es la clave única y no se reescribe).
        if ($digits !== '') {
            $conversation = static::where('instance_id', $instanceId)
                ->where(function ($q) use ($digits) {
                    $q->where('wa_id', $digits)->orWhere('phone_number', $digits);
                })
                ->first();

            if ($conversation) {
                return $conversation->absorbIdentity($bsuid, $digits);
            }

            // Esto corre dentro del webhook: si la búsqueda de variantes falla por
            // cualquier motivo, es preferible abrir un hilo nuevo que perder el
            // mensaje del cliente.
            try {
                if ($variant = self::findVariant($instanceId, $digits)) {
                    return $variant->absorbIdentity($bsuid, $digits);
                }
            } catch (\Throwable $e) {
                Log::warning('No se pudo buscar variantes del número', [
                    'instance_id' => $instanceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // `wa_id` es la clave única del hilo: el teléfono cuando se conoce, y el
        // BSUID cuando el cliente lo oculta. `phone_number` se deja vacío en ese
        // caso en vez de repetir el BSUID: la columna es varchar(20) y un BSUID
        // llega a 131 caracteres, así que copiarlo ahí reventaría el insert
        // además de fingir un número que no existe.
        $waId = $digits !== '' ? $digits : (string) $bsuid;

        try {
            return static::create(array_merge($defaults, [
                'instance_id' => $instanceId,
                'wa_id' => $waId,
                'bsuid' => $bsuid,
                'phone_number' => $digits !== '' ? ($defaults['phone_number'] ?? $digits) : '',
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // Un lote del webhook puede traer dos mensajes del mismo cliente nuevo
            // y competir por el índice único (instance_id, wa_id).
            return static::where('instance_id', $instanceId)
                ->where('wa_id', $waId)
                ->firstOrFail()
                ->absorbIdentity($bsuid, $digits);
        }
    }

    /**
     * El hilo de un canal que no es WhatsApp, buscado por su identificador.
     *
     * Va aparte de `resolveFor()` y no es duplicación: aquello resuelve la
     * maraña propia de WhatsApp —normalizar teléfonos, absorber BSUID, buscar
     * variantes del número— y aquí nada de eso aplica. Un IGSID de Instagram no
     * es un teléfono, no se normaliza y no tiene variantes: **es o no es**.
     *
     * Meterlo por `resolveFor()` pasándolo como BSUID habría funcionado y habría
     * sido una mentira permanente, igual que meter un IGSID en `phone_number_id`.
     *
     * `wa_id` sí se reutiliza a propósito: es «la clave única del hilo» y ya
     * admite cualquier forma de identidad. `phone_number` se queda en null, que
     * es justo para lo que se hizo anulable.
     */
    public static function resolverPorIdentidad(int $instanceId, string $identidad, array $defaults = []): self
    {
        $conversacion = static::where('instance_id', $instanceId)
            ->where('wa_id', $identidad)
            ->first();

        if ($conversacion) {
            return $conversacion;
        }

        try {
            return static::create(array_merge($defaults, [
                'instance_id' => $instanceId,
                'wa_id' => $identidad,
                'phone_number' => null,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // Un lote del webhook puede traer dos mensajes del mismo cliente
            // nuevo y competir por el índice único (instance_id, wa_id).
            return static::where('instance_id', $instanceId)
                ->where('wa_id', $identidad)
                ->firstOrFail();
        }
    }

    /**
     * Completa la identidad del hilo con lo que traiga este webhook.
     *
     * Meta revela el teléfono de forma intermitente (sólo si hubo contacto en los
     * últimos 30 días) y el BSUID empezó a llegar después de que muchos hilos ya
     * existieran. Cada mensaje es una oportunidad de rellenar lo que falte, y es
     * lo que evita que el mismo cliente acabe con dos hilos cuando una de las dos
     * identidades deja de venir.
     *
     * Sólo rellena huecos: nunca sobrescribe un dato ya guardado, para que un
     * payload raro no reescriba la identidad de un hilo con historial.
     */
    public function absorbIdentity(?string $bsuid, string $digits = ''): self
    {
        $cambios = [];

        if ($bsuid && ! $this->bsuid) {
            $cambios['bsuid'] = $bsuid;
        }

        // La columna admite 20 caracteres: un teléfono real cabe de sobra, pero
        // no se arriesga el insert con un valor inesperado.
        if ($digits !== '' && ! $this->hasPhone() && strlen($digits) <= 20) {
            $cambios['phone_number'] = $digits;
        }

        if ($cambios) {
            $this->update($cambios);
        }

        return $this;
    }

    /**
     * Hilo ya existente del mismo abonado escrito de otra forma: con espacios,
     * sin indicativo o con el indicativo repetido ("57573012146547").
     *
     * Se busca por los últimos 10 dígitos —que en la práctica identifican al
     * abonado— y se exige además que un número sea sufijo del otro, para no unir
     * jamás dos clientes distintos que casualmente terminen igual.
     */
    private static function findVariant(int $instanceId, string $digits): ?self
    {
        if (strlen($digits) < 10) {
            return null;
        }

        $candidates = static::where('instance_id', $instanceId)
            ->whereRaw("REGEXP_REPLACE(wa_id, '[^0-9]', '') LIKE ?", ['%'.substr($digits, -10)])
            ->orderByDesc('last_message_at')
            ->limit(5)
            ->get();

        foreach ($candidates as $candidate) {
            // Un BSUID reducido a dígitos parece un teléfono larguísimo y puede
            // terminar en los mismos diez: unirlo aquí mezclaría dos clientes.
            // Esos hilos sólo se reconocen por su identidad, nunca por parecido.
            if (self::isBsuid($candidate->wa_id)) {
                continue;
            }

            $candidateDigits = self::normalizePhone($candidate->wa_id);

            if ($candidateDigits === '') {
                continue;
            }

            if (str_ends_with($candidateDigits, $digits) || str_ends_with($digits, $candidateDigits)) {
                return $candidate;
            }
        }

        return null;
    }

    public function instance()
    {
        return $this->belongsTo(Instance::class);
    }

    public function messages()
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id');
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Quién cerró la conversación por última vez.
     *
     * Se llama `closedByUser` y no `closedBy` a propósito: al serializar, la
     * relación toma el nombre en snake_case y `closed_by` chocaría con la
     * columna del mismo nombre, sobrescribiendo el id con el objeto usuario.
     */
    public function closedByUser()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function kanbanColumn()
    {
        return $this->belongsTo(KanbanColumn::class, 'kanban_column_id');
    }

    /**
     * The tags that belong to the conversation.
     */
    public function tags(): BelongsToMany
    {
        // withTimestamps es lo que da el «lleva 12 días en esta etapa»: cada
        // columna del tablero es una etiqueta, así que la fecha en que se
        // enganchó es la fecha en que la tarjeta entró en la columna.
        //
        // La tabla pivote tenía las dos columnas de fecha desde el principio,
        // pero sin esto Eloquent nunca las rellenaba: las 1.026 filas
        // anteriores al 9-sep-2026 están a NULL y no hay forma honrada de
        // reconstruirlas. Se muestran como «sin dato» hasta que la tarjeta se
        // mueva una vez.
        return $this->belongsToMany(Tag::class, 'whatsapp_conversation_tag', 'whatsapp_conversation_id', 'tag_id')
            ->withTimestamps();
    }

    /**
     * Por qué canal llegó esta conversación.
     *
     * Se deriva de la instancia en vez de copiarse en la tabla: una conversación
     * no cambia de canal nunca, así que duplicar el dato sólo daría dos sitios
     * donde pueda quedar desactualizado. Si algún día el listado necesita
     * filtrar por canal sin cargar la instancia, entonces se denormaliza y se
     * dirá aquí por qué.
     */
    public function canal(): string
    {
        return $this->instance?->channel ?? Instance::CANAL_WHATSAPP;
    }

    public function nombreDelCanal(): string
    {
        return $this->instance?->nombreDelCanal() ?? 'WhatsApp';
    }

    public function markAsRead()
    {
        $this->update(['unread_count' => 0]);
    }

    public function incrementUnread()
    {
        $this->increment('unread_count');
    }

    public function getInitialsAttribute()
    {
        $name = $this->name ?? $this->phone_number ?? 'U';
        $words = explode(' ', $name);
        if (count($words) >= 2) {
            return strtoupper(substr($words[0], 0, 1).substr($words[1], 0, 1));
        }

        return strtoupper(substr($name, 0, 2));
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Ventana de servicio de 24h de Meta: solo se puede enviar texto/adjuntos
     * libres si el cliente escribió en las últimas 24h. Fuera de esa ventana
     * hay que reabrir con una plantilla aprobada.
     */
    public function isWindowOpen(): bool
    {
        // Meta cuenta desde que el cliente pulsó enviar (`sent_at`), no desde que
        // nosotros guardamos el mensaje (`created_at`). Se parecen mientras todo
        // fluye, pero cuando Meta reintenta un webhook durante días y luego suelta
        // la cola de golpe, `created_at` es de hoy y `sent_at` de hace tres días:
        // dábamos la ventana por abierta y el envío moría con "Re-engagement".
        return $this->messages()
            ->where('direction', 'inbound')
            ->whereRaw('COALESCE(sent_at, created_at) >= ?', [now()->subDay()])
            ->exists();
    }

    /**
     * Guarda a qué cliente de Integra corresponde este hilo.
     *
     * Vive en el modelo y no en el servicio de acciones del menú porque hay dos
     * caminos que identifican al cliente —el menú, preguntando la cédula, y el
     * flujo de IA, que la deduce— y sólo el primero lo estaba guardando. El
     * resultado era que la IA identificaba a alguien y en el mensaje siguiente
     * volvía a preguntarle quién era.
     *
     * Sólo la identidad, no las facturas: el saldo cambia cada mes y guardarlo
     * garantizaría contestar cifras viejas.
     *
     * @return bool true si hubo algo nuevo que guardar.
     */
    public function rememberIntegraClient(
        int|string|null $clientId,
        ?string $identification,
        ?string $name = null
    ): bool {
        $identification = trim((string) $identification) ?: null;

        // Sin ninguno de los dos no hay identidad que recordar, y escribir un
        // bloque vacío borraría la que el menú ya había averiguado.
        if ($clientId === null && $identification === null) {
            return false;
        }

        $metadata = $this->metadata ?? [];

        if (data_get($metadata, 'integra.identificacion') === $identification
            && data_get($metadata, 'integra.cliente_id') === $clientId) {
            return false;
        }

        $metadata['integra'] = [
            'cliente_id' => $clientId,
            'identificacion' => $identification,
            'nombre' => trim((string) $name) ?: data_get($metadata, 'integra.nombre'),
            'linked_at' => now()->toIso8601String(),
        ];

        $this->update(['metadata' => $metadata]);

        return true;
    }

    public function scopeForInstance($query, $instanceId)
    {
        return $query->where('instance_id', $instanceId);
    }

    /**
     * Buscar una conversación por lo que se ve de ella en pantalla.
     *
     * Buscaba sólo en los campos de la conversación, y el `name` de una
     * conversación es el nombre de perfil de WhatsApp —"isnardo paredes31"—,
     * mientras que la cabecera del chat enseña el del contacto de la agenda
     * —"ISNARDO SALAZAR RAMIREZ"—. Así que el agente leía un nombre, lo
     * escribía en el buscador y no salía nada: estaba buscando algo que no se
     * miraba en ninguna columna (caso real en Megastore, 10-sep-2026).
     *
     * Ahora entra también el contacto: su nombre, su apellido, su usuario, su
     * identificación y sus teléfonos. Los números se comparan además sin
     * separadores, porque en la agenda se guardan de mil formas —"+57 302
     * 335 0723", "302-3350723"— y nadie los teclea igual que están guardados.
     */
    public function scopeSearch($query, $search)
    {
        $texto = trim((string) $search);

        if ($texto === '') {
            return $query;
        }

        $soloDigitos = preg_replace('/\D+/', '', $texto);

        return $query->where(function ($q) use ($texto, $soloDigitos) {
            $q->where('name', 'like', "%{$texto}%")
                ->orWhere('phone_number', 'like', "%{$texto}%")
                ->orWhere('last_message', 'like', "%{$texto}%");

            if ($soloDigitos !== '') {
                $q->orWhere('wa_id', 'like', "%{$soloDigitos}%")
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(phone_number, ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$soloDigitos}%"]);
            }

            $q->orWhereHas('contact', function ($c) use ($texto, $soloDigitos) {
                $c->where('name', 'like', "%{$texto}%")
                    ->orWhere('last_name', 'like', "%{$texto}%")
                    ->orWhere('username', 'like', "%{$texto}%")
                    ->orWhere('identificacion', 'like', "%{$texto}%")
                    ->orWhere('phone_number', 'like', "%{$texto}%");

                if ($soloDigitos !== '') {
                    $c->orWhereRaw("REPLACE(REPLACE(REPLACE(phone_number, ' ', ''), '-', ''), '+', '') LIKE ?", ["%{$soloDigitos}%"]);
                }
            });
        });
    }
}
