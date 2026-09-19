<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El globo verde de la bandeja tiene que decir la verdad.
 *
 * `unread_count` sólo sabía subir, y bajaba en un único sitio: al abrir el chat
 * en el CRM. En un número en coexistencia el asesor contesta desde el celular y
 * no abre el CRM nunca, así que el contador crecía sin fin. El 19-sep-2026
 * Transinternet tenía 783 conversaciones con el globo encendido cuyo último
 * mensaje era la respuesta del propio asesor —3.694 "sin leer" inventados—, y
 * las 783 se habían contestado desde el móvil: ni una desde el CRM, ni una por
 * el bot.
 *
 * Lo que se protege aquí es qué cuenta como "ya atendido", que es donde está
 * todo el riesgo: pasarse deja clientes sin avisar.
 */
class RecalcularSinLeerTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instancia;

    protected function setUp(): void
    {
        parent::setUp();

        $empresa = Company::create(['name' => 'Fibra Sur', 'slug' => 'fibra-'.Str::random(5), 'active' => true]);

        $this->instancia = Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '11779625154'.random_int(10000, 99999),
            'waba_id' => 'waba-'.Str::random(6),
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);
    }

    /** Si el asesor ya contestó, lo de antes está leído. */
    public function test_apaga_el_globo_cuando_el_asesor_ya_respondio(): void
    {
        $conv = $this->conversacion(2);
        $this->mensaje($conv, 'inbound');
        $this->mensaje($conv, 'inbound');
        $this->mensaje($conv, 'outbound');

        $this->correr();

        $this->assertSame(0, $conv->fresh()->unread_count);
    }

    /** Pero lo que llegó después sigue contando. */
    public function test_cuenta_lo_que_llego_despues_de_la_respuesta(): void
    {
        $conv = $this->conversacion(9);
        $this->mensaje($conv, 'inbound');
        $this->mensaje($conv, 'outbound');
        $this->mensaje($conv, 'inbound');
        $this->mensaje($conv, 'inbound');

        $this->correr();

        $this->assertSame(2, $conv->fresh()->unread_count);
    }

    /**
     * Una tanda masiva no atiende a nadie.
     *
     * Sin excluirla, la campaña del viernes apagaría de una vez el aviso de
     * todos los chats con preguntas pendientes.
     */
    public function test_una_campana_no_cuenta_como_atender(): void
    {
        $conv = $this->conversacion(2);
        $this->mensaje($conv, 'inbound');
        $this->mensaje($conv, 'inbound');
        $this->mensaje($conv, 'outbound', ['campaign_id' => $this->campana()]);

        $this->correr();

        $this->assertSame(2, $conv->fresh()->unread_count);
    }

    /** Ni el menú ni la IA: contestan solos, sin que nadie haya leído nada. */
    public function test_el_bot_no_cuenta_como_atender(): void
    {
        $conv = $this->conversacion(2);
        $this->mensaje($conv, 'inbound');
        $this->mensaje($conv, 'inbound');
        $this->mensaje($conv, 'outbound', ['metadata' => ['ia' => true]]);

        $porMenu = $this->conversacion(3, '573002');
        $this->mensaje($porMenu, 'inbound');
        $this->mensaje($porMenu, 'inbound');
        $this->mensaje($porMenu, 'inbound');
        $this->mensaje($porMenu, 'outbound', ['metadata' => ['menu_id' => 4]]);

        $this->correr();

        $this->assertSame(2, $conv->fresh()->unread_count, 'La IA no ha leído nada.');
        $this->assertSame(3, $porMenu->fresh()->unread_count, 'El menú tampoco.');
    }

    /** Un aviso del sistema no es el cliente pidiendo nada. */
    public function test_los_avisos_del_sistema_no_cuentan(): void
    {
        $conv = $this->conversacion(3);
        $this->mensaje($conv, 'inbound');
        $this->mensaje($conv, 'outbound');
        $this->mensaje($conv, 'inbound', ['type' => 'system', 'content' => 'El cliente envió un mensaje (revoke).']);

        $this->correr();

        $this->assertSame(0, $conv->fresh()->unread_count);
    }

    /** Sin --apply no se toca nada: es un comando que reescribe datos. */
    public function test_sin_apply_no_cambia_nada(): void
    {
        $conv = $this->conversacion(7);
        $this->mensaje($conv, 'inbound');
        $this->mensaje($conv, 'outbound');

        $this->artisan('whatsapp:recalcular-sin-leer')->assertExitCode(0);

        $this->assertSame(7, $conv->fresh()->unread_count);
    }

    /** Y un chat cerrado no se toca: su globo ya no se mira. */
    public function test_no_toca_las_conversaciones_cerradas(): void
    {
        $conv = $this->conversacion(4);
        $conv->update(['status' => 'closed']);
        $this->mensaje($conv, 'inbound');
        $this->mensaje($conv, 'outbound');

        $this->correr();

        $this->assertSame(4, $conv->fresh()->unread_count);
    }

    private function correr(): void
    {
        $this->artisan('whatsapp:recalcular-sin-leer --apply')->assertExitCode(0);
    }

    private function campana(): int
    {
        return \DB::table('whatsapp_campaigns')->insertGetId([
            'company_id' => $this->instancia->company_id,
            'instance_id' => $this->instancia->id,
            'name' => 'Aviso de corte',
            'status' => 'sent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function conversacion(int $sinLeer, string $waId = '573001'): WhatsAppConversation
    {
        return WhatsAppConversation::create([
            'instance_id' => $this->instancia->id,
            'wa_id' => $waId,
            'phone_number' => $waId,
            'name' => 'Cliente',
            'status' => 'open',
            'unread_count' => $sinLeer,
            'last_message_at' => now(),
        ]);
    }

    private function mensaje(WhatsAppConversation $conv, string $direction, array $extra = []): void
    {
        WhatsAppMessage::create(array_merge([
            'conversation_id' => $conv->id,
            'wamid' => 'wamid.'.Str::random(12),
            'type' => 'text',
            'content' => 'Hola',
            'direction' => $direction,
            'status' => 'delivered',
            'is_internal' => false,
            'sent_at' => now(),
        ], $extra));
    }
}
