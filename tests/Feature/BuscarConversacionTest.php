<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buscar por lo que se ve en pantalla.
 *
 * El `name` de una conversación es el nombre de perfil de WhatsApp —"isnardo
 * paredes31"— mientras que la cabecera del chat enseña el del contacto de la
 * agenda —"ISNARDO SALAZAR RAMIREZ"—. En Megastore, 1.094 de 2.140
 * conversaciones tienen el contacto con otro nombre: en todas ellas el agente
 * leía un nombre, lo buscaba y no salía nada.
 */
class BuscarConversacionTest extends TestCase
{
    use RefreshDatabase;

    private function conversacion(array $datos = [], array $contacto = null): WhatsAppConversation
    {
        $company = Company::firstOrCreate(
            ['slug' => 'acme'],
            ['name' => 'Acme', 'active' => true],
        );

        $instancia = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => (string) random_int(100000, 999999),
            'waba_id' => (string) random_int(100000, 999999),
            'type' => 'meta',
            'active' => true,
        ]);

        $id = null;
        if ($contacto) {
            $id = Contact::create(array_merge(['company_id' => $company->id], $contacto))->id;
        }

        return WhatsAppConversation::create(array_merge([
            'instance_id' => $instancia->id,
            'wa_id' => (string) random_int(1000000, 9999999),
            'phone_number' => '+57 302 335 0723',
            'name' => 'isnardo paredes31',
            'last_message' => 'Gracias',
            'status' => 'closed',
            'contact_id' => $id,
        ], $datos));
    }

    public function test_encuentra_por_el_nombre_del_contacto_aunque_la_conversacion_se_llame_distinto(): void
    {
        $k = $this->conversacion([], ['name' => 'ISNARDO SALAZAR RAMIREZ']);

        $hallada = WhatsAppConversation::search('ISNARDO SALAZAR')->pluck('id');

        $this->assertTrue($hallada->contains($k->id), 'No la encontró por el nombre del contacto');
    }

    public function test_sigue_encontrando_por_el_nombre_de_la_conversacion(): void
    {
        $k = $this->conversacion();

        $this->assertTrue(
            WhatsAppConversation::search('paredes')->pluck('id')->contains($k->id),
        );
    }

    /** El teléfono se teclea sin los espacios con los que está guardado. */
    public function test_encuentra_por_el_telefono_aunque_se_escriba_sin_separadores(): void
    {
        $k = $this->conversacion();

        $this->assertTrue(
            WhatsAppConversation::search('3023350723')->pluck('id')->contains($k->id),
            'No la encontró tecleando el número seguido',
        );
    }

    public function test_no_devuelve_lo_que_no_encaja(): void
    {
        $this->conversacion([], ['name' => 'ISNARDO SALAZAR RAMIREZ']);

        $this->assertCount(0, WhatsAppConversation::search('Buenaventura')->get());
    }

    /** Una búsqueda vacía no filtra nada, en vez de no devolver nada. */
    public function test_una_busqueda_vacia_no_esconde_las_conversaciones(): void
    {
        $this->conversacion();

        $this->assertCount(1, WhatsAppConversation::search('   ')->get());
    }
}
