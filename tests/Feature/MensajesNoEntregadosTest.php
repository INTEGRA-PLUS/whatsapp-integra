<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\CoexistenceIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los mensajes que el chat mostraba como "Mensaje no compatible (X)".
 *
 * Salieron a la luz al importar el historial de un número en coexistencia: de
 * 4.076 mensajes, siete quedaron con ese texto. Son dos problemas distintos y
 * conviene no mezclarlos:
 *
 *  - `type: errors` (código 131051) es Meta diciendo "no sé representar esto".
 *    El contenido NO viaja y no hay forma de pedirlo. Lo único que se puede
 *    arreglar es el texto: decir qué pasó y que el mensaje sigue en el celular.
 *  - `type: button` era un fallo nuestro: la respuesta del cliente ("Aceptar")
 *    venía entera en el payload y el volcado la tiraba a la basura porque su
 *    mapa de tipos era más corto que el del webhook normal.
 */
class MensajesNoEntregadosTest extends TestCase
{
    use RefreshDatabase;

    private const NEGOCIO = '573181454747';
    private const CLIENTE = '573001112233';

    // ------------------------------------------------------- volcado de historial

    public function test_un_mensaje_que_meta_no_entrega_se_explica_en_vez_de_decir_errors(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote([
            $this->noEntregado('wamid.uno', self::CLIENTE),
        ]));

        $mensaje = WhatsAppMessage::first();

        $this->assertNotNull($mensaje);
        $this->assertStringNotContainsString('no compatible', $mensaje->content);
        $this->assertStringContainsString('El cliente envió', $mensaje->content);
        // Lo accionable: el mensaje no se perdió, está en el celular del negocio.
        $this->assertStringContainsString('app del celular', $mensaje->content);
        $this->assertTrue($mensaje->metadata['no_entregado']);
        $this->assertSame(131051, $mensaje->metadata['error_code']);
    }

    /**
     * `content` lleva la explicación entera, que en la lista de chats no cabe.
     * Sin el resumen, la fila del contacto mostraba media frase cortada.
     */
    public function test_la_lista_de_chats_muestra_un_resumen_corto(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote([
            $this->noEntregado('wamid.uno', self::CLIENTE),
        ]));

        $conversacion = WhatsAppConversation::first();

        $this->assertSame('Mensaje que WhatsApp no entregó', $conversacion->last_message);
    }

    /** Si lo mandó el negocio desde su celular, "El cliente envió" es falso. */
    public function test_el_texto_distingue_quien_lo_mando(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote([
            $this->noEntregado('wamid.uno', self::NEGOCIO, ['from_me' => true]),
        ]));

        $this->assertStringContainsString('Enviaste', WhatsAppMessage::first()->content);
    }

    /**
     * El webhook normal ya sabía leer los botones de plantilla; el volcado no.
     * Dos mapas de tipos separados y sólo uno completo: ese es el fallo.
     */
    public function test_la_respuesta_a_un_boton_se_recupera_entera(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote([[
            'from'            => self::CLIENTE,
            'id'              => 'wamid.boton',
            'timestamp'       => (string) now()->timestamp,
            'type'            => 'button',
            'button'          => ['payload' => 'Aceptar', 'text' => 'Aceptar'],
            'history_context' => ['status' => 'DELIVERED'],
        ]]));

        $mensaje = WhatsAppMessage::first();

        $this->assertSame('text', $mensaje->type);
        $this->assertSame('Aceptar', $mensaje->content);
    }

    /** Una reacción se cuelga del mensaje al que apunta, no crea burbuja. */
    public function test_una_reaccion_del_historial_no_deja_una_burbuja_suelta(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote([
            $this->texto('wamid.original', self::NEGOCIO, 'Ya salimos'),
        ]));

        $this->ingesta()->importarHistorial($instancia, $this->lote([[
            'from'            => self::CLIENTE,
            'id'              => 'wamid.reaccion',
            'timestamp'       => (string) now()->timestamp,
            'type'            => 'reaction',
            'reaction'        => ['message_id' => 'wamid.original', 'emoji' => '👍'],
            'history_context' => ['status' => 'READ'],
        ]]));

        $this->assertSame(1, WhatsAppMessage::count());
        $this->assertSame('👍', WhatsAppMessage::where('wamid', 'wamid.original')->first()->metadata['reaction']);
    }

    // ------------------------------------------------------------- webhook en vivo

    /**
     * Según la versión de la API el mismo error 131051 llega como
     * `type: unsupported` o como `type: errors`. Sólo la primera etiqueta
     * estaba contemplada, así que la segunda caía en el `default`.
     */
    public function test_el_webhook_en_vivo_entiende_el_tipo_errors(): void
    {
        $instancia = $this->instanciaMeta();

        $this->postSignedWebhook([
            'object' => 'whatsapp_business_account',
            'entry'  => [[
                'id'      => '2212436902867081',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '573104047030',
                            'phone_number_id'      => $instancia->phone_number_id,
                        ],
                        'contacts' => [['profile' => ['name' => 'Daniela'], 'wa_id' => self::CLIENTE]],
                        'messages' => [[
                            'from'      => self::CLIENTE,
                            'id'        => 'wamid.envivo',
                            'timestamp' => (string) now()->timestamp,
                            'type'      => 'errors',
                            'errors'    => [[
                                'code'       => 131051,
                                'title'      => 'Message type unknown',
                                'error_data' => ['details' => 'Unsupported message received'],
                            ]],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();

        $mensaje = WhatsAppMessage::where('wamid', 'wamid.envivo')->first();

        $this->assertNotNull($mensaje);
        $this->assertStringNotContainsString('no compatible', $mensaje->content);
        $this->assertTrue($mensaje->metadata['no_entregado']);
        // En vivo el mensaje no está en ninguna parte: lo único útil es pedirlo.
        $this->assertStringContainsString('reenvíe', $mensaje->content);
    }

    // --------------------------------------------------------------- reparación

    /**
     * Las 2.111 filas anteriores a agosto no guardaron el payload: su contenido
     * no existe. Lo que sí se puede es dejar de mostrar una etiqueta interna.
     */
    public function test_la_migracion_reescribe_las_filas_viejas_sin_payload(): void
    {
        $instancia = $this->instancia();
        $conversacion = WhatsAppConversation::create([
            'instance_id'  => $instancia->id,
            'wa_id'        => self::CLIENTE,
            'phone_number' => self::CLIENTE,
            'name'         => 'Daniela',
            'status'       => 'open',
            'last_message' => 'ℹ️ Mensaje no compatible (sticker)',
        ]);

        $id = DB::table('whatsapp_messages')->insertGetId([
            'conversation_id' => $conversacion->id,
            'wamid'           => 'wamid.viejo',
            'type'            => 'system',
            'content'         => 'Mensaje no compatible (sticker)',
            'direction'       => 'inbound',
            'status'          => 'delivered',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->migracion()->up();

        $mensaje = WhatsAppMessage::find($id);

        $this->assertStringContainsString('un sticker', $mensaje->content);
        $this->assertStringNotContainsString('no compatible', $mensaje->content);
        $this->assertTrue($mensaje->metadata['no_entregado']);
        $this->assertSame('ℹ️ Sticker (sin contenido)', $conversacion->fresh()->last_message);
    }

    /**
     * Una reacción vieja no guardó ni el emoji ni a qué mensaje apuntaba: no
     * hay nada que colgar de ningún sitio, sólo una burbuja de ruido en mitad
     * del hilo. Se quitan, y el hilo se queda con los mensajes de verdad.
     */
    public function test_la_migracion_quita_las_reacciones_huerfanas(): void
    {
        $conversacion = $this->conversacion();

        DB::table('whatsapp_messages')->insert([
            [
                'conversation_id' => $conversacion->id,
                'wamid'           => 'wamid.reaccion',
                'type'            => 'system',
                'content'         => 'Mensaje no compatible (reaction)',
                'direction'       => 'inbound',
                'status'          => 'delivered',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
            [
                'conversation_id' => $conversacion->id,
                'wamid'           => 'wamid.real',
                'type'            => 'text',
                'content'         => 'Buenas, ¿me instalan hoy?',
                'direction'       => 'inbound',
                'status'          => 'delivered',
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
        ]);

        $this->migracion()->up();

        $this->assertNull(WhatsAppMessage::where('wamid', 'wamid.reaccion')->first());
        $this->assertNotNull(WhatsAppMessage::where('wamid', 'wamid.real')->first());
    }

    /**
     * Si la reacción era el último mensaje del hilo, la lista de chats se queda
     * anunciando una fila que ya no existe. `last_message` es una copia: hay que
     * recalcularla a mano después de quitar nada.
     */
    public function test_la_lista_de_chats_no_se_queda_anunciando_la_reaccion_borrada(): void
    {
        $conversacion = $this->conversacion(['last_message' => 'ℹ️ Mensaje no compatible (reaction)']);

        DB::table('whatsapp_messages')->insert([
            [
                'conversation_id' => $conversacion->id,
                'wamid'           => 'wamid.real',
                'type'            => 'text',
                'content'         => 'Buenas, ¿me instalan hoy?',
                'direction'       => 'inbound',
                'status'          => 'delivered',
                'sent_at'         => now()->subMinute(),
                'created_at'      => now()->subMinute(),
                'updated_at'      => now()->subMinute(),
            ],
            [
                'conversation_id' => $conversacion->id,
                'wamid'           => 'wamid.reaccion',
                'type'            => 'system',
                'content'         => 'Mensaje no compatible (reaction)',
                'direction'       => 'inbound',
                'status'          => 'delivered',
                'sent_at'         => now(),
                'created_at'      => now(),
                'updated_at'      => now(),
            ],
        ]);

        $this->migracion()->up();

        $this->assertSame('Buenas, ¿me instalan hoy?', $conversacion->fresh()->last_message);
    }

    /** Las siete filas recientes sí traen el payload: de ahí sale el contenido real. */
    public function test_la_migracion_recupera_el_boton_que_se_habia_perdido(): void
    {
        $instancia = $this->instancia();
        $conversacion = WhatsAppConversation::create([
            'instance_id'  => $instancia->id,
            'wa_id'        => self::CLIENTE,
            'phone_number' => self::CLIENTE,
            'name'         => 'Daniela',
            'status'       => 'open',
        ]);

        $id = DB::table('whatsapp_messages')->insertGetId([
            'conversation_id' => $conversacion->id,
            'wamid'           => 'wamid.boton',
            'type'            => 'system',
            'content'         => 'Mensaje no compatible (button)',
            'direction'       => 'inbound',
            'status'          => 'delivered',
            'metadata'        => json_encode(['unhandled' => [
                'type'   => 'button',
                'button' => ['payload' => 'Aceptar', 'text' => 'Aceptar'],
            ]]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->migracion()->up();

        $mensaje = WhatsAppMessage::find($id);

        $this->assertSame('text', $mensaje->type);
        $this->assertSame('Aceptar', $mensaje->content);
    }

    // ------------------------------------------------------------- utilidades

    private function conversacion(array $extra = []): WhatsAppConversation
    {
        return WhatsAppConversation::create(array_merge([
            'instance_id'  => $this->instancia()->id,
            'wa_id'        => self::CLIENTE,
            'phone_number' => self::CLIENTE,
            'name'         => 'Daniela',
            'status'       => 'open',
        ], $extra));
    }

    private function migracion(): object
    {
        return require database_path('migrations/2026_09_08_170000_repair_unsupported_message_copy.php');
    }

    private function ingesta(): CoexistenceIngestService
    {
        return app(CoexistenceIngestService::class);
    }

    private function instancia(): Instance
    {
        $company = Company::create(['name' => 'Fibra XYZ', 'slug' => 'fibra-xyz', 'active' => true]);

        return Instance::create([
            'company_id'           => $company->id,
            'uuid'                 => (string) Str::uuid(),
            'name'                 => 'Principal',
            'phone_number_id'      => '1247515825107349',
            'waba_id'              => '1421384372768123',
            'display_phone_number' => '+57 318 1454747',
            'type'                 => 'meta',
            'status'               => 'active',
            'active'               => true,
        ]);
    }

    private function instanciaMeta(): Instance
    {
        $instancia = $this->instancia();
        $instancia->update(['phone_number_id' => '1177962515404155', 'access_token' => 'token-meta']);

        return $instancia->fresh();
    }

    private function lote(array $mensajes): array
    {
        return [
            'metadata' => ['display_phone_number' => self::NEGOCIO, 'phone_number_id' => '1247515825107349'],
            'history'  => [[
                'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 10],
                'threads'  => [['id' => self::CLIENTE, 'messages' => $mensajes]],
            ]],
        ];
    }

    private function texto(string $wamid, string $de, string $texto): array
    {
        return [
            'from'            => $de,
            'id'              => $wamid,
            'timestamp'       => (string) now()->timestamp,
            'type'            => 'text',
            'text'            => ['body' => $texto],
            'history_context' => ['status' => 'READ'],
        ];
    }

    /** Tal cual llegó en el volcado de producción del 8-sep. */
    private function noEntregado(string $wamid, string $de, array $contexto = []): array
    {
        return [
            'from'            => $de,
            'id'              => $wamid,
            'timestamp'       => (string) now()->timestamp,
            'type'            => 'errors',
            'errors'          => [[
                'code'       => 131051,
                'title'      => 'Message type unknown',
                'message'    => 'Message type unknown',
                'error_data' => ['details' => 'Unsupported message received'],
            ]],
            'history_context' => array_merge(['status' => 'pending'], $contexto),
        ];
    }
}
