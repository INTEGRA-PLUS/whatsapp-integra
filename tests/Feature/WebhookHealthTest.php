<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La salud de un webhook: si entrega, no si está encendido.
 *
 * "ACTIVO" en verde sólo dice que el interruptor está puesto. En producción
 * había uno con 111 entregas, todas con HTTP 405, apuntando a una web en vez de
 * a una ruta que recibiera POSTs: llevaba meses sin entregar nada y la pantalla
 * lo mostraba en verde. Este es el estado que hay que enseñar.
 */
class WebhookHealthTest extends TestCase
{
    use RefreshDatabase;

    /** Sin entregas no se acusa a nadie: puede ser nuevo, o su evento no ha ocurrido. */
    public function test_un_webhook_sin_entregas_no_se_declara_roto(): void
    {
        $health = $this->endpoint()->deliveryHealth();

        $this->assertSame('idle', $health['state']);
        $this->assertSame(0, $health['total']);
        $this->assertStringContainsString('no se ha disparado', $health['says']);
    }

    public function test_manda_la_ultima_entrega_y_no_el_historico(): void
    {
        $endpoint = $this->endpoint();
        // Estuvo cayéndose y ya se arregló: lo que importa es cómo está ahora.
        $this->delivery($endpoint, 500, false);
        $this->delivery($endpoint, 500, false);
        $this->delivery($endpoint, 200, true);

        $health = $endpoint->deliveryHealth();

        $this->assertSame('ok', $health['state']);
        $this->assertSame(3, $health['total']);
        $this->assertSame(1, $health['ok']);
        $this->assertSame(2, $health['failed']);
        // Sin nada que arreglar no se sugiere nada.
        $this->assertNull($health['fix']);
    }

    /**
     * El 405 es el caso real que motivó todo esto: la URL apunta a una página
     * web, que existe pero no acepta que le manden datos.
     */
    public function test_el_405_explica_que_la_url_es_una_web_y_no_un_endpoint(): void
    {
        $endpoint = $this->endpoint();
        $this->delivery($endpoint, 405, false);

        $health = $endpoint->deliveryHealth();

        $this->assertSame('failing', $health['state']);
        $this->assertStringContainsString('405', $health['says']);
        $this->assertStringContainsString('no acepta POST', $health['fix']);
        $this->assertStringContainsString('página web', $health['fix']);
    }

    /** Un 5xx es problema del receptor, y decirlo evita que revisen la URL en vano. */
    public function test_un_error_del_servidor_receptor_se_atribuye_a_su_lado(): void
    {
        $endpoint = $this->endpoint();
        $this->delivery($endpoint, 502, false);

        $this->assertStringContainsString('falló al procesarlo', $endpoint->deliveryHealth()['fix']);
    }

    /** Sin respuesta no hay código: la dirección puede no ser alcanzable. */
    public function test_sin_respuesta_se_dice_que_no_contesto(): void
    {
        $endpoint = $this->endpoint();
        $this->delivery($endpoint, null, false);

        $health = $endpoint->deliveryHealth();

        $this->assertStringContainsString('sin respuesta', $health['says']);
        $this->assertStringContainsString('no contestó', $health['fix']);
    }

    /** La salud viaja con el webhook al serializarlo: la pantalla la necesita. */
    public function test_la_salud_viaja_en_el_json_del_webhook(): void
    {
        $endpoint = $this->endpoint();
        $this->delivery($endpoint, 405, false);

        $this->assertSame('failing', $endpoint->fresh()->toArray()['health']['state']);
    }

    private function endpoint(): WebhookEndpoint
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true]);

        return WebhookEndpoint::create([
            'company_id' => $company->id,
            'name' => 'CMNET - Message Sent',
            'url' => 'https://cmnet.online/software',
            'events' => ['message.sent'],
            'active' => true,
        ]);
    }

    private function delivery(WebhookEndpoint $endpoint, ?int $code, bool $success): void
    {
        DB::table('webhook_deliveries')->insert([
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'message.sent',
            'payload' => json_encode([]),
            'status_code' => $code,
            'success' => $success,
            'attempts' => 1,
            'created_at' => now(),
        ]);
    }
}
