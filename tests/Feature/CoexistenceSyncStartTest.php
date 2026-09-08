<?php

namespace Tests\Feature;

use App\Jobs\IniciarSincronizacionCoexistencia;
use App\Models\CoexistenceSync;
use App\Models\Company;
use App\Models\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El disparo automático de la importación de coexistencia.
 *
 * Todo lo que se prueba aquí gira alrededor de una regla de Meta: la
 * importación se pide **una sola vez**, dentro de una ventana de 24 horas.
 * Gastarla por error no se arregla reintentando — hay que desconectar el número
 * desde el celular del cliente y rehacer el registro insertado entero. Ya pasó
 * una vez, el 2026-09-08, con el historial del primer número en coexistencia.
 */
class CoexistenceSyncStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_pide_contactos_e_historial_para_un_numero_en_coexistencia(): void
    {
        $instancia = $this->instancia();
        $this->fingirMeta(esCoexistencia: true);

        (new IniciarSincronizacionCoexistencia($instancia->id))->handle(app(\App\Services\MetaWhatsAppService::class));

        $sync = CoexistenceSync::where('instance_id', $instancia->id)->first();

        $this->assertNotNull($sync);
        $this->assertSame(CoexistenceSync::SOLICITADA, $sync->status);
        $this->assertNotNull($sync->requested_at);
        $this->assertSame('req-smb_app_state_sync', $sync->contacts_request_id);
        $this->assertSame('req-history', $sync->history_request_id);

        // Los contactos primero: cuando entren las conversaciones ya tienen a
        // quién colgarse y el cliente ve nombres en vez de números.
        $tipos = $this->tiposPedidos();
        $this->assertSame(['smb_app_state_sync', 'history'], $tipos);
    }

    /**
     * Un número registrado por el camino normal no tiene historial en ningún
     * celular. Pedirlo igual gastaría el intento para no traer nada.
     */
    public function test_un_numero_sin_coexistencia_no_gasta_el_intento(): void
    {
        $instancia = $this->instancia();
        $this->fingirMeta(esCoexistencia: false);

        (new IniciarSincronizacionCoexistencia($instancia->id))->handle(app(\App\Services\MetaWhatsAppService::class));

        $this->assertSame(0, CoexistenceSync::count());
        $this->assertSame([], $this->tiposPedidos());
    }

    /** El candado: dos ejecuciones no pueden pedirle a Meta dos veces. */
    public function test_no_se_puede_pedir_la_importacion_dos_veces(): void
    {
        $instancia = $this->instancia();
        $this->fingirMeta(esCoexistencia: true);

        (new IniciarSincronizacionCoexistencia($instancia->id))->handle(app(\App\Services\MetaWhatsAppService::class));
        (new IniciarSincronizacionCoexistencia($instancia->id))->handle(app(\App\Services\MetaWhatsAppService::class));

        $this->assertSame(1, CoexistenceSync::count());
        $this->assertSame(['smb_app_state_sync', 'history'], $this->tiposPedidos());
    }

    /**
     * La fila puede existir antes de pedir nada: la crea el volcado de un eco
     * del celular, que llega en cuanto el negocio responde y no tiene relación
     * con la importación. Bloquear por la existencia de la fila dejaba al
     * cliente sin historial y sin que nadie se enterase.
     */
    public function test_una_fila_creada_por_un_eco_no_bloquea_la_importacion(): void
    {
        $instancia = $this->instancia();
        $this->fingirMeta(esCoexistencia: true);

        // Lo que haría el volcado de un eco: fila sin `requested_at`.
        CoexistenceSync::create(['instance_id' => $instancia->id]);

        (new IniciarSincronizacionCoexistencia($instancia->id))->handle(app(\App\Services\MetaWhatsAppService::class));

        $sync = CoexistenceSync::where('instance_id', $instancia->id)->first();

        $this->assertSame(1, CoexistenceSync::count());
        $this->assertNotNull($sync->requested_at);
        $this->assertSame(['smb_app_state_sync', 'history'], $this->tiposPedidos());
    }

    /** Si Meta rechaza las dos peticiones queda constancia, no un silencio. */
    public function test_si_meta_rechaza_las_dos_peticiones_queda_como_fallida(): void
    {
        $instancia = $this->instancia();

        Http::fake([
            '*/smb_app_data' => Http::response(['error' => ['message' => 'no']], 400),
            '*' => Http::response(['id' => '1', 'is_on_biz_app' => true], 200),
        ]);

        (new IniciarSincronizacionCoexistencia($instancia->id))->handle(app(\App\Services\MetaWhatsAppService::class));

        $sync = CoexistenceSync::where('instance_id', $instancia->id)->first();

        $this->assertSame(CoexistenceSync::FALLIDA, $sync->status);
        $this->assertNotNull($sync->completed_at);
    }

    /** La ventana de 24 horas se mide desde que se pidió, no desde la conexión. */
    public function test_la_ventana_se_cierra_a_las_24_horas(): void
    {
        $instancia = $this->instancia();

        $sync = CoexistenceSync::create([
            'instance_id'  => $instancia->id,
            'status'       => CoexistenceSync::IMPORTANDO,
            'requested_at' => now()->subHours(23),
        ]);

        $this->assertTrue($sync->ventanaAbierta());

        $sync->update(['requested_at' => now()->subHours(25)]);

        $this->assertFalse($sync->fresh()->ventanaAbierta());
    }

    // ------------------------------------------------------------- utilidades

    /** Los `sync_type` que se le pidieron a Meta, en orden. */
    private function tiposPedidos(): array
    {
        $tipos = [];

        foreach (Http::recorded() as [$peticion, $_]) {
            if (str_contains($peticion->url(), 'smb_app_data')) {
                $tipos[] = $peticion->data()['sync_type'] ?? null;
            }
        }

        return $tipos;
    }

    private function fingirMeta(bool $esCoexistencia): void
    {
        Http::fake([
            '*/smb_app_data' => Http::sequence()
                ->push(['messaging_product' => 'whatsapp', 'request_id' => 'req-smb_app_state_sync', 'success' => true])
                ->push(['messaging_product' => 'whatsapp', 'request_id' => 'req-history', 'success' => true]),
            '*' => Http::response([
                'id'            => '1247515825107349',
                'is_on_biz_app' => $esCoexistencia,
                'platform_type' => 'CLOUD_API',
            ], 200),
        ]);
    }

    private function instancia(): Instance
    {
        $company = Company::create(['name' => 'Fibra XYZ', 'slug' => 'fibra-xyz', 'active' => true]);

        return Instance::create([
            'company_id'           => $company->id,
            'uuid'                 => (string) Str::uuid(),
            'name'                 => 'Principal',
            'phone_number_id'      => '1247515825107349',
            'waba_id'              => '1421384372768123',
            'display_phone_number' => '+57 318 1454747',
            'access_token'         => 'EAA-token-de-prueba',
            'type'                 => 'meta',
            'status'               => 'active',
            'active'               => true,
        ]);
    }
}
