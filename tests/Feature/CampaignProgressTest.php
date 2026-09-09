<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El detalle de una campaña no puede devolver la lista entera.
 *
 * La pantalla se refresca sola cada cuatro segundos mientras envía. Con doce
 * mil socios, devolver todos los destinatarios en cada refresco son varios MB
 * por tick y por operador, durante las horas que dure la campaña. Los
 * contadores no dependen de esa lista: salen de un GROUP BY.
 */
class CampaignProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WhatsAppCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.x']]], 200));

        $company = Company::create([
            'name' => 'Cooperativa',
            'slug' => 'coop-'.Str::random(6),
            'active' => true,
        ]);

        $instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        $this->user = User::create([
            'name' => 'Admin',
            'email' => Str::random(8).'@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'active' => true,
        ]);

        setPermissionsTeamId($company->id);
        foreach (['campaigns.view', 'campaigns.update'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'admin', 'company_id' => $company->id, 'guard_name' => 'web']);
        $role->syncPermissions(['campaigns.view', 'campaigns.update']);
        $this->user->assignRole($role);

        $this->campaign = WhatsAppCampaign::create([
            'company_id' => $company->id,
            'instance_id' => $instance->id,
            'name' => 'Aviso de cuota',
            'message' => null,
            'message_type' => 'template',
            'template_name' => 'aviso',
            'template_language' => 'es',
            'template_components' => [['type' => 'BODY', 'text' => 'Hola {{1}}']],
            'variable_map' => ['body' => [['source' => 'field', 'field' => 'name']]],
            'status' => 'sending',
            'schedule_type' => 'manual',
            'total_recipients' => 600,
        ]);

        $filas = [];
        $ahora = now();
        for ($i = 0; $i < 600; $i++) {
            $filas[] = [
                'campaign_id' => $this->campaign->id,
                'phone_number' => '5730'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'name' => 'Socio '.$i,
                // 500 enviados y 100 fallidos: el operador viene a ver los fallos.
                'status' => $i < 500 ? 'sent' : 'failed',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }
        foreach (array_chunk($filas, 200) as $tanda) {
            WhatsAppCampaignRecipient::insert($tanda);
        }
    }

    public function test_el_progreso_no_devuelve_la_lista_entera(): void
    {
        $res = $this->actingAs($this->user)
            ->getJson(route('campaigns.progress', $this->campaign->id))
            ->assertOk();

        $this->assertCount(200, $res->json('recipients'), 'El progreso devolvió más de la ventana.');
        $this->assertSame(600, $res->json('recipientsMeta.total'));
        $this->assertTrue($res->json('recipientsMeta.truncated'));

        // Los contadores siguen siendo los de verdad: no salen de la lista.
        $this->assertSame(500, $res->json('campaign.counts.sent'));
        $this->assertSame(100, $res->json('campaign.counts.failed'));
    }

    public function test_el_filtro_lo_resuelve_el_servidor(): void
    {
        $res = $this->actingAs($this->user)
            ->getJson(route('campaigns.progress', $this->campaign->id).'?filter=failed')
            ->assertOk();

        $estados = collect($res->json('recipients'))->pluck('status')->unique()->values()->all();

        $this->assertSame(['failed'], $estados, 'El filtro del servidor devolvió estados que no se pidieron.');
        $this->assertSame(100, $res->json('recipientsMeta.total'));
        $this->assertFalse($res->json('recipientsMeta.truncated'), 'Cien fallos caben en la ventana de 200.');
    }

    public function test_un_filtro_inventado_no_vacia_la_pantalla(): void
    {
        $res = $this->actingAs($this->user)
            ->getJson(route('campaigns.progress', $this->campaign->id).'?filter=loquesea')
            ->assertOk();

        $this->assertSame('all', $res->json('recipientsMeta.filter'));
        $this->assertCount(200, $res->json('recipients'));
    }
}
