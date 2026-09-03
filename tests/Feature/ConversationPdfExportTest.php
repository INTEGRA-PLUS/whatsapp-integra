<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationPdfExportTest extends TestCase
{
    use RefreshDatabase;

    private function agentFor(Company $company, string $sufijo = ''): User
    {
        return User::create([
            'company_id' => $company->id,
            'name'       => 'Ana Gómez' . $sufijo,
            'email'      => 'agente-' . $company->slug . $sufijo . '@example.test',
            'password'   => bcrypt('secret'),
            'role'       => 'agent',
            'active'     => true,
        ]);
    }

    private function scenario(string $slug = 'acme'): array
    {
        $company = Company::create(['name' => 'Acme', 'slug' => $slug, 'active' => true]);

        $user = $this->agentFor($company);

        $instance = Instance::create([
            'company_id'      => $company->id,
            'uuid'            => (string) \Illuminate\Support\Str::uuid(),
            'name'            => 'Principal',
            'phone_number_id' => '111',
            'waba_id'         => '222',
            'type'            => 'meta',
            'active'          => true,
            'access_token'    => 'token-meta',
        ]);

        $conversation = WhatsAppConversation::create([
            'instance_id'  => $instance->id,
            'wa_id'        => '573001112233',
            'phone_number' => '573001112233',
            'name'         => 'Juliana Pérez',
            'status'       => 'open',
            'assigned_to'  => $user->id,
        ]);

        return [$company, $user, $instance, $conversation];
    }

    public function test_la_vista_imprimible_trae_el_hilo_completo(): void
    {
        [, $user, , $conversation] = $this->scenario();

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type'            => 'text',
            'direction'       => 'inbound',
            'content'         => 'Buenas, ¿ya salió mi factura? 🙂',
        ]);

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type'            => 'text',
            'direction'       => 'outbound',
            'status'          => 'sent',
            'sent_by'         => $user->id,
            'content'         => 'Sí, se la envío ahora mismo.',
        ]);

        $response = $this->actingAs($user)
            ->get("/api/chat/conversations/{$conversation->id}/export");

        $response->assertOk();
        $response->assertSee('Juliana Pérez');
        $response->assertSee('573001112233');
        $response->assertSee('Buenas, ¿ya salió mi factura? 🙂', false);
        $response->assertSee('Sí, se la envío ahora mismo.', false);
        $response->assertSee('Ana Gómez', false);
        // El emoji tiene que llegar tal cual: es el motivo de imprimir en el
        // navegador en vez de generar el PDF en el servidor.
        $this->assertStringContainsString('🙂', $response->getContent());
    }

    public function test_las_notas_internas_quedan_marcadas_como_tales(): void
    {
        [, $user, , $conversation] = $this->scenario();

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type'            => 'text',
            'direction'       => 'outbound',
            'is_internal'     => true,
            'sent_by'         => $user->id,
            'content'         => 'El cliente ya pagó, confirmado con cartera.',
        ]);

        $response = $this->actingAs($user)
            ->get("/api/chat/conversations/{$conversation->id}/export");

        $response->assertOk();
        $response->assertSee('Nota interna');
        $response->assertSee('El cliente ya pagó, confirmado con cartera.', false);
    }

    public function test_los_adjuntos_dejan_constancia_del_archivo(): void
    {
        [, $user, , $conversation] = $this->scenario();

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type'            => 'document',
            'direction'       => 'inbound',
            'filename'        => 'Factura_TP62389.pdf',
        ]);

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'type'            => 'audio',
            'direction'       => 'inbound',
            'filename'        => 'nota_de_voz.ogg',
        ]);

        $response = $this->actingAs($user)
            ->get("/api/chat/conversations/{$conversation->id}/export");

        $response->assertOk();
        $response->assertSee('Documento — Factura_TP62389.pdf', false);
        $response->assertSee('Nota de voz / audio — nota_de_voz.ogg', false);
    }

    public function test_no_se_puede_exportar_el_hilo_de_otra_empresa(): void
    {
        [, , , $conversation] = $this->scenario('acme');
        [$otra] = $this->scenario('otra');

        $intruso = $this->agentFor($otra, '-intruso');

        $this->actingAs($intruso)
            ->get("/api/chat/conversations/{$conversation->id}/export")
            ->assertForbidden();
    }

    public function test_un_hilo_sin_mensajes_no_revienta(): void
    {
        [, $user, , $conversation] = $this->scenario();

        $response = $this->actingAs($user)
            ->get("/api/chat/conversations/{$conversation->id}/export");

        $response->assertOk();
        $response->assertSee('Esta conversación todavía no tiene mensajes.', false);
    }
}
