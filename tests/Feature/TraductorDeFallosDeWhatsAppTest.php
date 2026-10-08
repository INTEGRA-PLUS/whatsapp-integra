<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppFailureTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Los motivos de fallo de Meta, dichos como los entiende quien atiende.
 *
 * Varios estaban mal: el 132007 se explicaba como «saltos de línea o espacios
 * de más» (es contenido contra las políticas; lo de los espacios es el 132018),
 * «part of an experiment» se metía con la baja voluntaria de marketing
 * (131050), y cualquier «rate limit hit» —también el de un solo destinatario—
 * acababa en el límite general de la línea. Códigos verificados contra la
 * tabla oficial de errores de la Cloud API.
 */
class TraductorDeFallosDeWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private function explicar(?string $codigo, ?string $texto = null): array
    {
        $mensaje = new WhatsAppMessage([
            'status' => 'failed',
            'error_code' => $codigo,
            'error_message' => $texto,
        ]);

        return app(WhatsAppFailureTranslator::class)->explain($mensaje);
    }

    public function test_el_132007_es_contenido_contra_las_politicas_y_el_132018_los_espacios(): void
    {
        $politica = $this->explicar('132007');
        $this->assertStringContainsString('políticas', $politica['title']);
        $this->assertStringNotContainsString('saltos de línea', $politica['detail']);

        $espacios = $this->explicar('132018');
        $this->assertStringContainsString('saltos de línea', $espacios['title']);
        $this->assertSame('permanent', $espacios['severity']);
    }

    public function test_el_experimento_de_marketing_no_es_una_baja(): void
    {
        $porTexto = $this->explicar(null, 'Message not sent as part of an experiment');
        $porCodigo = $this->explicar('130472');

        $this->assertSame($porCodigo['title'], $porTexto['title']);
        $this->assertStringContainsString('experimento', $porCodigo['title']);
        $this->assertNotSame($porCodigo['title'], $this->explicar('131050')['title']);
    }

    public function test_la_baja_de_marketing_dice_que_no_se_reintente(): void
    {
        $baja = $this->explicar('131050');

        $this->assertStringContainsString('baja', $baja['title']);
        $this->assertStringContainsString('No lo reintentes', $baja['action']);
        $this->assertSame('permanent', $baja['severity']);
    }

    public function test_el_131049_pide_esperar_24_horas(): void
    {
        $this->assertStringContainsString('24 horas', $this->explicar('131049')['action']);
    }

    public function test_el_limite_por_destinatario_no_es_el_de_la_linea(): void
    {
        $destinatario = $this->explicar(null, '(Business Account, Consumer Account) pair rate limit hit');
        $linea = $this->explicar(null, 'Rate limit hit');

        $this->assertSame($this->explicar('131056')['title'], $destinatario['title']);
        $this->assertSame($this->explicar('130429')['title'], $linea['title']);
        $this->assertNotSame($destinatario['title'], $linea['title']);

        $this->assertSame(
            $this->explicar('131056')['title'],
            $this->explicar(null, 'Too many messages sent from the sender phone number to the same recipient phone number in a short period of time')['title']
        );

        // Y el de spam sigue siendo el suyo.
        $this->assertSame($this->explicar('131048')['title'], $this->explicar(null, 'Spam rate limit hit')['title']);
    }

    public function test_el_token_caducado_pide_reconectar(): void
    {
        $caducado = $this->explicar('190');

        $this->assertSame('config', $caducado['severity']);
        $this->assertStringContainsString('volver a conectar', $caducado['action']);
        $this->assertSame($caducado['title'], $this->explicar(null, 'Error validating access token: Session has expired on Tuesday')['title']);
    }

    /** Cada código nuevo con su propia explicación, no la genérica. */
    public function test_los_codigos_nuevos_tienen_explicacion(): void
    {
        $generico = $this->explicar('999999')['title'];

        foreach (['4', '10', '80007', '131037', '131045', '131056', '131057', '132068', '132069', '130472', '132018'] as $codigo) {
            $explicacion = $this->explicar($codigo);

            $this->assertNotSame($generico, $explicacion['title'], "El {$codigo} cae en la explicación genérica.");
            $this->assertContains($explicacion['severity'], WhatsAppFailureTranslator::severities());
        }

        $this->assertStringContainsString('restringida o deshabilitada', $this->explicar('368')['title']);
    }

    /** Los titulares agrupan el resumen de motivos: no pueden repetirse entre códigos. */
    public function test_cada_codigo_tiene_su_propio_titular(): void
    {
        $traductor = app(WhatsAppFailureTranslator::class);

        foreach (['130472', '131050', '131056', '130429', '132007', '132018', '190', '0'] as $codigo) {
            $this->assertSame([$codigo], $traductor->matchersForTitle($this->explicar($codigo)['title'])['codes'], "Titular compartido por {$codigo}.");
        }
    }

    /**
     * La burbuja roja del chat enseñaba el texto de Meta en inglés. Ahora el
     * mensaje fallido viaja ya traducido, por la misma tabla que el panel.
     */
    public function test_el_mensaje_fallido_lleva_su_motivo_traducido_al_chat(): void
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet-'.Str::random(4), 'active' => true]);
        $instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'type' => 'meta',
            'active' => true,
        ]);
        $conversacion = WhatsAppConversation::create([
            'instance_id' => $instance->id,
            'wa_id' => '573001112233',
            'phone_number' => '573001112233',
            'name' => 'Marta',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $fallido = WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'type' => 'template',
            'direction' => 'outbound',
            'status' => 'failed',
            'error_code' => '131049',
            'error_message' => 'This message was not delivered to maintain healthy ecosystem engagement.',
        ]);
        $enviado = WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'type' => 'text',
            'content' => 'Hola',
            'direction' => 'outbound',
            'status' => 'sent',
        ]);

        $json = $fallido->fresh()->toArray();

        $this->assertSame($this->explicar('131049')['title'], $json['failure_reason']['title']);
        $this->assertStringContainsString('24 horas', $json['failure_reason']['action']);
        $this->assertNull($enviado->fresh()->toArray()['failure_reason']);
    }
}
