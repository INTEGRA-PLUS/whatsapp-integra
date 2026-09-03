<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El buscador de empresas del panel master.
 *
 * Buscar es una visita Inertia a la misma acción que pinta el dashboard, así
 * que sin cuidado cada tecla recalcula todo: dos barridos completos de
 * whatsapp_messages y un conteo por empresa, para devolver diez filas. Eso son
 * los cuatro o cinco segundos que se sentían al escribir.
 *
 * Lo que se protege aquí es que la recarga parcial siga siendo parcial de
 * verdad — es decir, que los bloques del dashboard sigan siendo closures. Si
 * alguien los convierte en valores ya calculados, la página funciona igual y
 * la lentitud vuelve sin que nada falle; por eso se mide en consultas.
 */
class MasterSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Buscar no debe tocar whatsapp_messages.
     *
     * Es la medición que importa: los dos barridos de esa tabla y el conteo por
     * empresa son el grueso de los segundos que se sentían al teclear. Si
     * alguien convierte los bloques del dashboard en valores ya calculados, la
     * página sigue funcionando igual y esto se pone rojo, que es el punto.
     */
    public function test_buscar_no_toca_la_tabla_de_mensajes(): void
    {
        $consultas = [];
        DB::listen(function ($q) use (&$consultas) { $consultas[] = $q->sql; });

        $this->buscar('ticc')->assertOk();

        $mensajes = array_filter($consultas, fn ($sql) => str_contains($sql, 'whatsapp_messages'));

        $this->assertSame([], array_values($mensajes), 'El buscador no debe consultar whatsapp_messages.');
    }

    /** Y aun así tiene que buscar: por nombre de empresa, email y admin. */
    public function test_el_buscador_filtra(): void
    {
        Company::create(['name' => 'Ticc Telecomunicaciones', 'slug' => 'ticc', 'email' => 'hola@ticc.test', 'active' => true]);
        Company::create(['name' => 'Fibra XYZ', 'slug' => 'fibra-xyz', 'email' => 'hola@xyz.test', 'active' => true]);

        $this->buscar('ticc')
            ->assertOk()
            ->assertJsonPath('props.companies.data.0.name', 'Ticc Telecomunicaciones')
            ->assertJsonCount(1, 'props.companies.data');
    }

    /** El filtro de estado viaja por el mismo camino parcial. */
    public function test_el_filtro_de_estado_tampoco_recalcula(): void
    {
        Company::create(['name' => 'Apagada', 'slug' => 'apagada', 'email' => 'no@x.test', 'active' => false]);
        Company::create(['name' => 'Encendida', 'slug' => 'encendida', 'email' => 'si@x.test', 'active' => true]);

        $consultas = [];
        DB::listen(function ($q) use (&$consultas) { $consultas[] = $q->sql; });

        $this->parcial('/master?status=inactive')
            ->assertOk()
            ->assertJsonPath('props.companies.data.0.name', 'Apagada')
            ->assertJsonCount(1, 'props.companies.data');

        $this->assertSame([], array_values(array_filter($consultas, fn ($s) => str_contains($s, 'whatsapp_messages'))));
    }

    /**
     * La carga completa sí trae el dashboard entero: la recarga parcial es un
     * atajo del buscador, no una amputación de la página.
     *
     * Sólo corre en MySQL porque los agregados usan DATE_FORMAT.
     */
    public function test_la_carga_completa_trae_el_dashboard(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Los agregados del dashboard usan DATE_FORMAT (MySQL).');
        }

        $this->actingAs($this->master())
            ->get('/master')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('stats')
                ->has('companies_growth')
                ->has('messages_volume')
                ->has('top_companies')
                ->has('companies'));
    }

    /**
     * La versión de assets que Inertia exige en las recargas parciales: sin
     * ella responde 409 pidiendo recargar la página entera.
     */
    private function inertiaVersion(): ?string
    {
        return app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    }

    /** La recarga parcial que hace el buscador del panel. */
    private function buscar(string $q)
    {
        return $this->parcial('/master?search=' . urlencode($q));
    }

    private function parcial(string $url)
    {
        return $this->actingAs($this->master())
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $this->inertiaVersion(),
                'X-Inertia-Partial-Component' => 'Master/Index',
                'X-Inertia-Partial-Data' => 'companies,filters',
            ])
            ->get($url);
    }

    private function master(): User
    {
        $company = Company::create(['name' => 'Integra', 'slug' => 'integra', 'active' => true]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Master',
            'email' => 'master@example.test',
            'password' => bcrypt('secreto123'),
            'role' => 'master',
            'active' => true,
        ]);

        // isMaster() pregunta por el rol de spatie, no por la columna.
        setPermissionsTeamId($company->id);
        $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate([
            'name' => 'master',
            'company_id' => $company->id,
            'guard_name' => 'web',
        ]));

        return $user->fresh();
    }
}
