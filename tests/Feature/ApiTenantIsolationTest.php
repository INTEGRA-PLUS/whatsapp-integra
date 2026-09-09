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
 * La API pública no puede enseñar los mensajes de otra empresa.
 *
 * `/api/v1/whatsapp-messages` filtraba sólo por `incoming_company_nit`, que es
 * una etiqueta que elige quien llama —el ERP la manda al registrar el mensaje—
 * y no una credencial. Quien tuviera un token válido podía pedir el NIT de otra
 * empresa y recibir sus mensajes.
 *
 * Aquí no se prueba un texto de error: se prueba que los datos ajenos no salen.
 */
class ApiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const NIT_AJENO = 900123456;

    public function test_no_devuelve_los_mensajes_de_otra_empresa_aunque_se_pida_su_nit(): void
    {
        [$miInstancia, $mio] = $this->empresaConMensaje('Mi mensaje', 800111222);
        [, $ajeno] = $this->empresaConMensaje('Mensaje de la otra empresa', self::NIT_AJENO);

        $res = $this->withHeader('X-Instance-Token', $miInstancia->phone_number_id)
            ->getJson('/api/v1/whatsapp-messages?'.http_build_query([
                'incoming_company_nit' => self::NIT_AJENO,
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
            ]))
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertNotContains($ajeno->id, $ids, 'La API devolvió un mensaje de otra empresa.');
        $this->assertSame([], $ids, 'Con el NIT ajeno no debe salir nada.');

        // Y el contenido tampoco viaja por otro camino.
        $this->assertStringNotContainsString('Mensaje de la otra empresa', $res->getContent());

        // Que no se haya roto: con el NIT propio sí devuelve lo propio.
        $propios = $this->withHeader('X-Instance-Token', $miInstancia->phone_number_id)
            ->getJson('/api/v1/whatsapp-messages?'.http_build_query([
                'incoming_company_nit' => 800111222,
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->json('data');

        $this->assertSame([$mio->id], collect($propios)->pluck('id')->all());
    }

    public function test_el_filtro_por_estado_sigue_funcionando(): void
    {
        [$instancia, $mensaje] = $this->empresaConMensaje('Entregado', 800111222, 'delivered');

        $res = $this->withHeader('X-Instance-Token', $instancia->phone_number_id)
            ->getJson('/api/v1/whatsapp-messages?'.http_build_query([
                'incoming_company_nit' => 800111222,
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
                'status' => 'delivered',
            ]))
            ->assertOk();

        $this->assertSame([$mensaje->id], collect($res->json('data'))->pluck('id')->all());

        $vacio = $this->withHeader('X-Instance-Token', $instancia->phone_number_id)
            ->getJson('/api/v1/whatsapp-messages?'.http_build_query([
                'incoming_company_nit' => 800111222,
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
                'status' => 'failed',
            ]))
            ->assertOk();

        $this->assertSame([], $vacio->json('data'));
    }

    /** @return array{0: Instance, 1: WhatsAppMessage} */
    private function empresaConMensaje(string $texto, int $nit, string $estado = 'sent'): array
    {
        $company = Company::create([
            'name' => 'Empresa '.Str::random(4),
            'slug' => 'e-'.Str::random(8),
            'active' => true,
        ]);

        $instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => (string) random_int(1000000000000, 9999999999999),
            'waba_id' => 'waba-'.Str::random(5),
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        $numero = '5730'.random_int(10000000, 99999999);

        $conversation = WhatsAppConversation::create([
            'instance_id' => $instance->id,
            'wa_id' => $numero,
            'phone_number' => $numero,
            'name' => 'Cliente',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $mensaje = WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'wamid' => 'wamid.'.Str::random(12),
            'type' => 'text',
            'content' => $texto,
            'direction' => 'outbound',
            'status' => $estado,
            'sent_at' => now(),
            'incoming_company_nit' => $nit,
        ]);

        return [$instance, $mensaje];
    }
}
