<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Services\Embeddings;
use App\Services\OnePayClient;
use App\Support\Configuracion;
use App\Support\Suscripcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Una variable que se quedó con el texto de ejemplo no está configurada.
 *
 * Tres veces ha pasado en este proyecto y las tres costaron caro:
 * `META_APP_SECRETS` vacía tumbó todos los webhooks de Meta con 403;
 * `META_ES_CONFIG_ID` desapareció en un rebuild y el botón de conectar WhatsApp
 * dejó de pintarse; y los tres secretos de OnePay se quedaron en producción con
 * `<el appkey de la cuenta>` literal, copiado de un comando de la documentación.
 *
 * Las tres en silencio. El patrón no es que falten —`filled()` las da por
 * buenas— sino que están puestas con algo que no sirve.
 */
class MarcadoresSinSustituirTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function reconoce_los_huecos_que_deja_una_plantilla(): void
    {
        foreach ([
            '',
            '   ',
            '<el appkey de la cuenta>',
            '<tu-token-aqui>',
            'tu-appkey',
            'su_token',
            'your-secret',
            'xxx',
            'CAMBIAR',
            'pendiente',
        ] as $hueco) {
            $this->assertFalse(
                Configuracion::puesta($hueco),
                "«{$hueco}» es un hueco, no un valor."
            );
        }
    }

    /** Y no se come valores buenos que casualmente se le parezcan. */
    public function test_no_descarta_un_valor_de_verdad(): void
    {
        foreach ([
            'wh_tok_aBcD1234',
            'http://ollama:11434',
            'https://api.onepay.la/v1',
            // Empieza por «tu» pero es una palabra, no el prefijo de plantilla.
            'turbo-token-9f8a',
            // Lleva `<` pero no es un marcador entero.
            'a<b',
            '0',
        ] as $bueno) {
            $this->assertTrue(
                Configuracion::puesta($bueno),
                "«{$bueno}» es un valor de verdad y no se puede descartar."
            );
        }
    }

    /**
     * Con el token de ejemplo, el cobro no sale hacia la pasarela.
     *
     * Antes salía: `filled()` daba el marcador por bueno, así que la petición
     * viajaba con «<el appkey de la cuenta>» de Bearer y volvía como un 401 que
     * había que ir a buscar al log para entender por qué no llegaba nada.
     *
     * @test
     */
    public function con_el_token_de_ejemplo_no_se_llama_a_onepay(): void
    {
        config(['services.onepay.token' => '<el appkey de la cuenta>']);

        Http::fake();

        $company = Company::create([
            'name' => 'Fibra XYZ', 'slug' => 'fibra-xyz', 'active' => true,
            'plan' => 'basico', 'cobro' => 'activo', 'ciclo' => 'mensual',
        ]);

        $this->assertFalse(OnePayClient::configurado());
        $this->assertNull(OnePayClient::crearFactura(Suscripcion::emitir($company)));

        Http::assertNothingSent();
    }

    /**
     * Y ese mismo texto tampoco vale como firma del webhook.
     *
     * Es lo peligroso de verdad: el marcador está escrito en la documentación
     * del repo. Si se quedara puesto y alguien pasara el modo a `exigir`, quien
     * lo leyera podría firmar como bueno el aviso de un pago que nadie hizo.
     *
     * @test
     */
    public function el_texto_de_ejemplo_no_firma_un_pago(): void
    {
        config([
            'services.onepay.webhook_header' => '<el header secreto>',
            'services.onepay.webhook_secret' => '<el secreto>',
            'services.onepay.webhook_modo' => 'exigir',
        ]);

        $this->withHeaders(['X-Signature' => '<el header secreto>'])
            ->postJson('/pagos/onepay', ['event' => ['type' => 'invoice.paid']])
            ->assertStatus(401);
    }

    /** Sin URL de verdad no se calcula ningún vector. */
    public function test_con_la_url_de_ejemplo_no_se_llama_al_modelo(): void
    {
        config(['services.embeddings.url' => '<la url de ollama>']);

        Http::fake();

        $this->assertFalse(Embeddings::configurado());
        $this->assertNull(Embeddings::de('hola'));

        Http::assertNothingSent();
    }
}
