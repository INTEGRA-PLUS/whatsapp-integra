<?php

namespace Tests\Feature;

use App\Models\CoexistenceSync;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\CoexistenceIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El volcado de los webhooks que sólo existen en coexistencia.
 *
 * Antes de esto el callback los recibía y los tiraba: llegaron 6 webhooks de
 * `history` y 3 de `smb_app_state_sync` de un cliente real y la base se quedó
 * con cero conversaciones. Como la importación es de **un solo uso**, ese
 * historial no se recupera sin desconectar el número y rehacer el registro
 * entero, así que lo que se protege aquí no es un detalle: es la única
 * oportunidad que tiene cada cliente.
 */
class CoexistenceIngestTest extends TestCase
{
    use RefreshDatabase;

    private const NEGOCIO = '573181454747';
    private const CLIENTE = '573001112233';

    public function test_el_historial_crea_conversaciones_y_mensajes(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote([
            $this->mensaje('wamid.uno', self::CLIENTE, 'Buenas, ¿me instalan hoy?'),
            $this->mensaje('wamid.dos', self::NEGOCIO, 'Claro, vamos a las 3'),
        ]));

        $conversacion = WhatsAppConversation::where('instance_id', $instancia->id)->first();

        $this->assertNotNull($conversacion);
        $this->assertSame(self::CLIENTE, $conversacion->wa_id ?: $conversacion->phone_number);
        $this->assertSame(2, WhatsAppMessage::count());

        // La dirección se deduce comparando con el número del negocio: sin esto
        // todo el historial entraría como si lo hubiera escrito el cliente.
        $this->assertSame('inbound', WhatsAppMessage::where('wamid', 'wamid.uno')->value('direction'));
        $this->assertSame('outbound', WhatsAppMessage::where('wamid', 'wamid.dos')->value('direction'));
    }

    /**
     * Meta reenvía el lote entero si el callback responde 500. Reprocesar tiene
     * que ser inofensivo o el cliente termina con su historial duplicado.
     */
    public function test_reprocesar_el_mismo_lote_no_duplica_nada(): void
    {
        $instancia = $this->instancia();
        $lote = $this->lote([$this->mensaje('wamid.uno', self::CLIENTE, 'hola')]);

        $this->ingesta()->importarHistorial($instancia, $lote);
        $this->ingesta()->importarHistorial($instancia, $lote);

        $this->assertSame(1, WhatsAppMessage::count());
        $this->assertSame(1, WhatsAppConversation::where('instance_id', $instancia->id)->count());
        $this->assertSame(1, CoexistenceSync::where('instance_id', $instancia->id)->value('messages_imported'));
    }

    /**
     * El historial llega hacia atrás en el tiempo y desordenado. Tocar
     * `last_message` con cualquier mensaje dejaría el listado de chats
     * anunciando como "último" algo de hace seis meses.
     */
    public function test_un_mensaje_viejo_no_pisa_el_ultimo_mensaje(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote([
            $this->mensaje('wamid.reciente', self::CLIENTE, 'lo de ayer', now()->subDay()->timestamp),
        ]));

        $this->ingesta()->importarHistorial($instancia, $this->lote([
            $this->mensaje('wamid.antiguo', self::CLIENTE, 'lo de hace meses', now()->subMonths(5)->timestamp),
        ], fase: 1));

        $conversacion = WhatsAppConversation::where('instance_id', $instancia->id)->first();

        $this->assertSame('lo de ayer', $conversacion->last_message);
    }

    /** El porcentaje y la fase salen de lo que Meta reporta, no de un contador nuestro. */
    public function test_el_progreso_avanza_con_lo_que_reporta_meta(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote(
            [$this->mensaje('wamid.uno', self::CLIENTE, 'hola')],
            fase: 0,
            progreso: 55
        ));

        $sync = CoexistenceSync::where('instance_id', $instancia->id)->first();
        $this->assertSame(CoexistenceSync::IMPORTANDO, $sync->status);
        $this->assertSame(0, $sync->phase);
        $this->assertSame(55, $sync->progress);

        // Un lote que llega tarde con menos progreso no debe hacer retroceder la
        // barra: una barra que baja parece un fallo.
        $this->ingesta()->importarHistorial($instancia, $this->lote(
            [$this->mensaje('wamid.dos', self::CLIENTE, 'otra')],
            fase: 0,
            progreso: 20
        ));

        $this->assertSame(55, $sync->fresh()->progress);
    }

    public function test_la_ultima_fase_al_cien_cierra_la_importacion(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote(
            [$this->mensaje('wamid.uno', self::CLIENTE, 'hola')],
            fase: 2,
            progreso: 100
        ));

        $sync = CoexistenceSync::where('instance_id', $instancia->id)->first();

        $this->assertSame(CoexistenceSync::COMPLETADA, $sync->status);
        $this->assertNotNull($sync->completed_at);
        $this->assertSame(100, $sync->porcentajeGlobal());
    }

    /**
     * Que el negocio decida no compartir sus chats no es un fallo. Meta lo avisa
     * con el código 2593109 y la pantalla tiene que poder decirlo con esas
     * palabras en vez de enseñar un error rojo.
     */
    public function test_si_el_negocio_no_comparte_el_historial_queda_como_rechazado(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, [
            'metadata' => ['display_phone_number' => self::NEGOCIO, 'phone_number_id' => '1247515825107349'],
            'history'  => [[
                'errors' => [[
                    'code'  => 2593109,
                    'title' => 'History sync is turned off by the business from the WhatsApp Business App',
                ]],
            ]],
        ]);

        $sync = CoexistenceSync::where('instance_id', $instancia->id)->first();

        $this->assertSame(CoexistenceSync::RECHAZADA, $sync->status);
        $this->assertSame('2593109', $sync->error_code);
        $this->assertSame(0, WhatsAppMessage::count());
    }

    /**
     * Los adjuntos no vienen en el hilo: llegan en un webhook posterior que
     * completa un mensaje ya guardado. Sin esto el chat muestra para siempre
     * "Archivo adjunto" sin el archivo.
     */
    public function test_el_multimedia_completa_el_marcador_guardado_antes(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote([[
            'from'      => self::CLIENTE,
            'id'        => 'wamid.foto',
            'timestamp' => (string) now()->timestamp,
            'type'      => 'media_placeholder',
        ]]));

        $this->assertSame('media_placeholder', WhatsAppMessage::where('wamid', 'wamid.foto')->value('type'));

        $this->ingesta()->importarHistorial($instancia, [
            'metadata' => ['display_phone_number' => self::NEGOCIO, 'phone_number_id' => '1247515825107349'],
            'messages' => [[
                'id'    => 'wamid.foto',
                'type'  => 'image',
                'image' => ['id' => '24230790383178626', 'mime_type' => 'image/jpeg', 'caption' => 'el poste'],
            ]],
        ]);

        $mensaje = WhatsAppMessage::where('wamid', 'wamid.foto')->first();

        $this->assertSame('image', $mensaje->type);
        $this->assertSame('24230790383178626', $mensaje->media_id);
        $this->assertSame('el poste', $mensaje->content);
    }

    public function test_los_contactos_del_celular_entran_en_la_agenda(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->sincronizarContactos($instancia, [
            'metadata'   => ['display_phone_number' => self::NEGOCIO, 'phone_number_id' => '1247515825107349'],
            'state_sync' => [[
                'type'    => 'contact',
                'contact' => ['full_name' => 'Pablo Morales', 'first_name' => 'Pablo', 'phone_number' => self::CLIENTE],
                'action'  => 'add',
            ]],
        ]);

        $contacto = Contact::where('company_id', $instancia->company_id)->first();

        $this->assertSame('Pablo Morales', $contacto->name);
        $this->assertSame(1, CoexistenceSync::where('instance_id', $instancia->id)->value('contacts_imported'));
    }

    /**
     * Que el negocio borre un contacto de la agenda de su teléfono no significa
     * que quiera perderlo del CRM: aquí cuelgan conversaciones, etiquetas y
     * negocios cerrados.
     */
    public function test_borrar_un_contacto_en_el_celular_no_lo_borra_del_crm(): void
    {
        $instancia = $this->instancia();

        Contact::create([
            'company_id'   => $instancia->company_id,
            'phone_number' => self::CLIENTE,
            'name'         => 'Cliente de toda la vida',
        ]);

        $this->ingesta()->sincronizarContactos($instancia, [
            'metadata'   => ['display_phone_number' => self::NEGOCIO, 'phone_number_id' => '1247515825107349'],
            'state_sync' => [[
                'type'    => 'contact',
                'contact' => ['phone_number' => self::CLIENTE],
                'action'  => 'remove',
            ]],
        ]);

        $this->assertSame(1, Contact::where('company_id', $instancia->company_id)->count());
    }

    /**
     * Lo que el negocio responde desde su celular tiene que verse en el CRM, o
     * el asesor contesta otra vez algo ya respondido.
     */
    public function test_el_eco_del_celular_entra_como_mensaje_saliente(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->reflejarEco($instancia, [
            'metadata'       => ['display_phone_number' => self::NEGOCIO, 'phone_number_id' => '1247515825107349'],
            'message_echoes' => [[
                'from'      => self::NEGOCIO,
                'to'        => self::CLIENTE,
                'id'        => 'wamid.eco',
                'timestamp' => (string) now()->timestamp,
                'type'      => 'text',
                'text'      => ['body' => 'Ya salimos para allá'],
            ]],
        ]);

        $mensaje = WhatsAppMessage::where('wamid', 'wamid.eco')->first();

        $this->assertNotNull($mensaje);
        $this->assertSame('outbound', $mensaje->direction);
        $this->assertSame('Ya salimos para allá', $mensaje->content);

        $conversacion = WhatsAppConversation::where('instance_id', $instancia->id)->first();
        $this->assertSame('Ya salimos para allá', $conversacion->last_message);
    }

    // ------------------------------------------------------------- utilidades

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

    /**
     * El caso de Transintermet: fase 2 al 100, y aun así «importando».
     *
     * Los lotes de `history` llegan desordenados. Cuando uno rezagado llegaba
     * después del que cerraba la importación, `avanzar()` lo volvía a poner en
     * «importando»: el estado se decidía con el progreso de ese lote suelto, y
     * un lote sin `progress` contaba como 0%.
     *
     * El resultado eran 70.035 mensajes ya guardados, la barra clavada en 99% y
     * un vigilante a punto de decirle al cliente que rehiciera la conexión
     * (9-sep-2026).
     */
    public function test_un_lote_rezagado_no_reabre_una_importacion_terminada(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote(
            [$this->mensaje('wamid.final', self::CLIENTE, 'el ultimo')],
            fase: 2,
            progreso: 100
        ));

        $sync = CoexistenceSync::where('instance_id', $instancia->id)->first();
        $this->assertSame(CoexistenceSync::COMPLETADA, $sync->status);
        $cerradaEn = $sync->completed_at;

        // Llega uno de la misma fase con menos progreso, como los que Meta
        // manda fuera de orden.
        $this->ingesta()->importarHistorial($instancia, $this->lote(
            [$this->mensaje('wamid.rezagado', self::CLIENTE, 'venia atras')],
            fase: 2,
            progreso: 40
        ));

        $sync->refresh();
        $this->assertSame(CoexistenceSync::COMPLETADA, $sync->status, 'Un lote rezagado reabrió la importación.');
        $this->assertSame(100, $sync->progress);
        $this->assertEquals($cerradaEn, $sync->completed_at, 'Se reescribió la fecha de cierre con la del rezagado.');
    }

    /**
     * Un lote sin `progress` en su metadata no dice «vamos por cero»: dice que
     * no trae el dato. Tomarlo por cero borraba el avance.
     */
    public function test_un_lote_sin_progreso_no_borra_el_avance(): void
    {
        $instancia = $this->instancia();

        $this->ingesta()->importarHistorial($instancia, $this->lote(
            [$this->mensaje('wamid.uno', self::CLIENTE, 'hola')],
            fase: 2,
            progreso: 100
        ));

        $payload = $this->lote([$this->mensaje('wamid.dos', self::CLIENTE, 'otra')], fase: 2);
        unset($payload['history'][0]['metadata']['progress']);

        $this->ingesta()->importarHistorial($instancia, $payload);

        $sync = CoexistenceSync::where('instance_id', $instancia->id)->first();

        $this->assertSame(100, $sync->progress, 'Un lote sin progreso borró el avance acumulado.');
        $this->assertSame(CoexistenceSync::COMPLETADA, $sync->status);
    }

    /** Un webhook de `history` con un solo hilo. */
    private function lote(array $mensajes, int $fase = 0, int $progreso = 10): array
    {
        return [
            'metadata' => ['display_phone_number' => self::NEGOCIO, 'phone_number_id' => '1247515825107349'],
            'history'  => [[
                'metadata' => ['phase' => $fase, 'chunk_order' => 1, 'progress' => $progreso],
                'threads'  => [['id' => self::CLIENTE, 'messages' => $mensajes]],
            ]],
        ];
    }

    private function mensaje(string $wamid, string $de, string $texto, ?int $timestamp = null): array
    {
        return [
            'from'            => $de,
            'id'              => $wamid,
            'timestamp'       => (string) ($timestamp ?? now()->timestamp),
            'type'            => 'text',
            'text'            => ['body' => $texto],
            'history_context' => ['status' => 'READ'],
        ];
    }
}
