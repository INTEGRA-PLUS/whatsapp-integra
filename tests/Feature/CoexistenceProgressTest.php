<?php

namespace Tests\Feature;

use App\Events\CoexistenceSyncEvent;
use App\Models\CoexistenceSync;
use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Services\CoexistenceIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * El avance que ve el cliente durante la importación.
 *
 * La importación tarda minutos. Si la pantalla no dice nada, el cliente asume
 * que falló y en el peor caso desconecta el número desde su celular — gastando
 * el único intento que Meta da y obligando a rehacer todo el registro.
 *
 * Lo que se protege aquí es que el avance llegue **por los dos caminos** con la
 * misma forma: por websocket cuando Reverb conecta, y por consulta cuando no.
 */
class CoexistenceProgressTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Meta reporta el progreso POR FASE. Un 100 en la fase 0 no es el final de
     * nada, y enseñarlo tal cual haría que la barra llegue al tope tres veces.
     */
    public function test_el_porcentaje_reparte_el_avance_entre_las_tres_fases(): void
    {
        $sync = new CoexistenceSync(['status' => CoexistenceSync::IMPORTANDO]);

        $sync->phase = 0;
        $sync->progress = 100;
        $primeraFase = $sync->porcentajeGlobal();

        $sync->phase = 1;
        $sync->progress = 0;
        $segundaFase = $sync->porcentajeGlobal();

        $sync->phase = 2;
        $sync->progress = 90;
        $terceraFase = $sync->porcentajeGlobal();

        $this->assertSame(33, $primeraFase);
        $this->assertGreaterThanOrEqual($primeraFase, $segundaFase);
        $this->assertGreaterThan($segundaFase, $terceraFase);

        // Nunca 100 mientras no esté completada: el cliente vería la barra llena
        // con la importación todavía corriendo y se iría de la pantalla.
        $this->assertLessThan(100, $terceraFase);
    }

    public function test_solo_una_importacion_completada_llega_al_cien(): void
    {
        $sync = new CoexistenceSync([
            'status' => CoexistenceSync::COMPLETADA,
            'phase'  => 2,
        ]);

        $this->assertSame(100, $sync->porcentajeGlobal());
    }

    /** Cada lote de historial empuja el avance a la pantalla. */
    public function test_el_avance_se_emite_al_procesar_un_lote(): void
    {
        Event::fake([CoexistenceSyncEvent::class]);

        $instancia = $this->instancia();

        app(CoexistenceIngestService::class)->importarHistorial($instancia, [
            'metadata' => ['display_phone_number' => '573181454747', 'phone_number_id' => '1247515825107349'],
            'history'  => [[
                'metadata' => ['phase' => 0, 'chunk_order' => 1, 'progress' => 40],
                'threads'  => [['id' => '573001112233', 'messages' => [[
                    'from' => '573001112233', 'id' => 'wamid.uno', 'timestamp' => (string) now()->timestamp,
                    'type' => 'text', 'text' => ['body' => 'hola'],
                ]]]],
            ]],
        ]);

        Event::assertDispatched(CoexistenceSyncEvent::class);
    }

    /**
     * El respaldo por consulta devuelve exactamente la misma forma que el
     * evento: el cliente no debe distinguir por dónde llegó el dato.
     */
    public function test_la_consulta_de_respaldo_devuelve_el_estado(): void
    {
        $usuario = $this->usuario();
        $instancia = $this->instancia($usuario->company_id);

        CoexistenceSync::create([
            'instance_id'       => $instancia->id,
            'status'            => CoexistenceSync::IMPORTANDO,
            'phase'             => 1,
            'progress'          => 50,
            'contacts_imported' => 418,
            'messages_imported' => 1204,
            'requested_at'      => now(),
        ]);

        $this->actingAs($usuario)
            ->getJson("/instances/{$instancia->id}/coexistence-sync")
            ->assertOk()
            ->assertJsonPath('sync.status', CoexistenceSync::IMPORTANDO)
            ->assertJsonPath('sync.contactos', 418)
            ->assertJsonPath('sync.mensajes', 1204)
            ->assertJsonPath('sync.etapa', 'Últimos 3 meses')
            ->assertJsonPath('sync.terminada', false)
            ->assertJsonPath('sync.porcentaje', 50);
    }

    /**
     * Una instancia sin importación no es un error: simplemente no hay tarjeta.
     *
     * Se responde `sync: null` y no un objeto vacío, porque `{}` en JavaScript
     * es verdadero y la pantalla pintaría una tarjeta de progreso hueca.
     */
    public function test_una_instancia_sin_importacion_devuelve_nulo(): void
    {
        $usuario = $this->usuario();
        $instancia = $this->instancia($usuario->company_id);

        $this->actingAs($usuario)
            ->getJson("/instances/{$instancia->id}/coexistence-sync")
            ->assertOk()
            ->assertJsonPath('sync', null);
    }

    /** El progreso de una empresa no se le enseña a otra. */
    public function test_no_se_puede_consultar_la_importacion_de_otra_empresa(): void
    {
        $ajeno = $this->usuario('Otra Fibra', 'otra-fibra', 'otro@fibra.test');
        $instancia = $this->instancia();

        CoexistenceSync::create(['instance_id' => $instancia->id, 'requested_at' => now()]);

        $this->actingAs($ajeno)
            ->getJson("/instances/{$instancia->id}/coexistence-sync")
            ->assertForbidden();
    }

    /**
     * Que el negocio no comparta sus chats se cuenta con palabras, no con un
     * error rojo: es una decisión suya y mandarlo a soporte por eso es ruido.
     */
    public function test_el_rechazo_del_historial_se_explica_sin_parecer_un_fallo(): void
    {
        $sync = new CoexistenceSync(['status' => CoexistenceSync::RECHAZADA]);

        $pantalla = $sync->paraPantalla();

        $this->assertStringContainsString('No se compartió el historial', $pantalla['error']);
        $this->assertTrue($pantalla['terminada']);
    }

    /**
     * La guía vive dentro del producto, en el momento en que el cliente está
     * mirando el botón. Su ruta va ANTES que `/instances/{instance}`, o
     * "guia-coexistencia" se tomaría por el id de una instancia.
     */
    public function test_la_guia_se_sirve_dentro_del_crm(): void
    {
        $this->actingAs($this->usuario())
            ->get('/instances/guia-coexistencia')
            ->assertOk();
    }

    public function test_la_guia_no_es_publica(): void
    {
        $this->get('/instances/guia-coexistencia')->assertRedirect('/login');
    }

    // ------------------------------------------------------------- utilidades

    private function usuario(string $nombre = 'Fibra XYZ', string $slug = 'fibra-xyz', string $email = 'admin@fibra.test'): User
    {
        $company = Company::create(['name' => $nombre, 'slug' => $slug, 'active' => true]);

        foreach (['instances.view'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        return User::create([
            'company_id' => $company->id,
            'name'       => 'Admin',
            'email'      => $email,
            'password'   => bcrypt('secreto123'),
        ]);
    }

    private function instancia(?int $companyId = null): Instance
    {
        $companyId ??= Company::create(['name' => 'Dueña', 'slug' => 'duena-' . Str::random(5), 'active' => true])->id;

        return Instance::create([
            'company_id'           => $companyId,
            'uuid'                 => (string) Str::uuid(),
            'name'                 => 'Principal',
            'phone_number_id'      => '1247515825107349',
            'waba_id'              => '1421384372768123',
            'display_phone_number' => '+57 318 1454747',
            'type'                 => 'meta',
            'status'               => 'active',
            'active'               => true,
        ]);
    }
}
