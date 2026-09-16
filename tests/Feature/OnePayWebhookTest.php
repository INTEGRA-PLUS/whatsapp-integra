<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SuscripcionCobro;
use App\Services\OnePayClient;
use App\Support\Suscripcion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El aviso de pago de OnePay.
 *
 * Escrito contra el webhook que Integra 2.0 lleva meses recibiendo de esta misma
 * pasarela, así que los casos raros de aquí no son hipótesis: son cosas que allí
 * pasaron y están documentadas en sus comentarios.
 *
 * Las tres que cuestan dinero:
 *
 * 1. **El importe viene en unidades distintas según el evento.** `invoice.paid`
 *    tal cual; `payment.approved` en centavos.
 * 2. **La pasarela puede reemplazar la solicitud de pago**, y entonces pisa el
 *    `provider_id`, pierde el `metadata` y estrena `id`. Sólo sobrevive el
 *    `external_id`.
 * 3. **El webhook se reintenta.** Sin idempotencia, el reintento regala un
 *    periodo entero.
 */
class OnePayWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** El caso normal: avisa, se paga y la suscripción se alarga. */
    public function test_invoice_paid_paga_el_cobro_y_alarga(): void
    {
        [$company, $cobro] = $this->cobro();

        $this->postJson('/pagos/onepay', [
            'event' => ['type' => 'invoice.paid'],
            'invoice' => [
                'id' => 'inv_1',
                'external_id' => OnePayClient::referencia($cobro),
                'payment' => ['id' => 'pay_1', 'amount' => $cobro->importe_usd * config('planes.tasa_cop')],
            ],
        ])->assertOk();

        $this->assertSame('pagado', $cobro->refresh()->estado);
        $this->assertSame(
            $cobro->periodo_hasta->toDateString(),
            $company->refresh()->suscripcion_hasta->toDateString()
        );
    }

    /**
     * `payment.approved` llega en centavos y hay que dividir entre cien.
     *
     * Si no, un cobro de 116.000 pesos se da por pagado con 1.160 — y el aviso
     * de descuadre que debería saltar no salta, porque el número «cuadra» con lo
     * que el código cree que es.
     */
    public function test_payment_approved_viene_en_centavos(): void
    {
        [, $cobro] = $this->cobro();
        $esperado = $cobro->importe_usd * config('planes.tasa_cop');

        $this->postJson('/pagos/onepay', [
            'event' => ['type' => 'payment.approved'],
            'payment' => [
                'id' => 'pay_2',
                'external_id' => OnePayClient::referencia($cobro),
                // En centavos, que es como llega este evento.
                'amount' => $esperado * 100,
            ],
        ])->assertOk();

        $this->assertSame('pagado', $cobro->refresh()->estado);
    }

    /**
     * Encuentra el cobro por `external_id` aunque la pasarela haya pisado el
     * `provider_id` y perdido el `metadata`.
     *
     * Es el incidente de Megastore del 1-sep-2026 con la factura EST7496: los
     * tres caminos obvios fallaron a la vez.
     */
    public function test_encuentra_el_cobro_aunque_la_pasarela_pise_el_provider_id(): void
    {
        [, $cobro] = $this->cobro();

        $this->postJson('/pagos/onepay', [
            'event' => ['type' => 'invoice.paid'],
            'invoice' => [
                'id' => 'inv_nuevo_que_no_conocemos',
                // Lo que hace la pasarela al reemplazar la solicitud.
                'provider_id' => '$int.-2naRF5t10RY2_OMI',
                'external_id' => OnePayClient::referencia($cobro),
                // Sin metadata, también perdido.
                'payment' => ['id' => 'pay_3', 'amount' => $cobro->importe_usd * config('planes.tasa_cop')],
            ],
        ])->assertOk();

        $this->assertSame('pagado', $cobro->refresh()->estado);
    }

    /** Y por el metadata cuando no viene ninguna referencia. */
    public function test_encuentra_el_cobro_por_el_metadata(): void
    {
        [, $cobro] = $this->cobro();

        $this->postJson('/pagos/onepay', [
            'event' => ['type' => 'invoice.paid'],
            'invoice' => [
                'id' => 'inv_4',
                'metadata' => ['cobro_id' => $cobro->id],
                'payment' => ['id' => 'pay_4', 'amount' => $cobro->importe_usd * config('planes.tasa_cop')],
            ],
        ])->assertOk();

        $this->assertSame('pagado', $cobro->refresh()->estado);
    }

    /**
     * El reintento del webhook no alarga el periodo dos veces.
     *
     * Todas las pasarelas reintentan. Sin esto, cada reintento es un mes gratis.
     */
    public function test_el_reintento_no_alarga_dos_veces(): void
    {
        [$company, $cobro] = $this->cobro();

        $payload = [
            'event' => ['type' => 'invoice.paid'],
            'invoice' => [
                'id' => 'inv_5',
                'external_id' => OnePayClient::referencia($cobro),
                'payment' => ['id' => 'pay_5', 'amount' => $cobro->importe_usd * config('planes.tasa_cop')],
            ],
        ];

        $this->postJson('/pagos/onepay', $payload)->assertOk();
        $hasta = $company->refresh()->suscripcion_hasta->toDateString();

        $this->postJson('/pagos/onepay', $payload)->assertOk();

        $this->assertSame($hasta, $company->refresh()->suscripcion_hasta->toDateString());
    }

    /**
     * Un pago que no es nuestro se contesta 200 y se ignora.
     *
     * Si algún día la cuenta se comparte con Integra, aquí llegarán los pagos de
     * los abonados de los ISPs. Responder error haría que OnePay reintentara en
     * bucle un evento que nunca vamos a querer.
     */
    public function test_un_pago_ajeno_no_rompe_nada(): void
    {
        $this->postJson('/pagos/onepay', [
            'event' => ['type' => 'invoice.paid'],
            'invoice' => [
                'id' => 'inv_de_integra',
                'provider' => 'integra',
                'provider_id' => '0FV123',
                'payment' => ['id' => 'pay_x', 'amount' => 50000],
            ],
        ])->assertOk()->assertJson(['ajeno' => true]);

        $this->assertSame(0, SuscripcionCobro::where('estado', 'pagado')->count());
    }

    /** Un evento que no interesa se contesta 200 sin hacer nada. */
    public function test_un_evento_que_no_interesa_se_ignora(): void
    {
        [, $cobro] = $this->cobro();

        $this->postJson('/pagos/onepay', [
            'event' => ['type' => 'invoice.created'],
            'invoice' => ['id' => 'inv_6', 'external_id' => OnePayClient::referencia($cobro)],
        ])->assertOk();

        $this->assertSame('pendiente', $cobro->refresh()->estado);
    }

    /** La referencia va y vuelve entera, con letras y ceros de relleno. */
    public function test_la_referencia_sobrevive_al_relleno_de_ceros(): void
    {
        [, $cobro] = $this->cobro();

        $referencia = OnePayClient::referencia($cobro);

        $this->assertGreaterThanOrEqual(5, strlen($referencia), 'OnePay exige mínimo 5 caracteres.');
        $this->assertSame($cobro->id, OnePayClient::cobroDeLaReferencia($referencia));

        // Y una referencia de Integra no se confunde con una nuestra.
        $this->assertNull(OnePayClient::cobroDeLaReferencia('0FV123'));
    }

    /** @return array{0: Company, 1: SuscripcionCobro} */
    private function cobro(): array
    {
        $company = Company::create([
            'name' => 'Fibra '.uniqid(),
            'slug' => 'fibra-'.uniqid(),
            'active' => true,
            'plan' => 'basico',
            'cobro' => 'activo',
            'ciclo' => 'mensual',
        ]);

        return [$company, Suscripcion::emitir($company)];
    }
}
