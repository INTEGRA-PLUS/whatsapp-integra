<?php

namespace Tests\Feature;

use App\Jobs\DeliverWhatsAppMessage;
use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\MetaWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La línea cambió de número y el cliente sólo le había escrito al anterior.
 *
 * EL FALLO QUE SE PREVIENE: el 6-oct-2026 CMNET pasó su línea a un número
 * nuevo. A los clientes que le habían escrito al viejo, Meta les rechazaba
 * hasta un «buenas tardes» con «(#131005) Access denied», y el agente sólo veía
 * eso. Ahora el aviso dice que el cliente escribió al número anterior y qué
 * hacer para retomar.
 */
class CambioDeNumeroTest extends TestCase
{
    use RefreshDatabase;

    private Instance $linea;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();

        $empresa = Company::create(['name' => 'CMNET', 'slug' => 'cmnet-'.Str::random(4), 'active' => true]);

        $this->agente = User::create([
            'company_id' => $empresa->id,
            'name' => 'cment',
            'email' => Str::random(6).'@cmnet.test',
            'password' => 'secret',
            'active' => true,
        ]);

        $this->linea = Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'principal',
            'phone_number_id' => '1177962515404155',
            'display_phone_number' => '+57 300 0000000',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        Http::fake(fn () => Http::response(['error' => [
            'message' => '(#131005) Access denied',
            'code' => 131005,
            'error_data' => ['details' => 'There was a problem with the access token or permissions you are using for the API call.'],
        ]], 403));
    }

    private function cambiarDeNumero(): void
    {
        $this->linea->update(['phone_number_id' => '1333422603193430', 'display_phone_number' => '+57 310 4047030', 'waba_id' => '1638591804306129']);
    }

    private function conversacionConEntranteHace(\DateTimeInterface $cuando): WhatsAppConversation
    {
        $conversacion = WhatsAppConversation::create([
            'instance_id' => $this->linea->id,
            'wa_id' => '573114300668',
            'name' => 'Auriestela',
            'status' => 'open',
            'last_message_at' => $cuando,
        ]);

        WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'wamid' => 'wamid.IN'.Str::random(8),
            'type' => 'text',
            'content' => 'Buenos días',
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => $cuando,
        ]);

        return $conversacion;
    }

    private function enviar(WhatsAppConversation $conversacion): WhatsAppMessage
    {
        $mensaje = WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'type' => 'text',
            'content' => 'buenas tardes',
            'direction' => 'outbound',
            'status' => 'pending',
            'sent_by' => $this->agente->id,
        ]);

        (new DeliverWhatsAppMessage($mensaje->id))->handle(app(MetaWhatsAppService::class));

        return $mensaje->refresh();
    }

    public function test_al_cambiar_de_numero_se_guarda_el_anterior(): void
    {
        $this->cambiarDeNumero();

        $this->linea->refresh();
        $this->assertSame('1177962515404155', $this->linea->numero_anterior_id);
        $this->assertSame('+57 300 0000000', $this->linea->numero_anterior_visible);
        $this->assertNotNull($this->linea->numero_cambiado_at);
    }

    public function test_cambiar_otra_cosa_no_cuenta_como_cambio_de_numero(): void
    {
        $this->linea->update(['name' => 'principal 2']);

        $this->assertNull($this->linea->refresh()->numero_cambiado_at);
    }

    public function test_el_cliente_que_escribio_al_numero_anterior_recibe_la_explicacion(): void
    {
        $conversacion = $this->conversacionConEntranteHace(now()->subHour());
        $this->cambiarDeNumero();

        $mensaje = $this->enviar($conversacion);

        $this->assertSame('failed', $mensaje->status);
        $this->assertSame('131005', (string) $mensaje->error_code);
        $this->assertStringContainsString('al número anterior (+57 300 0000000)', $mensaje->error_details);
        $this->assertStringContainsString('plantilla aprobada', $mensaje->error_details);
    }

    public function test_si_ya_escribio_al_numero_nuevo_se_deja_el_error_de_meta(): void
    {
        $this->cambiarDeNumero();
        $conversacion = $this->conversacionConEntranteHace(now()->addMinute());

        $mensaje = $this->enviar($conversacion);

        $this->assertStringStartsWith('There was a problem with the access token', $mensaje->error_details);
    }

    public function test_una_linea_que_nunca_cambio_de_numero_no_se_toca(): void
    {
        $conversacion = $this->conversacionConEntranteHace(now()->subHour());

        $mensaje = $this->enviar($conversacion);

        $this->assertStringStartsWith('There was a problem with the access token', $mensaje->error_details);
    }
}
