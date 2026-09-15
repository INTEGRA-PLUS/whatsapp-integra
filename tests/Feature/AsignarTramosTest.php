<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El comando que rellena el tramo de cada empresa.
 *
 * Lo que se protege: que guarde el TOPE del tramo y no el número de contactos
 * —confundirlos deja a la empresa «pasada de tramo» en cuanto entre un cliente
 * más— y que no le pise a nadie un tramo ya negociado.
 */
class AsignarTramosTest extends TestCase
{
    use RefreshDatabase;

    private function empresaCon(int $contactos, array $extra = []): Company
    {
        $company = Company::create(array_merge([
            'name' => 'Fibra '.uniqid(),
            'slug' => 'fibra-'.uniqid(),
            'active' => true,
        ], $extra));

        for ($i = 0; $i < $contactos; $i++) {
            Contact::create([
                'company_id' => $company->id,
                'phone_number' => '57'.$company->id.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'name' => 'C'.$i,
            ]);
        }

        return $company;
    }

    /** El tope del tramo, no los contactos: si no, se «pasa» con un cliente más. */
    public function test_guarda_el_tope_del_tramo_y_no_los_contactos(): void
    {
        $company = $this->empresaCon(7);

        $this->artisan('planes:asignar-tramos')->assertSuccessful();

        $this->assertSame(500, (int) $company->refresh()->contactos_contratados);
    }

    public function test_dry_no_guarda_nada(): void
    {
        $company = $this->empresaCon(3);

        $this->artisan('planes:asignar-tramos', ['--dry' => true])->assertSuccessful();

        $this->assertNull($company->refresh()->contactos_contratados);
    }

    /** Un tramo ya puesto puede ser lo negociado: no se toca sin --forzar. */
    public function test_no_pisa_un_tramo_ya_negociado(): void
    {
        $company = $this->empresaCon(4, ['contactos_contratados' => 15000]);

        $this->artisan('planes:asignar-tramos')->assertSuccessful();
        $this->assertSame(15000, (int) $company->refresh()->contactos_contratados);

        $this->artisan('planes:asignar-tramos', ['--forzar' => true])->assertSuccessful();
        $this->assertSame(500, (int) $company->refresh()->contactos_contratados);
    }

    public function test_se_puede_acotar_a_una_empresa(): void
    {
        $una = $this->empresaCon(2);
        $otra = $this->empresaCon(2);

        $this->artisan('planes:asignar-tramos', ['--company' => $una->id])->assertSuccessful();

        $this->assertSame(500, (int) $una->refresh()->contactos_contratados);
        $this->assertNull($otra->refresh()->contactos_contratados);
    }

    /** Sin contactos también entra en el primer tramo; no se queda en null. */
    public function test_una_empresa_sin_contactos_cae_en_el_primer_tramo(): void
    {
        $company = $this->empresaCon(0);

        $this->artisan('planes:asignar-tramos')->assertSuccessful();

        $this->assertSame(500, (int) $company->refresh()->contactos_contratados);
    }
}
