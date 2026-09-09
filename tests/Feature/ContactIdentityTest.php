<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fichas de clientes que ocultan su número.
 *
 * Desde que WhatsApp deja esconder el teléfono tras un nombre de usuario, Meta
 * manda hilos sin número. La agenda se indexaba por teléfono y era obligatorio:
 * al agente le salía "no se puede crear la ficha" y ese cliente se quedaba sin
 * nombre, sin correo y sin notas para siempre. Ahora el usuario vale como
 * identificador, y el número se puede anotar a mano cuando el cliente lo da.
 */
class ContactIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Instance $instance;

    private const BSUID = 'CO.1402615141764490';

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet-' . Str::random(4), 'active' => true]);

        $this->instance = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);
    }

    public function test_se_crea_la_ficha_de_un_cliente_que_oculta_su_numero(): void
    {
        $conversation = $this->hiloSinTelefono('pntmldnd73_');

        $this->actingAs($this->agente())
            ->postJson("/api/chat/conversations/{$conversation->id}/attach-contact", [
                'name' => 'Juan Manuel',
                'last_name' => 'Developer',
                'username' => '@Pntmldnd73_',
            ])
            ->assertOk()
            ->assertJsonPath('contact.username', 'pntmldnd73_')
            ->assertJsonPath('contact.full_name', 'Juan Manuel Developer');

        $contact = Contact::sole();

        $this->assertNull($contact->phone_number, 'Meta no manda el número de quien lo oculta: la ficha se queda sin él.');
        $this->assertSame($contact->id, $conversation->fresh()->contact_id);
        $this->assertSame('Juan Manuel Developer', $conversation->fresh()->name, 'El chat se titula con el nombre completo de la ficha.');
    }

    public function test_dos_clientes_sin_numero_no_chocan_en_la_agenda(): void
    {
        $agente = $this->agente();

        foreach ([['pntmldnd73_', 'Juan'], ['katherine.pc', 'Katherine']] as [$username, $nombre]) {
            $conversation = $this->hiloSinTelefono($username);

            $this->actingAs($agente)
                ->postJson("/api/chat/conversations/{$conversation->id}/attach-contact", [
                    'name' => $nombre,
                    'username' => $username,
                ])
                ->assertOk();
        }

        // Con `phone_number` obligatorio y único, el segundo cliente chocaba
        // contra la ficha del primero: los dos acababan en el mismo contacto.
        $this->assertSame(2, Contact::count());
        $this->assertSame(['katherine.pc', 'pntmldnd73_'], Contact::orderBy('username')->pluck('username')->all());
    }

    public function test_el_agente_anota_el_numero_que_le_dio_el_cliente(): void
    {
        $conversation = $this->hiloSinTelefono('pntmldnd73_');
        $agente = $this->agente();

        $contact = Contact::create([
            'company_id' => $this->company->id,
            'name' => 'Juan Manuel',
            'username' => 'pntmldnd73_',
        ]);
        $conversation->update(['contact_id' => $contact->id]);

        $this->actingAs($agente)
            ->putJson("/api/contacts/{$contact->id}", [
                'name' => 'Juan Manuel',
                'username' => 'pntmldnd73_',
                'phone_number' => '+57 305 258 3254',
            ])
            ->assertOk()
            ->assertJsonPath('phone_number', '573052583254');

        $this->assertSame(
            $conversation->bsuid,
            $conversation->fresh()->recipientId(),
            'Anotar el número en la ficha no cambia por dónde se le responde: el hilo sigue siendo del BSUID.'
        );
    }

    public function test_una_ficha_no_puede_quedarse_sin_telefono_y_sin_usuario(): void
    {
        $contact = Contact::create([
            'company_id' => $this->company->id,
            'name' => 'Juan Manuel',
            'phone_number' => '573052583254',
        ]);

        $this->actingAs($this->agente())
            ->putJson("/api/contacts/{$contact->id}", [
                'name' => 'Juan Manuel',
                'phone_number' => '',
                'username' => '',
            ])
            ->assertStatus(422);

        $this->assertSame('573052583254', $contact->fresh()->phone_number);
    }

    public function test_el_mismo_usuario_no_entra_dos_veces_en_la_agenda(): void
    {
        Contact::create([
            'company_id' => $this->company->id,
            'name' => 'Juan Manuel',
            'username' => 'pntmldnd73_',
        ]);

        $this->actingAs($this->agente())
            ->postJson('/api/contacts', [
                'name' => 'Otro',
                'username' => '@PNTMLDND73_',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_la_agenda_exige_al_menos_una_forma_de_identificar_al_cliente(): void
    {
        $this->actingAs($this->agente())
            ->postJson('/api/contacts', ['name' => 'Sin nada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone_number');
    }

    private function hiloSinTelefono(string $username): WhatsAppConversation
    {
        return WhatsAppConversation::create([
            'instance_id' => $this->instance->id,
            'wa_id' => self::BSUID . '.' . $username,
            'bsuid' => self::BSUID . '.' . $username,
            // Así guarda el webhook a quien oculta su número: el hilo existe,
            // pero el campo del teléfono se queda vacío.
            'phone_number' => '',
            'name' => '@' . $username,
            'status' => 'open',
            'metadata' => ['username' => $username],
        ]);
    }

    private function agente(): User
    {
        $user = User::create([
            'name' => 'Agente',
            'email' => Str::random(8) . '@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $this->company->id,
            'active' => true,
        ]);

        setPermissionsTeamId($this->company->id);
        foreach (['contacts.view', 'contacts.create', 'contacts.update'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'admin', 'company_id' => $this->company->id, 'guard_name' => 'web']);
        $role->syncPermissions(['contacts.view', 'contacts.create', 'contacts.update']);
        $user->assignRole($role);

        return $user;
    }
}
