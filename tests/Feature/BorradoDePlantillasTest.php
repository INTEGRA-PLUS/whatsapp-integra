<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Borrar una plantilla desde el CRM.
 *
 * Nació para corregir categorías: Meta no deja cambiar la de una aprobada, y
 * una de utilidad que quedó como marketing sólo se arregla borrándola y
 * creándola otra vez (2-oct-2026). Lo que se cuida es a quién se borra: el
 * WABA de la línea de la empresa, y el nombre exacto —el filtro de Meta es por
 * coincidencia parcial—.
 */
class BorradoDePlantillasTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Instance $linea;

    protected function setUp(): void
    {
        parent::setUp();

        $empresa = Company::create(['name' => 'SM', 'slug' => 'sm-'.Str::random(4), 'active' => true]);

        $this->linea = Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '910373275495096',
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-waba-1',
        ]);

        $this->usuario = $this->usuarioCon($empresa, ['templates.delete']);

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET' && str_contains($request->url(), '/waba-1/message_templates')) {
                // Meta filtra `name` por coincidencia parcial: pedir `factura`
                // trae también `factura_vencida`.
                return Http::response(['data' => [
                    ['id' => '1', 'name' => 'factura', 'language' => 'es', 'status' => 'APPROVED'],
                    ['id' => '2', 'name' => 'factura', 'language' => 'en', 'status' => 'APPROVED'],
                    ['id' => '3', 'name' => 'factura_vencida', 'language' => 'es', 'status' => 'APPROVED'],
                ]], 200);
            }

            if ($request->method() === 'DELETE') {
                return Http::response(['success' => true], 200);
            }

            return Http::response([], 404);
        });
    }

    private function usuarioCon(Company $empresa, array $permisos): User
    {
        $usuario = User::create([
            'company_id' => $empresa->id,
            'name' => 'Admin',
            'email' => Str::random(6).'@sm.test',
            'password' => 'secret',
            'active' => true,
        ]);

        setPermissionsTeamId($empresa->id);
        $rol = Role::create(['name' => 'rol-'.Str::random(4), 'company_id' => $empresa->id, 'guard_name' => 'web']);
        foreach ($permisos as $permiso) {
            $rol->givePermissionTo(Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']));
        }
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function seBorroEnMeta(): bool
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->isNotEmpty();
    }

    public function test_borra_por_nombre_en_el_waba_de_la_linea(): void
    {
        $this->actingAs($this->usuario)
            ->deleteJson('/api/templates/family/factura?instance_id='.$this->linea->id)
            ->assertOk()
            ->assertJsonPath('eliminada', true)
            ->assertJsonPath('idiomas', 2);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/waba-1/message_templates')
            && str_contains($r->url(), 'name=factura')
            && ! str_contains($r->url(), 'factura_vencida'));
    }

    public function test_un_nombre_que_solo_coincide_a_medias_no_se_borra(): void
    {
        $this->actingAs($this->usuario)
            ->deleteJson('/api/templates/family/factu?instance_id='.$this->linea->id)
            ->assertNotFound();

        $this->assertFalse($this->seBorroEnMeta());
    }

    public function test_no_borra_con_la_linea_de_otra_empresa(): void
    {
        $otra = Company::create(['name' => 'Otra', 'slug' => 'otra-'.Str::random(4), 'active' => true]);
        $ajeno = $this->usuarioCon($otra, ['templates.delete']);

        // Su empresa no tiene líneas: la de SM no le sirve aunque mande su id.
        $this->actingAs($ajeno)
            ->deleteJson('/api/templates/family/factura?instance_id='.$this->linea->id)
            ->assertStatus(422);

        $this->assertFalse($this->seBorroEnMeta());
    }

    public function test_sin_permiso_de_borrar_no_se_borra(): void
    {
        $empresa = Company::find($this->linea->company_id);
        $soloVer = $this->usuarioCon($empresa, ['templates.view', 'templates.update']);

        $this->actingAs($soloVer)
            ->deleteJson('/api/templates/family/factura?instance_id='.$this->linea->id)
            ->assertForbidden();

        $this->assertFalse($this->seBorroEnMeta());
    }
}
