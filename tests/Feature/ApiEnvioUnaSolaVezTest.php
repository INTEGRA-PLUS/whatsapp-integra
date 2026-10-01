<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Una factura del ERP sale una vez aunque el ERP la pida dos.
 *
 * EL FALLO QUE SE PREVIENE (1-oct-2026): el ERP repite la llamada cuando no le
 * llega respuesta o recibe un 500. `/messages/template` copiaba el adjunto de
 * la plantilla *antes* de guardar la burbuja y sin tiempo límite; si la copia
 * tardaba, el ERP daba la petición por perdida y la repetía, y el cliente
 * recibía —y la empresa pagaba— la misma factura dos veces. Y cualquier
 * rechazo de Meta salía como 500, que para el ERP significa «repite».
 */
class ApiEnvioUnaSolaVezTest extends TestCase
{
    use RefreshDatabase;

    private function linea(): array
    {
        $company = Company::create(['name' => 'Conecta '.Str::random(3), 'slug' => 'conecta-'.Str::random(6), 'active' => true]);

        $instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => (string) random_int(1000000000000, 9999999999999),
            'waba_id' => 'waba-'.Str::random(4),
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-'.Str::random(6),
        ]);

        return [$instance, $instance->generarApiToken()];
    }

    /**
     * Graph falso con un único closure. `$envio` decide la respuesta a cada
     * POST /messages; devuelve cuántos se hicieron.
     */
    private function fakeGraph(?\Closure $envio = null): \ArrayObject
    {
        $enviados = new \ArrayObject;

        Http::fake(function ($request) use ($envio, $enviados) {
            $url = $request->url();

            if (str_contains($url, '/message_templates')) {
                return Http::response(['data' => []], 200);
            }

            if (str_ends_with($url, '/messages')) {
                $enviados->append($request->data());

                return $envio
                    ? $envio(count($enviados))
                    : Http::response(['messages' => [['id' => 'wamid.'.Str::random(8)]]], 200);
            }

            if (str_contains($url, 'lookaside')) {
                throw new ConnectionException('Operation timed out');
            }

            return Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'application/pdf', 'file_size' => 1000], 200);
        });

        return $enviados;
    }

    private function plantilla(string $token, array $extra = [], array $cabeceras = [])
    {
        return $this->withHeaders(['X-Instance-Token' => $token] + $cabeceras)
            ->postJson('/api/v1/messages/template', array_merge([
                'to' => '573001112233',
                'template_name' => 'facturacion',
                'language_code' => 'es',
            ], $extra));
    }

    public function test_la_misma_idempotency_key_no_envia_dos_veces(): void
    {
        $enviados = $this->fakeGraph();
        [, $token] = $this->linea();

        $primera = $this->plantilla($token, [], ['Idempotency-Key' => 'erp-envio-1'])->assertOk();
        $segunda = $this->plantilla($token, [], ['Idempotency-Key' => 'erp-envio-1'])->assertOk();

        $this->assertCount(1, $enviados, 'El reintento del ERP volvió a enviar la plantilla.');
        $this->assertSame($primera->json('wamid'), $segunda->json('wamid'));
        $this->assertSame($primera->json('message_id'), $segunda->json('message_id'));
        $segunda->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, WhatsAppMessage::count());

        // Otra clave es otro envío.
        $this->plantilla($token, [], ['Idempotency-Key' => 'erp-envio-2'])->assertOk();
        $this->assertCount(2, $enviados);
    }

    public function test_la_misma_factura_a_la_misma_persona_sale_una_vez_aunque_no_traiga_cabecera(): void
    {
        $enviados = $this->fakeGraph();
        [, $token] = $this->linea();

        $this->plantilla($token, ['incoming_invoice_id' => 4311])->assertOk();
        // El mismo número escrito de otra forma sigue siendo la misma persona.
        $this->plantilla($token, ['incoming_invoice_id' => 4311, 'to' => '+57 300 111 2233'])->assertOk();

        $this->assertCount(1, $enviados);

        // Otra factura sí sale.
        $this->plantilla($token, ['incoming_invoice_id' => 4312])->assertOk();
        $this->assertCount(2, $enviados);
    }

    public function test_la_clave_es_de_cada_empresa(): void
    {
        $enviados = $this->fakeGraph();
        [, $tokenA] = $this->linea();
        [, $tokenB] = $this->linea();

        $this->plantilla($tokenA, ['incoming_invoice_id' => 100])->assertOk();
        $this->plantilla($tokenB, ['incoming_invoice_id' => 100])->assertOk();

        $this->assertCount(2, $enviados, 'La factura 100 de una empresa bloqueó la factura 100 de otra.');
    }

    public function test_un_rechazo_de_meta_es_422_con_su_codigo_y_no_bloquea_el_reintento(): void
    {
        $enviados = $this->fakeGraph(fn (int $n) => $n === 1
            ? Http::response(['error' => [
                'message' => '(#132001) Template name does not exist in the translation',
                'code' => 132001,
                'error_data' => ['details' => 'template name (facturacion) does not exist in es'],
            ]], 400)
            : Http::response(['messages' => [['id' => 'wamid.SEGUNDO']]], 200));
        [, $token] = $this->linea();

        $this->plantilla($token, ['incoming_invoice_id' => 9], ['Idempotency-Key' => 'k-9'])
            ->assertStatus(422)
            ->assertJson([
                'success' => false,
                'code' => 132001,
                'meta_code' => 132001,
                'details' => 'template name (facturacion) does not exist in es',
            ])
            ->assertJsonPath('error', '(#132001) Template name does not exist in the translation');

        // Corregida la plantilla en Meta, el ERP repite con la misma clave y sale.
        $this->plantilla($token, ['incoming_invoice_id' => 9], ['Idempotency-Key' => 'k-9'])
            ->assertOk()
            ->assertJsonPath('wamid', 'wamid.SEGUNDO');

        $this->assertCount(2, $enviados);
    }

    public function test_si_meta_pide_bajar_el_ritmo_se_contesta_429(): void
    {
        $this->fakeGraph(fn () => Http::response(['error' => ['message' => 'Rate limit hit', 'code' => 130429]], 400));
        [, $token] = $this->linea();

        $this->plantilla($token)
            ->assertStatus(429)
            ->assertHeader('Retry-After', '60')
            ->assertJsonPath('code', 130429);
    }

    public function test_si_meta_no_responde_sigue_siendo_500(): void
    {
        $this->fakeGraph(fn () => Http::response(['error' => ['message' => 'Service unavailable', 'code' => 2]], 503));
        [, $token] = $this->linea();

        $this->plantilla($token)->assertStatus(500)->assertJsonPath('success', false);
    }

    public function test_la_burbuja_se_guarda_aunque_la_copia_del_adjunto_falle(): void
    {
        Storage::fake('s3_media');
        $enviados = $this->fakeGraph();
        [, $token] = $this->linea();

        $this->plantilla($token, ['components' => [[
            'type' => 'header',
            'parameters' => [['type' => 'document', 'document' => ['id' => '987654321012', 'filename' => 'factura.pdf']]],
        ]]])->assertOk();

        $mensaje = WhatsAppMessage::sole();
        $this->assertNotNull($mensaje->wamid);
        $this->assertSame('987654321012', $mensaje->media_id);
        $this->assertSame('factura.pdf', $mensaje->filename);
        $this->assertNull($mensaje->media_url, 'La copia falló: el chat la reintentará al abrir el mensaje.');
        $this->assertCount(1, $enviados);
    }

    /**
     * El primer intento murió después de que Meta aceptara y de guardar la
     * burbuja, pero antes de apuntar la respuesta. El reintento la encuentra
     * por su marca y contesta con ella.
     */
    public function test_un_intento_que_murio_a_medias_no_se_repite_si_la_burbuja_existe(): void
    {
        $enviados = $this->fakeGraph();
        [$instance, $token] = $this->linea();

        $hash = hash('sha256', 'cabecera:erp-77');
        Cache::put("api:idempotencia:{$instance->company_id}:{$hash}", ['estado' => 'en_curso', 'desde' => now()->timestamp], now()->addDay());

        $conversacion = WhatsAppConversation::resolveFor($instance->id, '573001112233', [
            'phone_number' => '573001112233', 'name' => 'Cliente', 'status' => 'open',
        ]);
        $previo = WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'wamid' => 'wamid.DEL_PRIMERO',
            'type' => 'text',
            'content' => 'Factura',
            'direction' => 'outbound',
            'status' => 'sent',
            'metadata' => ['template' => 'facturacion', 'idempotency_key' => $hash],
        ]);

        $this->plantilla($token, [], ['Idempotency-Key' => 'erp-77'])
            ->assertOk()
            ->assertJsonPath('message_id', $previo->id)
            ->assertJsonPath('wamid', 'wamid.DEL_PRIMERO');

        $this->assertCount(0, $enviados);
    }

    public function test_un_envio_en_curso_no_se_duplica(): void
    {
        $enviados = $this->fakeGraph();
        [$instance, $token] = $this->linea();

        $hash = hash('sha256', 'cabecera:erp-88');
        Cache::put("api:idempotencia:{$instance->company_id}:{$hash}", ['estado' => 'en_curso', 'desde' => now()->timestamp], now()->addDay());

        $this->plantilla($token, [], ['Idempotency-Key' => 'erp-88'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'idempotency_in_progress');

        $this->assertCount(0, $enviados);
    }

    public function test_el_texto_con_la_misma_clave_tampoco_se_repite(): void
    {
        $enviados = $this->fakeGraph();
        [$instance, $token] = $this->linea();

        // Ventana abierta: el cliente acaba de escribir.
        $conversacion = WhatsAppConversation::resolveFor($instance->id, '573001112233', [
            'phone_number' => '573001112233', 'name' => 'Cliente', 'status' => 'open',
        ]);
        WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'wamid' => 'wamid.ENTRANTE',
            'type' => 'text',
            'content' => 'Hola',
            'direction' => 'inbound',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        foreach ([1, 2] as $_) {
            $this->withHeaders(['X-Instance-Token' => $token, 'Idempotency-Key' => 'aviso-1'])
                ->postJson('/api/v1/messages/send', ['to' => '573001112233', 'message' => 'Tu pago se registró'])
                ->assertOk();
        }

        $this->assertCount(1, $enviados);
    }
}
