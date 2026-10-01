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
 * Editar una plantilla de WhatsApp desde el CRM.
 *
 * Lo delicado no es la llamada a Meta, que es una sola: es todo lo que se
 * comprueba antes. La plantilla tiene que ser del WABA de la línea —el token
 * alcanza los de otras empresas—, Meta sólo deja editar ciertos estados, y la
 * categoría de una aprobada no se toca. Cada uno de esos frenos evita una
 * llamada que o falla con un mensaje críptico o, peor, sale bien donde no debía.
 */
class EdicionDePlantillasTest extends TestCase
{
    use RefreshDatabase;

    private const CUERPO = 'Hola {{1}}, tu factura está lista.';

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $empresa = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet-'.Str::random(4), 'active' => true]);

        Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-waba-1',
        ]);

        $this->usuario = User::create([
            'company_id' => $empresa->id,
            'name' => 'Admin',
            'email' => 'admin@cmnet.test',
            'password' => 'secret',
            'active' => true,
        ]);

        setPermissionsTeamId($empresa->id);
        $rol = Role::firstOrCreate(['name' => 'admin', 'company_id' => $empresa->id, 'guard_name' => 'web']);
        $rol->givePermissionTo(Permission::firstOrCreate(['name' => 'templates.update', 'guard_name' => 'web']));
        $this->usuario->assignRole($rol);

        // El WABA de la línea tiene tres plantillas. La 999 existe en Meta pero
        // es de otra empresa: no sale en este listado.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/waba-1/message_templates')) {
                return Http::response(['data' => [
                    $this->plantilla('111', 'APPROVED'),
                    $this->plantilla('222', 'REJECTED'),
                    $this->plantilla('333', 'PENDING'),
                ]], 200);
            }

            if ($request->method() === 'POST') {
                return Http::response(['success' => true], 200);
            }

            return Http::response([], 404);
        });
    }

    private function plantilla(string $id, string $estado): array
    {
        return [
            'id' => $id,
            'name' => 'aviso_factura',
            'language' => 'es',
            'status' => $estado,
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => self::CUERPO]],
        ];
    }

    private function editar(string $id, string $categoria = 'UTILITY')
    {
        return $this->actingAs($this->usuario)->postJson("/api/templates/{$id}", [
            'category' => $categoria,
            'parameter_format' => 'POSITIONAL',
            'components' => [[
                'type' => 'BODY',
                'text' => 'Hola {{1}}, tu factura de este mes está lista.',
                'example' => ['body_text' => [['Ana']]],
            ]],
        ]);
    }

    private function seEditoEnMeta(string $id): bool
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), "/{$id}"))->isNotEmpty();
    }

    public function test_edita_una_aprobada_mandando_solo_el_contenido(): void
    {
        $this->editar('111')->assertOk()->assertJsonPath('editada', true);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/111')
            && ($r->data()['components'][0]['text'] ?? null) === 'Hola {{1}}, tu factura de este mes está lista.'
            // La categoría no cambió: mandarla igual haría que Meta rechace la
            // edición de una aprobada.
            && ! array_key_exists('category', $r->data()));
    }

    public function test_no_edita_una_plantilla_que_no_es_del_waba_de_la_linea(): void
    {
        $this->editar('999')->assertNotFound();

        $this->assertFalse($this->seEditoEnMeta('999'));
    }

    public function test_no_edita_una_plantilla_que_meta_todavia_esta_revisando(): void
    {
        $this->editar('333')->assertStatus(422);

        $this->assertFalse($this->seEditoEnMeta('333'));
    }

    public function test_no_cambia_la_categoria_de_una_aprobada(): void
    {
        $this->editar('111', 'MARKETING')->assertStatus(422);

        $this->assertFalse($this->seEditoEnMeta('111'));
    }

    public function test_una_rechazada_si_puede_cambiar_de_categoria(): void
    {
        $this->editar('222', 'MARKETING')->assertOk();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_ends_with($r->url(), '/222')
            && ($r->data()['category'] ?? null) === 'MARKETING');
    }

    public function test_sin_el_permiso_de_editar_no_entra(): void
    {
        $otro = User::create([
            'company_id' => $this->usuario->company_id,
            'name' => 'Agente',
            'email' => 'agente@cmnet.test',
            'password' => 'secret',
            'active' => true,
        ]);

        $this->actingAs($otro)->postJson('/api/templates/111', [
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Hola']],
        ])->assertForbidden();

        $this->assertFalse($this->seEditoEnMeta('111'));
    }
}
