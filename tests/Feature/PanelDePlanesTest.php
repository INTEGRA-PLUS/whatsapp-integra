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
        $this->empresa('Fibra Sur', 'basico');
        $this->empresa('Cootramed', 'pro');
        $this->empresa('Ticc', 'avanzado');

        $resumen = $this->resumen();

        $this->assertSame(
            ['basico', 'pro', 'avanzado'],
            array_column($resumen['planes'], 'slug'),
            'Los planes de la pantalla son los de config/planes.php, en su orden.'
        );

        $porSlug = collect($resumen['planes'])->keyBy('slug');

        $this->assertSame(1, $porSlug['pro']['empresas']);
        $this->assertSame(1, $porSlug['avanzado']['empresas']);

        // La empresa del master también cae en Básico: no tiene plan puesto, y
        // el suelo ante la duda es lo correcto.
        $this->assertSame(2, $porSlug['basico']['empresas']);

        // Y el precio es un número fijo, no un rango. Un rango se lee como
        // «depende» o como negociable; un número se dice en la mesa.
        $this->assertSame(config('planes.crm.basico.precio'), $porSlug['basico']['precio']);
        $this->assertSame(config('planes.crm.avanzado.precio'), $porSlug['avanzado']['precio']);
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
        $this->empresa('Con plan', 'basico');

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
        $this->empresa('Paga', 'avanzado', 'activo');
        $this->empresa('Cortesía', 'avanzado', 'cortesia');
        $this->empresa('Debe', 'avanzado', 'suspendido');

        $resumen = $this->resumen();

        $this->assertSame(1, $resumen['cobros']['activo']);
        $this->assertSame(1, $resumen['cobros']['suspendido']);

        // La del master no tiene cobro puesto: también es cortesía.
        $this->assertSame(2, $resumen['cobros']['cortesia']);

        // «Facturando» son dos: la activa y la suspendida. Suspendido es quien
        // no ha pagado, no quien no debe — se le sigue facturando, y el CRM
        // tampoco se le apaga. Perdonarle la factura al contarlo haría que el
        // panel enseñara menos ingreso pendiente del que hay.
        $avanzado = collect($resumen['planes'])->firstWhere('slug', 'avanzado');
        $this->assertSame(2, $avanzado['facturando']);
    }

    /**
     * Los tres planes de CRM, con lo que incluye cada uno.
     *
     * La pantalla enseñaba una escalera de quince precios —cinco tramos por tres
     * planes— que se retiró el 15-sep-2026: ahora son tres precios fijos, como
     * pidió Alejandro y como lo hace TecnoChat. Un rango se lee como «depende» o
     * como negociable; un número se dice en la mesa.
     */
    public function test_los_planes_traen_su_precio_y_lo_que_incluyen(): void
    {
        $planes = collect($this->resumen()['planes'])->keyBy('slug');

        foreach (config('planes.crm') as $slug => $datos) {
            $this->assertSame($datos['precio'], $planes[$slug]['precio']);
            $this->assertSame($datos['agentes'], $planes[$slug]['agentes']);
            $this->assertSame($datos['contactos'], $planes[$slug]['contactos']);
            $this->assertSame($datos['credito_ia'], $planes[$slug]['credito_ia']);
        }
    }

    /**
     * Y el complemento de IA aparte, que es lo que se vende.
     *
     * Va separado del plan porque al cliente de Integra el CRM ya se lo cobró el
     * ERP: lo único nuevo que se le puede vender es esto. Si el panel los
     * mezclara, no habría forma de ver cuántos lo tienen.
     */
    public function test_el_complemento_de_ia_se_cuenta_aparte(): void
    {
        $this->empresa('Con IA', 'basico', null, ['ia' => 'esencial']);
        $this->empresa('Sin IA', 'basico');

        $complementos = collect($this->resumen()['complementos'])->keyBy('slug');

        $this->assertSame(
            array_keys(config('planes.ia')),
            array_keys($complementos->all()),
            'Los complementos de la pantalla son los del catálogo, en su orden.'
        );

        $this->assertSame(config('planes.ia.esencial.precio'), $complementos['esencial']['precio']);

        // Los tres, con precio: el interruptor de la pestaña suma el del
        // complemento al del plan para enseñar las nueve combinaciones sin
        // listarlas. Si a alguno le faltara el número, el precio que se enseña
        // sería el del CRM a secas y nadie lo notaría — sale bien formado.
        foreach ($complementos as $slug => $complemento) {
            $this->assertArrayHasKey('precio', $complemento, "El complemento {$slug} no trae precio.");
            $this->assertSame(config("planes.ia.{$slug}.precio"), $complemento['precio']);
        }

        // Y qué trae cada uno, que es lo que se explica delante del cliente. La
        // frase sale de los flujos y no de un texto escrito aparte: una función
        // que cambie de nivel se la arrastra consigo.
        //
        // Son tres estados y no dos desde que `ai_menus` está en Esencial:
        // «tiene flujos» ya no significa «habla con el cliente», y resolver una
        // consulta no es lo mismo que conversar.
        $this->assertSame('les resuelve contra tu ERP', $complementos['esencial']['que_hace']);
        $this->assertSame('conversa con tus clientes', $complementos['completa']['que_hace']);
        $this->assertSame('te ayuda a atenderlos', $complementos['ninguno']['que_hace']);
        $this->assertNotEmpty($complementos['esencial']['extensiones'], 'La Esencial no dice qué trae.');
        $this->assertNotEmpty($complementos['completa']['flujos'], 'La Completa no dice qué añade.');
        $this->assertSame(1, $complementos['esencial']['empresas']);
    }

    /**
     * Cuántas vienen de Integra, que es el número que explica la facturación.
     *
     * Sin él, el panel enseñaba 2.391 USD/mes de facturación potencial sobre 41
     * clientes que en su mayoría ya pagaban — el CRM iba dentro de su ERP.
     */
    public function test_cuenta_cuantas_vienen_de_integra(): void
    {
        $this->empresa('ISP con ERP', 'basico', 'integra', ['viene_de_integra' => true]);
        $this->empresa('Cliente directo', 'basico', 'activo');

        $this->assertSame(1, $this->resumen()['de_integra']);
    }

    /** El CRM que va en los tres planes llega a la pantalla desde el catálogo. */
    public function test_el_nucleo_sale_de_la_configuracion(): void
    {
        $this->assertSame(config('planes.nucleo'), $this->resumen()['nucleo']);
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
        $this->empresa('Ticc', 'basico');

        $this->parcial('/master?search=ticc', 'companies,filters')
            ->assertOk()
            ->assertJsonMissingPath('props.planes_resumen');
    }

    /**
     * El formulario de plan trae sus opciones, o sale un desplegable vacío.
     *
     * Pasó de verdad: al separar el CRM de la IA, el panel siguió alimentando el
     * selector con `planes.disponibles`, que ya no existía. El modal abría con
     * el desplegable en blanco y no se podía asignar plan a nadie — y no falla
     * en ningún sitio, simplemente no hay nada que elegir.
     *
     * Se piden como recarga parcial de `planes,complementos`: son props de la
     * página, no del resumen, y ésa es justo la distinción que se rompió.
     */
    public function test_el_formulario_trae_los_planes_y_los_complementos(): void
    {
        $respuesta = $this->parcial('/master', 'planes,complementos')->assertOk();

        $this->assertSame(
            array_keys(config('planes.crm')),
            array_column($respuesta->json('props.planes'), 'value')
        );

        $this->assertSame(
            array_keys(config('planes.ia')),
            array_column($respuesta->json('props.complementos'), 'value')
        );

        // Y con etiqueta: un `value` sin `label` pinta opciones en blanco.
        foreach ($respuesta->json('props.planes') as $opcion) {
            $this->assertNotEmpty($opcion['label']);
        }
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
    private function empresa(string $nombre, ?string $plan = null, ?string $cobro = null, array $extra = []): Company
    {
        return Company::create(array_merge(array_filter([
            'name' => $nombre,
            'slug' => Str::slug($nombre),
            'email' => Str::slug($nombre).'@x.test',
            'active' => true,
            'plan' => $plan,
            'cobro' => $cobro,
        ], fn ($valor) => $valor !== null), $extra));
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
