<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Por qué línea envía el ERP se elige aquí, en el CRM.
 *
 * Antes no se elegía: Integra 2.0 hacía `Instance::where(...)->first()` y se
 * quedaba con la que devolviera la base de datos. Con una sola línea da igual,
 * pero Transinternet tiene dos y el ERP estaba usando **la que no era**: envía
 * por +57 318 666 5858 cuando la de WhatsApp Business es +57 311 577 5385
 * (9-sep-2026).
 *
 * Se elige en el CRM porque es quien habla con WhatsApp, y el ERP la pregunta
 * antes de enviar. Preguntarla no se desincroniza; copiarla a los dos lados sí.
 */
class LineaDelErpTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_elegir_devuelve_la_primera_activa(): void
    {
        [$empresa, $primera] = $this->empresaConDosLineas();

        $this->assertSame($primera->id, $empresa->instanciaDelErp()->id);
        $this->assertFalse($empresa->tieneLineaDelErpElegida());
    }

    public function test_al_elegir_una_devuelve_esa(): void
    {
        [$empresa, , $segunda] = $this->empresaConDosLineas();

        $empresa->elegirInstanciaDelErp($segunda->id);

        $this->assertSame($segunda->id, $empresa->fresh()->instanciaDelErp()->id);
        $this->assertTrue($empresa->fresh()->tieneLineaDelErpElegida());
    }

    /**
     * Si la línea elegida se desactiva, el ERP no puede quedarse sin enviar:
     * vuelve a la primera activa, que es lo que hacía antes de elegir nada.
     */
    public function test_si_la_elegida_se_desactiva_vuelve_a_la_primera(): void
    {
        [$empresa, $primera, $segunda] = $this->empresaConDosLineas();

        $empresa->elegirInstanciaDelErp($segunda->id);
        $segunda->update(['active' => false]);

        $this->assertSame($primera->id, $empresa->fresh()->instanciaDelErp()->id);
    }

    /** El API le dice al ERP cuál usar. */
    public function test_el_api_contesta_la_linea_elegida(): void
    {
        [$empresa, $primera, $segunda] = $this->empresaConDosLineas();
        $empresa->elegirInstanciaDelErp($segunda->id);

        // El ERP pregunta con el token de la línea que venía usando...
        $this->withHeader('X-Instance-Token', $primera->phone_number_id)
            ->getJson('/api/v1/config')
            ->assertOk()
            // ...y se le contesta con otra. Ése es justo el punto.
            ->assertJsonPath('linea_envios.phone_number_id', $segunda->phone_number_id)
            ->assertJsonPath('linea_envios.elegida', true);
    }

    /** Sin token no se contesta nada. */
    public function test_el_api_exige_token(): void
    {
        $this->getJson('/api/v1/config')->assertStatus(401);
    }

    /** Una línea de otra empresa no se puede elegir. */
    public function test_no_se_puede_elegir_la_linea_de_otra_empresa(): void
    {
        [$empresa, $primera] = $this->empresaConDosLineas();
        [$vecina, $ajena] = $this->empresaConDosLineas('vecina');

        $usuario = \App\Models\User::create([
            'company_id' => $empresa->id, 'name' => 'Admin',
            'email' => Str::random(6).'@test.local', 'password' => bcrypt('x'),
            'role' => 'admin', 'active' => true,
        ]);

        $this->actingAs($usuario)
            ->post('/integrations/linea-erp', ['instance_id' => $ajena->id])
            ->assertSessionHasErrors('instance_id');

        $this->assertSame($primera->id, $empresa->fresh()->instanciaDelErp()->id);
    }

    /**
     * No se puede mudar el ERP a una línea sin el catálogo de la que envía hoy.
     *
     * Los catálogos de plantillas viven en Meta y son por WABA. El 10-sep-2026
     * cambiar de línea sin comprobarlo dejó a Transinternet una noche entera
     * con Meta devolviendo «(#100) Invalid parameter» en cada factura, y nadie
     * se enteró hasta que el cliente lo dijo por WhatsApp a las 6 de la mañana.
     */
    public function test_no_deja_mudarse_a_una_linea_sin_las_plantillas(): void
    {
        [$empresa, $primera, $segunda] = $this->empresaConDosLineas();
        $usuario = $this->adminDe($empresa);

        $this->fingirCatalogos(
            enLaDeHoy: [['name' => 'facturacion', 'language' => 'es_CO', 'status' => 'APPROVED']],
            enLaNueva: [['name' => 'aviso', 'language' => 'es', 'status' => 'APPROVED']],
        );

        $this->actingAs($usuario)
            ->post('/integrations/linea-erp', ['instance_id' => $segunda->id])
            ->assertSessionHasErrors('instance_id');

        $this->assertSame($primera->id, $empresa->fresh()->instanciaDelErp()->id);
    }

    /** Con el catálogo completo sí deja. */
    public function test_con_las_plantillas_al_dia_deja_mudarse(): void
    {
        [$empresa, , $segunda] = $this->empresaConDosLineas();
        $usuario = $this->adminDe($empresa);

        $this->fingirCatalogos(
            enLaDeHoy: [['name' => 'facturacion', 'language' => 'es_CO', 'status' => 'APPROVED']],
            enLaNueva: [['name' => 'facturacion', 'language' => 'es_CO', 'status' => 'APPROVED']],
        );

        $this->actingAs($usuario)
            ->post('/integrations/linea-erp', ['instance_id' => $segunda->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($segunda->id, $empresa->fresh()->instanciaDelErp()->id);
    }

    /**
     * Si Meta no contesta no se bloquea: no saber no es lo mismo que saber que
     * falta, y dejar a alguien sin poder cambiar de línea porque Meta tuvo un
     * mal minuto sería peor que el riesgo que se evita.
     */
    public function test_si_meta_no_contesta_no_bloquea_el_cambio(): void
    {
        [$empresa, , $segunda] = $this->empresaConDosLineas();
        $usuario = $this->adminDe($empresa);

        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 500)]);

        $this->actingAs($usuario)
            ->post('/integrations/linea-erp', ['instance_id' => $segunda->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($segunda->id, $empresa->fresh()->instanciaDelErp()->id);
    }

    /**
     * @param  array<int, array<string, string>>  $enLaDeHoy
     * @param  array<int, array<string, string>>  $enLaNueva
     */
    private function fingirCatalogos(array $enLaDeHoy, array $enLaNueva): void
    {
        \Illuminate\Support\Facades\Http::fake([
            '*waba-hoy/message_templates*' => \Illuminate\Support\Facades\Http::response(['data' => $enLaDeHoy], 200),
            '*waba-nueva/message_templates*' => \Illuminate\Support\Facades\Http::response(['data' => $enLaNueva], 200),
            '*' => \Illuminate\Support\Facades\Http::response(['data' => []], 200),
        ]);
    }

    private function adminDe(Company $empresa): \App\Models\User
    {
        return \App\Models\User::create([
            'company_id' => $empresa->id, 'name' => 'Admin',
            'email' => Str::random(8).'@test.local', 'password' => bcrypt('x'),
            'role' => 'admin', 'active' => true,
        ]);
    }

    /**
     * @return array{0: Company, 1: Instance, 2: Instance}
     */
    private function empresaConDosLineas(string $sufijo = 'transinternet'): array
    {
        $empresa = Company::create([
            'name' => 'Transinternet '.$sufijo,
            'slug' => $sufijo.'-'.Str::random(5),
            'active' => true,
        ]);

        $linea = fn (string $nombre, string $pnid, string $numero) => Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => $nombre,
            'phone_number_id' => $pnid,
            'display_phone_number' => $numero,
            'waba_id' => $nombre === 'Instancia Principal' ? 'waba-hoy' : 'waba-nueva',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        $primera = $linea('Instancia Principal', 'pnid-a-'.Str::random(5), '+573186665858');
        $segunda = $linea('Transintermet', 'pnid-b-'.Str::random(5), '+573115775385');

        return [$empresa, $primera, $segunda];
    }
}
