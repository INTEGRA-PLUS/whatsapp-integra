<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ConversationDeletionRequest;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El borrado de un chat depende del permiso `chat.delete`: quien lo tiene borra
 * de verdad, quien no lo tiene deja una petición que otro aprueba.
 */
class ConversationDeletionRequestTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Instance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['name' => 'Acme', 'slug' => 'acme', 'active' => true]);

        $this->instance = Instance::create([
            'company_id'      => $this->company->id,
            'uuid'            => (string) Str::uuid(),
            'name'            => 'Principal',
            'phone_number_id' => '111',
            'waba_id'         => '222',
            'type'            => 'meta',
            'active'          => true,
            'access_token'    => 'token-meta',
        ]);

        Permission::firstOrCreate(['name' => 'chat.delete', 'guard_name' => 'web']);

        // User::booted() le da el rol admin con TODOS los permisos al primer
        // usuario de una empresa. Se crea uno de relleno para que los usuarios
        // de las pruebas tengan exactamente los permisos que se les asignan.
        User::create([
            'name'       => 'Dueño',
            'email'      => Str::random(8) . '@test.local',
            'password'   => bcrypt('secret'),
            'company_id' => $this->company->id,
            'active'     => true,
        ]);
    }

    private function conversation(): WhatsAppConversation
    {
        return WhatsAppConversation::create([
            'instance_id'  => $this->instance->id,
            'wa_id'        => '573001112233',
            'phone_number' => '573001112233',
            'name'         => 'Cliente',
            'status'       => 'open',
        ]);
    }

    /** Usuario de la empresa con (o sin) el permiso de borrar chats. */
    private function user(bool $canDelete, string $name): User
    {
        $user = User::create([
            'name'       => $name,
            'email'      => Str::random(8) . '@test.local',
            'password'   => bcrypt('secret'),
            'company_id' => $this->company->id,
            'active'     => true,
        ]);

        setPermissionsTeamId($this->company->id);

        $role = Role::firstOrCreate([
            'name'       => $canDelete ? 'admin' : 'agente',
            'company_id' => $this->company->id,
            'guard_name' => 'web',
        ]);
        $role->syncPermissions($canDelete ? ['chat.delete'] : []);
        $user->assignRole($role);

        return $user;
    }

    public function test_con_permiso_el_chat_se_elimina_de_inmediato(): void
    {
        $admin = $this->user(true, 'Admin');
        $conversation = $this->conversation();

        $response = $this->actingAs($admin)
            ->deleteJson("/api/chat/conversations/{$conversation->id}");

        $response->assertOk()->assertJson(['success' => true, 'deleted' => true]);

        $this->assertNull(WhatsAppConversation::find($conversation->id), 'El chat debía borrarse.');
        $this->assertSame(0, ConversationDeletionRequest::count(), 'No debía crearse ninguna petición.');
    }

    public function test_sin_permiso_se_crea_una_peticion_y_el_chat_sigue(): void
    {
        $agente = $this->user(false, 'Agente');
        $conversation = $this->conversation();

        $response = $this->actingAs($agente)
            ->deleteJson("/api/chat/conversations/{$conversation->id}", ['reason' => 'Duplicado']);

        $response->assertOk()->assertJson(['success' => true, 'status' => 'pending']);

        $this->assertNotNull(WhatsAppConversation::find($conversation->id), 'El chat NO debía borrarse.');

        $request = ConversationDeletionRequest::first();
        $this->assertNotNull($request);
        $this->assertSame('pending', $request->status);
        $this->assertSame($agente->id, $request->requested_by);
        $this->assertSame('Duplicado', $request->reason);
    }

    public function test_pedirlo_dos_veces_no_duplica_la_peticion(): void
    {
        $agente = $this->user(false, 'Agente');
        $conversation = $this->conversation();

        $this->actingAs($agente)->deleteJson("/api/chat/conversations/{$conversation->id}");
        $this->actingAs($agente)->deleteJson("/api/chat/conversations/{$conversation->id}");

        $this->assertSame(1, ConversationDeletionRequest::count());
    }

    public function test_aprobar_elimina_el_chat_y_conserva_el_registro(): void
    {
        $agente = $this->user(false, 'Agente');
        $admin  = $this->user(true, 'Admin');
        $conversation = $this->conversation();

        $this->actingAs($agente)->deleteJson("/api/chat/conversations/{$conversation->id}");
        $request = ConversationDeletionRequest::first();

        $response = $this->actingAs($admin)
            ->postJson("/api/chat/deletion-requests/{$request->id}/resolve", ['action' => 'approve']);

        $response->assertOk()->assertJson(['success' => true, 'deleted' => true]);

        $this->assertNull(WhatsAppConversation::find($conversation->id), 'El chat debía borrarse al aprobar.');

        // El registro sobrevive al chat: es la constancia de quién autorizó.
        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertNull($request->conversation_id);
    }

    public function test_rechazar_deja_el_chat_intacto(): void
    {
        $agente = $this->user(false, 'Agente');
        $admin  = $this->user(true, 'Admin');
        $conversation = $this->conversation();

        $this->actingAs($agente)->deleteJson("/api/chat/conversations/{$conversation->id}");
        $request = ConversationDeletionRequest::first();

        $this->actingAs($admin)
            ->postJson("/api/chat/deletion-requests/{$request->id}/resolve", ['action' => 'reject'])
            ->assertOk()
            ->assertJson(['success' => true, 'deleted' => false]);

        $this->assertNotNull(WhatsAppConversation::find($conversation->id), 'El chat NO debía borrarse.');
        $this->assertSame('rejected', $request->refresh()->status);
    }

    public function test_un_agente_no_puede_aprobar_su_propia_peticion(): void
    {
        $agente = $this->user(false, 'Agente');
        $conversation = $this->conversation();

        $this->actingAs($agente)->deleteJson("/api/chat/conversations/{$conversation->id}");
        $request = ConversationDeletionRequest::first();

        $this->actingAs($agente)
            ->postJson("/api/chat/deletion-requests/{$request->id}/resolve", ['action' => 'approve'])
            ->assertForbidden();

        $this->assertNotNull(WhatsAppConversation::find($conversation->id), 'El chat NO debía borrarse.');
        $this->assertSame('pending', $request->refresh()->status);
    }
}
