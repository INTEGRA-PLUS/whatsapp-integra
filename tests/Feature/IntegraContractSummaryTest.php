<?php

namespace Tests\Feature;

use App\Services\IntegraClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El resumen del contrato y la solicitud de prórroga.
 *
 * Lo que se protege aquí es sobre todo la titularidad. Los números de contrato
 * de Integra son secuenciales: sin comprobar quién pregunta, un cliente que
 * escriba el número siguiente al suyo recibe por WhatsApp la factura, la
 * dirección y la clave WiFi de su vecino. Por eso `identificacion` no es
 * opcional en la firma del método y por eso el 404 tiene que seguir llegando
 * como null indistinguible: si el bot pudiera diferenciar "no existe" de "no
 * eres el titular", ya tendría un oráculo para descubrir qué contratos existen.
 */
class IntegraContractSummaryTest extends TestCase
{
    private const BASE = 'https://demo.integra.test';

    public function test_el_resumen_viaja_con_la_identificacion_del_titular(): void
    {
        Http::fake([
            '*/api/v1/contratos/*/resumen*' => Http::response([
                'success' => true,
                'data' => ['contrato' => ['nro' => '10432']],
            ]),
        ]);

        $resumen = $this->client()->contractSummary('10432', '1017924455');

        $this->assertSame('10432', $resumen['contrato']['nro']);

        Http::assertSent(function (Request $request) {
            $query = $this->queryOf($request);

            return str_contains($request->url(), '/api/v1/contratos/10432/resumen')
                && $query['identificacion'] === '1017924455';
        });
    }

    /**
     * Integra responde 404 tanto si el contrato no existe como si existe pero
     * es de otra persona, y aquí las dos se vuelven null. Distinguirlas sería
     * exactamente la fuga que el 404 evita.
     */
    public function test_un_contrato_ajeno_o_inexistente_devuelve_null(): void
    {
        Http::fake([
            '*/api/v1/contratos/*/resumen*' => Http::response([
                'success' => false,
                'message' => 'Contrato no encontrado.',
            ], 404),
        ]);

        $this->assertNull($this->client()->contractSummary('10433', '1017924455'));
    }

    /** El detalle de consumo lo acota Integra a 90 días; pedir más es un 422 evitable. */
    public function test_los_dias_de_consumo_se_ajustan_al_rango_que_acepta_integra(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);

        $client = $this->client();
        $client->contractSummary('10432', '1017924455', 400);
        $client->contractSummary('10432', '1017924455', 0);

        $enviados = [];
        Http::assertSent(function (Request $request) use (&$enviados) {
            $enviados[] = $this->queryOf($request)['dias_consumo'];

            return true;
        });

        $this->assertSame(['90', '1'], $enviados);
    }

    /**
     * `contratos.leer` es un scope aparte, y hoy no lo tiene ningún token en
     * producción. Tragarse el 403 devolviendo null dejaría al bot diciéndole al
     * cliente que su contrato no existe, cuando el problema es del token.
     */
    public function test_sin_el_scope_de_contratos_el_error_no_se_confunde_con_un_contrato_ajeno(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'message' => 'El token no tiene permiso para esta operación.',
            ], 403),
        ]);

        try {
            $this->client()->contractSummary('10432', '1017924455');
            $this->fail('Se esperaba que el 403 se propagara.');
        } catch (\RuntimeException $e) {
            $this->assertSame(403, $e->getCode());
        }
    }

    public function test_la_prorroga_se_registra_como_solicitud_y_devuelve_su_estado(): void
    {
        Http::fake([
            '*/api/v1/contratos/*/prorroga' => Http::response([
                'success' => true,
                'data' => ['solicitud' => [
                    'id' => 77,
                    'tipo' => 'promesa',
                    'estado' => 'pendiente',
                    'fecha_propuesta' => '2026-09-04',
                ]],
            ], 201),
        ]);

        $solicitud = $this->client()->requestPaymentExtension(
            '10432',
            9911,
            '2026-09-04',
            '1017924455',
            'Me pagan el viernes'
        );

        $this->assertSame('pendiente', $solicitud['estado']);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request['factura_id'] === 9911
                && $request['fecha'] === '2026-09-04'
                && $request['identificacion'] === '1017924455'
                && $request['comentario'] === 'Me pagan el viernes';
        });
    }

    /**
     * Los topes (días de plazo, cupo anual, una solicitud a la vez) los decide
     * Integra y llegan redactados en el 422. Ese texto es justamente lo que hay
     * que contarle al cliente para que entienda por qué no y deje de insistir.
     */
    public function test_el_motivo_del_rechazo_llega_intacto_para_contarselo_al_cliente(): void
    {
        Http::fake([
            '*' => Http::response([
                'success' => false,
                'message' => 'Ya usaste las 2 promesas de pago de este año.',
            ], 422),
        ]);

        try {
            $this->client()->requestPaymentExtension('10432', 9911, '2026-09-04', '1017924455');
            $this->fail('Se esperaba el 422 de negocio.');
        } catch (\RuntimeException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertStringContainsString('2 promesas de pago', $e->getMessage());
        }
    }

    /**
     * A diferencia de la consulta, aquí un 404 no se puede volver null: quien
     * pide la prórroga necesita saber que no quedó registrada.
     */
    public function test_pedir_prorroga_sobre_un_contrato_ajeno_falla_en_vez_de_callar(): void
    {
        Http::fake([
            '*' => Http::response(['success' => false, 'message' => 'Contrato no encontrado.'], 404),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->client()->requestPaymentExtension('10433', 9911, '2026-09-04', '1017924455');
    }

    private function client(): IntegraClient
    {
        return new IntegraClient(self::BASE, 'itg_pruebas');
    }

    /** @return array<string, string> */
    private function queryOf(Request $request): array
    {
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

        return $query;
    }
}
