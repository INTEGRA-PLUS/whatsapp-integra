<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La pestaña de planes del panel master.
 *
 * Hasta ahora era decorado: tres tarjetas y una tabla escritas a pelo en el
 * JSX —«Startup 49,99 USD, 12 suscripciones», «Business Pro», «Enterprise»—
 * que no correspondían ni a los planes del producto ni a ninguna cifra que se
 * hubiera cobrado nunca. Un panel que enseña números inventados es peor que uno
 * que no enseña nada, porque se decide con ellos.
 *
 * Lo que se protege aquí es que los números salgan de donde dicen salir: el
 * catálogo de `config/planes.php` y las empresas de la base de datos.
 */
class PanelDePlanesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Los planes son los del producto, y cada uno cuenta sus empresas.
     *
     * Se pide como recarga parcial porque los bloques del dashboard usan
     * DATE_FORMAT y no corren en sqlite; el resumen de planes no los necesita,
     * y esta es justamente la prueba de que va por su cuenta.
     */
    public function test_el_resumen_sale_del_catalogo_y_de_las_empresas(): void
    {
        $this->empresa('Fibra Sur', 'esencial');
        $this->empresa('Cootramed', 'automatizacion');
        $this->empresa('Ticc', 'inteligente');

        $resumen = $this->resumen();

        $this->assertSame(
            ['esencial', 'automatizacion', 'inteligente'],
            array_column($resumen['planes'], 'slug'),
            'Los planes de la pantalla son los de config/planes.php, en su orden.'
        );

        $porSlug = collect($resumen['planes'])->keyBy('slug');

        $this->assertSame(1, $porSlug['esencial']['empresas']);
        $this->assertSame(1, $porSlug['automatizacion']['empresas']);

        // La empresa del master también es Inteligente: no tiene plan puesto.
        $this->assertSame(2, $porSlug['inteligente']['empresas']);

        // El precio va como rango y no como número suelto: un plan cuesta
        // distinto según el tramo de socios, y dar uno solo obligaría a elegir
        // un tramo arbitrario y llamarlo «el precio».
        $this->assertSame(35, $porSlug['esencial']['precio_desde']);
        $this->assertSame(259, $porSlug['esencial']['precio_hasta']);
        $this->assertSame(65, $porSlug['inteligente']['precio_desde']);
        $this->assertSame(419, $porSlug['inteligente']['precio_hasta']);
    }

    /**
     * Una empresa con un plan que ya no está en el catálogo cuenta como
     * Inteligente: es lo que el candado de las extensiones le aplica de verdad,
     * porque `PlanDeLaEmpresa::slug()` cae ahí cuando no reconoce el valor. La
     * columna tiene default, así que «sin plan» sólo puede darse así.
     *
     * Agrupando por la columna a pelo se quedaría fuera de los tres planes y la
     * suma no daría el total de empresas, que es como se nota el error.
     */
    public function test_una_empresa_con_un_plan_retirado_no_se_pierde(): void
    {
        $this->empresa('De un plan que ya no existe', 'pro_antiguo');
        $this->empresa('Con plan', 'esencial');

        $resumen = $this->resumen();

        $this->assertSame(
            $resumen['total_empresas'],
            array_sum(array_column($resumen['planes'], 'empresas')),
            'Las empresas de los tres planes tienen que sumar el total.'
        );
    }

    /** Quién factura y quién no, que es la columna que importa en la transición. */
    public function test_cuenta_las_empresas_por_estado_de_cobro(): void
    {
        $this->empresa('Paga', 'inteligente', 'activo');
        $this->empresa('Cortesía', 'inteligente', 'cortesia');
        $this->empresa('Debe', 'inteligente', 'suspendido');

        $resumen = $this->resumen();

        $this->assertSame(1, $resumen['cobros']['activo']);
        $this->assertSame(1, $resumen['cobros']['suspendido']);

        // La del master no tiene cobro puesto: también es cortesía.
        $this->assertSame(2, $resumen['cobros']['cortesia']);

        // «Facturando» son dos: la activa y la suspendida. Suspendido es quien
        // no ha pagado, no quien no debe — se le sigue facturando, y el CRM
        // tampoco se le apaga. Perdonarle la factura al contarlo haría que el
        // panel enseñara menos ingreso pendiente del que hay.
        $inteligente = collect($resumen['planes'])->firstWhere('slug', 'inteligente');
        $this->assertSame(2, $inteligente['facturando']);
    }

    /** La escalera de contactos, que hasta ahora no se veía en ninguna pantalla. */
    public function test_los_tramos_son_los_de_la_configuracion(): void
    {
        $resumen = $this->resumen();

        $this->assertSame(
            array_keys(config('planes.credito_ia')),
            array_column($resumen['tramos'], 'hasta')
        );
    }

    /**
     * Cada tramo lleva el precio de los tres planes.
     *
     * La pantalla enseñaba de cada plan sólo su precio menor y su mayor —«35 a
     * 259»—, y un rango se lee como un «depende» o como algo negociable. Son
     * quince precios fijos, y el que hace falta delante de un cliente es el de
     * su tramo, no el rango.
     */
    public function test_cada_tramo_trae_el_precio_de_cada_plan(): void
    {
        $tramos = collect($this->resumen()['tramos'])->keyBy('hasta');

        foreach (config('planes.precios') as $hasta => $porPlan) {
            foreach ($porPlan as $slug => $precio) {
                $this->assertSame(
                    $precio,
                    $tramos[$hasta]['precios'][$slug] ?? null,
                    "El precio de {$slug} en el tramo de {$hasta} no llega a la pantalla."
                );
            }
        }
    }

    /**
     * Cada tramo dice cuántas empresas caen hoy dentro, por sus contactos de
     * verdad y no por el tramo que alguien tecleó en la ficha.
     *
     * Es lo que responde si la escalera está donde están los clientes. Y la
     * suma tiene que dar todas las empresas mientras ninguna se salga del
     * último tramo: una empresa sin un solo contacto es un cliente recién
     * conectado, no un cliente que no existe, y cuenta en el primero.
     */
    public function test_cada_tramo_cuenta_las_empresas_por_contactos_reales(): void
    {
        $sinContactos = $this->empresa('Recién conectada');
        $conPocos = $this->empresa('Pequeña');
        $mediana = $this->empresa('Mediana');

        $this->contactos($conPocos, 3);
        $this->contactos($mediana, 600);

        $escalera = $this->resumen()['tramos'];
        $tramos = collect($escalera)->keyBy('hasta');

        // La del master y las dos de arriba de 500 o menos: 3 en el primero.
        $this->assertSame(3, $tramos[500]['empresas']);
        $this->assertSame(1, $tramos[2000]['empresas']);
        $this->assertSame(0, $tramos[5000]['empresas']);

        $this->assertSame(
            Company::count(),
            collect($escalera)->sum('empresas'),
            'Ninguna empresa puede quedarse fuera de la escalera.'
        );
    }

    private function contactos(Company $company, int $cuantos): void
    {
        $filas = [];

        for ($i = 0; $i < $cuantos; $i++) {
            $filas[] = [
                'company_id' => $company->id,
                'name' => 'Contacto '.$i,
                'phone_number' => '57300'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($filas, 200) as $lote) {
            DB::table('contacts')->insert($lote);
        }
    }

    /** Y los dos meses del pago anual, que son parte del precio. */
    public function test_el_descuento_anual_llega_a_la_pantalla(): void
    {
        $this->assertSame(
            (int) config('planes.meses_gratis_al_pagar_anual'),
            $this->resumen()['meses_gratis_al_pagar_anual']
        );
    }

    /**
     * Y buscar una empresa no debe recalcular todo esto.
     *
     * El resumen recorre todas las empresas del sistema; va en un closure por
     * lo mismo que los bloques del dashboard ([[MasterSearchTest]]). Si alguien
     * lo convierte en un valor ya calculado, la página funciona igual y el
     * buscador se vuelve lento otra vez sin que nada falle.
     */
    public function test_buscar_no_calcula_el_resumen_de_planes(): void
    {
        $this->empresa('Ticc', 'esencial');

        $this->parcial('/master?search=ticc', 'companies,filters')
            ->assertOk()
            ->assertJsonMissingPath('props.planes_resumen');
    }

    /** @return array<string, mixed> */
    private function resumen(): array
    {
        $respuesta = $this->parcial('/master?tab=plans', 'planes_resumen')->assertOk();

        return $respuesta->json('props.planes_resumen');
    }

    /**
     * `plan` y `cobro` son NOT NULL con default en la tabla ('inteligente' y
     * 'cortesia'): no se pasan si no se piden, o el insert revienta.
     */
    private function empresa(string $nombre, ?string $plan = null, ?string $cobro = null): Company
    {
        return Company::create(array_filter([
            'name' => $nombre,
            'slug' => Str::slug($nombre),
            'email' => Str::slug($nombre).'@x.test',
            'active' => true,
            'plan' => $plan,
            'cobro' => $cobro,
        ], fn ($valor) => $valor !== null));
    }

    private function parcial(string $url, string $props)
    {
        return $this->actingAs($this->master())
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Master/Index',
                'X-Inertia-Partial-Data' => $props,
            ])
            ->get($url);
    }

    private function master(): User
    {
        $company = Company::firstOrCreate(
            ['slug' => 'integra'],
            ['name' => 'Integra', 'active' => true]
        );

        if ($existente = User::where('email', 'master@example.test')->first()) {
            return $existente;
        }

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Master',
            'email' => 'master@example.test',
            'password' => bcrypt('secreto123'),
            'role' => 'master',
            'active' => true,
        ]);

        setPermissionsTeamId($company->id);
        $user->assignRole(Role::firstOrCreate([
            'name' => 'master',
            'company_id' => $company->id,
            'guard_name' => 'web',
        ]));

        return $user->fresh();
    }
}
