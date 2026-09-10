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
            'waba_id' => 'waba-'.Str::random(5),
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        $primera = $linea('Instancia Principal', 'pnid-a-'.Str::random(5), '+573186665858');
        $segunda = $linea('Transintermet', 'pnid-b-'.Str::random(5), '+573115775385');

        return [$empresa, $primera, $segunda];
    }
}
