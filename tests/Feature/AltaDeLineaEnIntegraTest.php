<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Services\RegistrarLineaEnIntegra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Conectar una línea aquí la da de alta también en Integra.
 *
 * Hasta el 10-sep-2026 había que registrarla **a mano en los dos sistemas**, y
 * eso produjo el enredo de Transinternet: dos líneas activas en el CRM, una que
 * enviaba facturas y otra que no, y nadie sabía cuál era cuál porque en Integra
 * sólo existía una de las dos.
 *
 * El alta se empuja porque ocurre una vez. La configuración —qué línea usar, qué
 * plantilla— se sigue preguntando, porque eso cambia.
 */
class AltaDeLineaEnIntegraTest extends TestCase
{
    use RefreshDatabase;

    /** Sin el complemento conectado no se empuja nada, y no es un error. */
    public function test_sin_integra_conectado_no_hace_nada(): void
    {
        Http::fake();
        $instancia = $this->lineaDe($this->empresa());

        $resultado = app(RegistrarLineaEnIntegra::class)($instancia);

        $this->assertFalse($resultado['empujada']);
        $this->assertSame('sin_conexion', $resultado['motivo']);
        $this->assertArrayNotHasKey('aviso', $resultado, 'No tener Integra no es un problema que reportar');
        Http::assertNothingSent();
    }

    public function test_con_integra_conectado_registra_la_linea(): void
    {
        Http::fake(['*/whatsapp/instancias' => Http::response([
            'success' => true,
            'data' => ['id' => 5, 'phone_number_id' => 'pnid-1', 'creada' => true],
        ], 201)]);

        $empresa = $this->empresa();
        $this->conectarIntegra($empresa);
        $instancia = $this->lineaDe($empresa);

        $resultado = app(RegistrarLineaEnIntegra::class)($instancia);

        $this->assertTrue($resultado['empujada']);

        Http::assertSent(function ($peticion) use ($instancia) {
            $cuerpo = $peticion->data();

            return str_contains($peticion->url(), '/api/v1/whatsapp/instancias')
                && $cuerpo['phone_number_id'] === $instancia->phone_number_id
                && $cuerpo['waba_id'] === $instancia->waba_id;
        });
    }

    /**
     * Un token emitido antes de que existiera este permiso da 403. No se
     * arregla reintentando: hay que volver a conectar, y el aviso lo dice con
     * esas palabras en vez de con el código.
     */
    public function test_un_token_viejo_avisa_de_que_hay_que_reconectar(): void
    {
        Http::fake(['*/whatsapp/instancias' => Http::response([
            'success' => false,
            'message' => 'El token no tiene este permiso.',
        ], 403)]);

        $empresa = $this->empresa();
        $this->conectarIntegra($empresa);

        $resultado = app(RegistrarLineaEnIntegra::class)($this->lineaDe($empresa));

        $this->assertFalse($resultado['empujada']);
        $this->assertStringContainsString('Vuelve a conectarla', $resultado['aviso']);
    }

    /**
     * Y si Integra se cae, la línea del CRM se queda igual: ya está conectada
     * con Meta y funcionando. Un fallo del push no puede tumbar el alta.
     */
    public function test_si_integra_falla_la_linea_del_crm_sigue_en_pie(): void
    {
        Http::fake(['*/whatsapp/instancias' => Http::response('', 500)]);

        $empresa = $this->empresa();
        $this->conectarIntegra($empresa);
        $instancia = $this->lineaDe($empresa);

        $resultado = app(RegistrarLineaEnIntegra::class)($instancia);

        $this->assertFalse($resultado['empujada']);
        $this->assertNotNull($resultado['aviso']);
        $this->assertDatabaseHas('instances', ['id' => $instancia->id]);
    }

    /** Que la línea ya estuviera en Integra cuenta como éxito, no como error. */
    public function test_una_linea_que_ya_estaba_no_es_un_fallo(): void
    {
        Http::fake(['*/whatsapp/instancias' => Http::response([
            'success' => true,
            'data' => ['id' => 5, 'phone_number_id' => 'pnid-1', 'creada' => false],
        ], 200)]);

        $empresa = $this->empresa();
        $this->conectarIntegra($empresa);

        $this->assertTrue(app(RegistrarLineaEnIntegra::class)($this->lineaDe($empresa))['empujada']);
    }

    private function empresa(): Company
    {
        return Company::create([
            'name' => 'ISP',
            'slug' => 'isp-'.Str::random(6),
            'active' => true,
        ]);
    }

    private function conectarIntegra(Company $empresa): void
    {
        CompanyIntegration::create([
            'company_id' => $empresa->id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'base_url' => 'https://miempresa.integra.test',
            'access_token' => 'itg_'.Str::random(20),
            'status' => 'connected',
        ]);
    }

    private function lineaDe(Company $empresa): Instance
    {
        return Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => 'pnid-1',
            'waba_id' => 'waba-1',
            'display_phone_number' => '+57 300 000 0000',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);
    }
}
