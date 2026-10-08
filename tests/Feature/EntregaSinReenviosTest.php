<?php

namespace Tests\Feature;

use App\Jobs\DeliverWhatsAppMessage;
use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\MetaWhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lo que sale del chat sale una vez, y con el token de su empresa.
 *
 * EL FALLO QUE SE PREVIENE (1-oct-2026): DeliverWhatsAppMessage guardaba el
 * wamid al final, después de copiar a S3 el adjunto de la plantilla. Si esa
 * copia se colgaba y el job moría por tiempo, la fila seguía "pending" sin
 * wamid y el reintento le mandaba la factura al cliente otra vez. Y el servicio
 * buscaba el token por `phone_number_id` sin empresa: con el número reclamado
 * por dos empresas, el mensaje salía con el token que la base devolviera antes.
 */
class EntregaSinReenviosTest extends TestCase
{
    use RefreshDatabase;

    private function instancia(string $phoneNumberId, string $token, array $extra = []): Instance
    {
        $company = Company::create(['name' => 'Emp '.Str::random(4), 'slug' => 'emp-'.Str::random(6), 'active' => true]);

        return Instance::create(array_merge([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => $phoneNumberId,
            'waba_id' => 'waba-'.Str::random(4),
            'type' => 'meta',
            'active' => true,
            'access_token' => $token,
        ], $extra));
    }

    private function plantillaPendiente(Instance $instance, array $components): WhatsAppMessage
    {
        $conversation = WhatsAppConversation::create([
            'instance_id' => $instance->id,
            'wa_id' => '573001112233',
            'phone_number' => '573001112233',
            'name' => 'Cliente',
            'status' => 'open',
        ]);

        return WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type' => 'template',
            'content' => 'Tu factura',
            'direction' => 'outbound',
            'status' => 'pending',
            'metadata' => ['template' => 'facturacion', 'language' => 'es', 'components' => $components],
        ]);
    }

    private function entregar(int $messageId): void
    {
        (new DeliverWhatsAppMessage($messageId))->handle(app(MetaWhatsAppService::class));
    }

    /**
     * El caso del incidente: Meta acepta, la copia del adjunto se cuelga y el
     * job muere. Cuando empieza la copia el wamid ya tiene que estar guardado,
     * y el reintento no puede volver a llamar a Meta.
     */
    public function test_si_el_job_muere_despues_de_que_meta_acepta_el_reintento_no_reenvia(): void
    {
        Storage::fake('s3_media');

        $instance = $this->instancia('111222333444555', 'token-a');
        $message = $this->plantillaPendiente($instance, [[
            'type' => 'header',
            'parameters' => [['type' => 'document', 'document' => ['id' => '987654321012', 'filename' => 'factura.pdf']]],
        ]]);

        $envios = 0;
        $wamidAlCopiar = 'sin-mirar';

        Http::fake(function ($request) use (&$envios, &$wamidAlCopiar, $message) {
            $url = $request->url();

            if (str_contains($url, '/message_templates')) {
                return Http::response(['data' => []], 200);
            }

            if (str_ends_with($url, '/messages')) {
                $envios++;

                return Http::response(['messages' => [['id' => 'wamid.UNICO']]], 200);
            }

            if (str_contains($url, 'lookaside')) {
                // El momento en que antes moría el job.
                $wamidAlCopiar = WhatsAppMessage::whereKey($message->id)->value('wamid');

                throw new ConnectionException('Operation timed out');
            }

            // La ficha del media (guardarraíl y primera mitad de la descarga).
            return Http::response(['url' => 'https://lookaside.fbsbx.com/x', 'mime_type' => 'application/pdf', 'file_size' => 1000], 200);
        });

        $this->entregar($message->id);

        $this->assertSame('wamid.UNICO', $wamidAlCopiar,
            'Cuando se copia el adjunto, el wamid ya tiene que estar guardado: si el job muere ahí, el reintento lo ve.');

        $message->refresh();
        $this->assertSame('sent', $message->status, 'Que falle la copia no convierte en fallido lo que ya salió.');
        $this->assertSame('987654321012', $message->media_id);

        // El reintento de la cola.
        $this->entregar($message->id);

        $this->assertSame(1, $envios, 'El cliente recibió la plantilla dos veces.');
    }

    public function test_el_guardarrail_deja_un_motivo_legible_en_la_burbuja(): void
    {
        $instance = $this->instancia('111222333444556', 'token-a');
        // La plantilla pide un dato y la burbuja no lleva ninguno.
        $message = $this->plantillaPendiente($instance, []);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/message_templates')) {
                return Http::response(['data' => [[
                    'id' => 'tpl-1', 'name' => 'facturacion', 'language' => 'es', 'status' => 'APPROVED',
                    'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, tu factura está lista.']],
                ]]], 200);
            }

            return Http::response(['messages' => [['id' => 'wamid.NO']]], 200);
        });

        $this->entregar($message->id);

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertNotEmpty($message->error_details);
        $this->assertStringNotContainsString('template_', $message->error_details,
            'El chat enseña error_details como motivo: no puede ser el código interno del guardarraíl.');
    }

    public function test_cada_envio_sale_con_el_token_de_su_empresa(): void
    {
        // Dos empresas reclaman el mismo número, con tokens distintos.
        $primera = $this->instancia('555000111222333', 'token-de-la-primera');
        $segunda = $this->instancia('555000111222333', 'token-de-la-segunda');

        $tokens = [];
        Http::fake(function ($request) use (&$tokens) {
            $tokens[] = $request->header('Authorization')[0] ?? null;

            return Http::response(['messages' => [['id' => 'wamid.'.Str::random(6)]]], 200);
        });

        $meta = app(MetaWhatsAppService::class);

        $this->assertTrue($meta->sendMessage($segunda, '573001112233', 'Hola')['success']);
        $this->assertSame(['Bearer token-de-la-segunda'], $tokens);

        // Con el número a secas no se sabe de quién es: error claro, sin enviar.
        $ambiguo = $meta->sendMessage('555000111222333', '573001112233', 'Hola');

        $this->assertFalse($ambiguo['success']);
        $this->assertSame('ambiguous_instance', $ambiguo['error']['error']['code']);
        $this->assertCount(1, $tokens, 'Con el número ambiguo no debía salir nada.');

        $subida = $meta->uploadMedia('555000111222333', __FILE__, 'text/plain');
        $this->assertFalse($subida['success']);
        $this->assertCount(1, $tokens);
    }

    public function test_una_fila_apagada_del_mismo_numero_no_compite_con_la_activa(): void
    {
        $this->instancia('777000111222333', 'token-viejo', ['active' => false]);
        $this->instancia('777000111222333', 'token-vigente');

        $tokens = [];
        Http::fake(function ($request) use (&$tokens) {
            $tokens[] = $request->header('Authorization')[0] ?? null;

            return Http::response(['messages' => [['id' => 'wamid.1']]], 200);
        });

        $this->assertTrue(app(MetaWhatsAppService::class)->sendMessage('777000111222333', '573001112233', 'Hola')['success']);
        $this->assertSame(['Bearer token-vigente'], $tokens);
    }
}
