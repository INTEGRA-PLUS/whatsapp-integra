<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Copiar plantillas de una línea a otra de la misma empresa.
 *
 * Las plantillas **no viven en el CRM: viven en Meta y son por WABA**. Dos
 * líneas con WABA distinto tienen catálogos separados.
 *
 * Eso rompió la facturación de Transinternet el 10-sep-2026: al cambiar la
 * línea de envíos a la de WhatsApp Business, Meta devolvía «(#100) Invalid
 * parameter» en cada factura, porque ese WABA tenía 1 plantilla y el otro 10,
 * `facturacion` entre ellas. Sin poder copiarlas, cambiar de línea significa
 * rehacer el catálogo a mano.
 */
class DuplicarPlantillasTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Instance $origen;

    private Instance $destino;

    protected function setUp(): void
    {
        parent::setUp();

        $empresa = Company::create(['name' => 'ISP', 'slug' => 'isp-'.Str::random(5), 'active' => true]);

        $this->origen = $this->linea($empresa, 'Vieja', 'waba-vieja');
        $this->destino = $this->linea($empresa, 'Nueva', 'waba-nueva');

        $this->usuario = User::create([
            'company_id' => $empresa->id, 'name' => 'Admin',
            'email' => Str::random(6).'@test.local', 'password' => bcrypt('x'),
            'role' => 'admin', 'active' => true,
        ]);

        setPermissionsTeamId($empresa->id);
        $rol = Role::firstOrCreate(['name' => 'admin', 'company_id' => $empresa->id, 'guard_name' => 'web']);
        foreach (['templates.view', 'templates.create'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }
        $rol->syncPermissions(Permission::all());
        $this->usuario->assignRole($rol);
        $this->usuario = $this->usuario->fresh();
    }

    public function test_copia_las_que_faltan_en_la_otra_linea(): void
    {
        $this->fingirMeta(
            enOrigen: [$this->plantillaDeTexto('bienvenida'), $this->plantillaDeTexto('recordatorio')],
            enDestino: [$this->plantillaDeTexto('bienvenida')],
        );

        $respuesta = $this->actingAs($this->usuario)->postJson('/api/templates/duplicar', [
            'origen_instance_id' => $this->origen->id,
            'destino_instance_id' => $this->destino->id,
        ]);

        $respuesta->assertOk()
            ->assertJsonPath('copiadas', 1)
            ->assertJsonPath('resultados.0.plantilla', 'recordatorio');

        // La que ya estaba no se vuelve a crear.
        Http::assertNotSent(fn ($p) => str_contains($p->url(), 'message_templates')
            && $p->method() === 'POST'
            && ($p->data()['name'] ?? null) === 'bienvenida');
    }

    /** La misma plantilla en otro idioma es otra plantilla para Meta. */
    public function test_el_idioma_cuenta_como_parte_de_la_identidad(): void
    {
        $this->fingirMeta(
            enOrigen: [$this->plantillaDeTexto('aviso', 'es_CO'), $this->plantillaDeTexto('aviso', 'en_US')],
            enDestino: [$this->plantillaDeTexto('aviso', 'es_CO')],
        );

        $this->actingAs($this->usuario)->postJson('/api/templates/duplicar', [
            'origen_instance_id' => $this->origen->id,
            'destino_instance_id' => $this->destino->id,
        ])->assertOk()->assertJsonPath('copiadas', 1);
    }

    /** Se puede pedir sólo algunas por nombre. */
    public function test_copia_solo_las_pedidas(): void
    {
        $this->fingirMeta(
            enOrigen: [$this->plantillaDeTexto('a'), $this->plantillaDeTexto('b'), $this->plantillaDeTexto('c')],
            enDestino: [],
        );

        $this->actingAs($this->usuario)->postJson('/api/templates/duplicar', [
            'origen_instance_id' => $this->origen->id,
            'destino_instance_id' => $this->destino->id,
            'nombres' => ['b'],
        ])->assertOk()->assertJsonPath('copiadas', 1)->assertJsonPath('resultados.0.plantilla', 'b');
    }

    /** Dos líneas del mismo WABA ya ven lo mismo: no hay nada que copiar. */
    public function test_avisa_si_las_dos_lineas_comparten_waba(): void
    {
        $this->destino->update(['waba_id' => $this->origen->waba_id]);

        $this->actingAs($this->usuario)->postJson('/api/templates/duplicar', [
            'origen_instance_id' => $this->origen->id,
            'destino_instance_id' => $this->destino->id,
        ])->assertStatus(422);
    }

    /** Y no se puede copiar hacia la línea de otra empresa. */
    public function test_no_copia_a_la_linea_de_otra_empresa(): void
    {
        $vecina = Company::create(['name' => 'Vecina', 'slug' => 'vecina-'.Str::random(5), 'active' => true]);
        $ajena = $this->linea($vecina, 'Ajena', 'waba-ajena');

        $this->fingirMeta(enOrigen: [$this->plantillaDeTexto('x')], enDestino: []);

        $this->actingAs($this->usuario)->postJson('/api/templates/duplicar', [
            'origen_instance_id' => $this->origen->id,
            'destino_instance_id' => $ajena->id,
        ])->assertStatus(422);
    }

    /**
     * @param  array<int, array<string, mixed>>  $enOrigen
     * @param  array<int, array<string, mixed>>  $enDestino
     */
    private function fingirMeta(array $enOrigen, array $enDestino): void
    {
        Http::fake([
            '*waba-vieja/message_templates*' => Http::response(['data' => $enOrigen], 200),
            '*waba-nueva/message_templates*' => Http::sequence()
                ->push(['data' => $enDestino], 200)
                ->whenEmpty(Http::response(['id' => '1', 'status' => 'PENDING'], 200)),
            '*' => Http::response(['id' => '1', 'status' => 'PENDING'], 200),
        ]);
    }

    /** @return array<string, mixed> */
    private function plantillaDeTexto(string $nombre, string $idioma = 'es_CO'): array
    {
        return [
            'id' => (string) random_int(1, 9999),
            'name' => $nombre,
            'language' => $idioma,
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'components' => [[
                'type' => 'BODY',
                'text' => 'Hola {{1}}',
                'example' => ['body_text' => [['Juan']]],
            ]],
        ];
    }

    private function linea(Company $empresa, string $nombre, string $waba): Instance
    {
        return Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => $nombre,
            'phone_number_id' => 'pnid-'.Str::random(6),
            'waba_id' => $waba,
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-'.Str::random(6),
        ]);
    }
}
