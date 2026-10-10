<?php

namespace Tests\Feature;

use App\Jobs\SyncContactsFromIntegra;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La sincronización con Integra cruza por el usuario de WhatsApp
 * (contactos.username) además del teléfono: antes descartaba a los clientes
 * sin celular y nunca leía el usuario.
 */
class SyncContactsFromIntegraUsernameTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true])->id;
    }

    private function upsert(array $row): bool
    {
        $job = new SyncContactsFromIntegra(0);
        $metodo = new \ReflectionMethod($job, 'upsertContact');

        return $metodo->invoke($job, $this->companyId, $row);
    }

    private function fila(array $contacto, array $extra = []): array
    {
        return array_merge([
            'id' => 1245,
            'identificacion' => '1073722061',
            'nombre_completo' => 'KATHERINE PEREZ',
            'contacto' => array_merge(['celular' => null, 'username' => null], $contacto),
        ], $extra);
    }

    public function test_un_cliente_sin_celular_pero_con_usuario_se_crea(): void
    {
        $this->assertTrue($this->upsert($this->fila(['username' => '@Katherine.Perez'])));

        $contact = Contact::sole();
        $this->assertSame('katherine.perez', $contact->username);
        $this->assertNull($contact->phone_number);
        $this->assertSame('1073722061', $contact->identificacion);
    }

    public function test_sin_celular_ni_usuario_se_salta(): void
    {
        $this->assertFalse($this->upsert($this->fila([])));
        $this->assertSame(0, Contact::count());
    }

    public function test_hace_match_por_usuario_y_completa_la_identificacion(): void
    {
        $existente = Contact::create([
            'company_id' => $this->companyId,
            'name'       => 'Kathe',
            'username'   => 'katherine.perez',
        ]);

        $this->assertFalse($this->upsert($this->fila(['username' => 'katherine.perez', 'celular' => '3007852081'])));

        $existente->refresh();
        $this->assertSame(1, Contact::count());
        $this->assertSame('Kathe', $existente->name, 'El nombre del CRM no se pisa.');
        $this->assertSame('1073722061', $existente->identificacion);
        $this->assertSame(1245, (int) $existente->metadata['integra_contactos']['external_id']);
    }

    public function test_el_match_por_telefono_completa_el_usuario(): void
    {
        $existente = Contact::create([
            'company_id'   => $this->companyId,
            'name'         => 'Kathe',
            'phone_number' => '573007852081',
        ]);

        $this->upsert($this->fila(['username' => 'katherine.perez', 'celular' => '3007852081']));

        $this->assertSame('katherine.perez', $existente->refresh()->username);
    }

    public function test_un_usuario_que_ya_lleva_otra_ficha_no_revienta(): void
    {
        Contact::create(['company_id' => $this->companyId, 'name' => 'Otra', 'username' => 'katherine.perez', 'phone_number' => '3110000000']);
        $porTelefono = Contact::create(['company_id' => $this->companyId, 'name' => 'Kathe', 'phone_number' => '3007852081']);

        // El usuario casa primero con «Otra»; el teléfono no llega a usarse.
        $this->assertFalse($this->upsert($this->fila(['username' => 'katherine.perez', 'celular' => '3007852081'])));

        $this->assertNull($porTelefono->refresh()->username);
        $this->assertSame(2, Contact::count());
    }
}
