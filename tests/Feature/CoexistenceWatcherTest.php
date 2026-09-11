<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CoexistenceSync;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El vigilante de las importaciones de coexistencia.
 *
 * Meta marca el final del historial mandando la última fase al 100. Ese aviso a
 * veces no llega, y la importación se queda en 99% con todo el historial ya
 * dentro. Antes, a las 24 horas eso se cerraba como fallido y se le pedía al
 * cliente desconectar el número y repetir la conexión —con un técnico delante—
 * para recuperar algo que ya estaba guardado.
 */
class CoexistenceWatcherTest extends TestCase
{
    use RefreshDatabase;

    private Instance $instance;

    protected function setUp(): void
    {
        parent::setUp();

        $company = Company::create([
            'name' => 'Transintermet',
            'slug' => 'transintermet-'.Str::random(6),
            'active' => true,
        ]);

        $this->instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Transintermet',
            'phone_number_id' => '2253367844691746',
            'waba_id' => '268497990443087',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        User::create([
            'name' => 'Admin',
            'email' => Str::random(8).'@test.local',
            'password' => bcrypt('secret'),
            'company_id' => $company->id,
            'active' => true,
        ]);
    }

    /**
     * El caso real: 70.035 mensajes dentro y la barra clavada en 99%.
     */
    public function test_una_importacion_entregada_se_cierra_sola_aunque_falte_el_aviso_de_meta(): void
    {
        $sync = $this->sync([
            'status' => CoexistenceSync::IMPORTANDO,
            'phase' => 2,
            'progress' => 99,
            'requested_at' => now()->subHours(3),
            'first_chunk_at' => now()->subHours(2),
            'last_chunk_at' => now()->subHours(2),
            'messages_imported' => 70035,
            'conversations_touched' => 9890,
            'contacts_imported' => 1339,
        ]);

        $this->artisan('coexistencia:vigilar --quiet-notifications')->assertSuccessful();

        $sync->refresh();

        $this->assertSame(CoexistenceSync::COMPLETADA, $sync->status);
        $this->assertSame(100, $sync->porcentajeGlobal());
        $this->assertNotNull($sync->completed_at);
        $this->assertNull($sync->error_message);
    }

    public function test_una_importacion_que_sigue_recibiendo_lotes_no_se_toca(): void
    {
        $sync = $this->sync([
            'status' => CoexistenceSync::IMPORTANDO,
            'phase' => 2,
            'progress' => 60,
            'requested_at' => now()->subHours(1),
            'first_chunk_at' => now()->subMinutes(50),
            // Un lote hace dos minutos: sigue viva.
            'last_chunk_at' => now()->subMinutes(2),
            'messages_imported' => 12000,
        ]);

        $this->artisan('coexistencia:vigilar --quiet-notifications')->assertSuccessful();

        $this->assertSame(CoexistenceSync::IMPORTANDO, $sync->refresh()->status);
    }

    /**
     * Sin haber llegado a la última fase, el silencio sí es un problema: falta
     * historial de verdad y rehacer la conexión sirve para algo.
     */
    public function test_una_importacion_a_medias_en_fase_temprana_sigue_venciendo(): void
    {
        $sync = $this->sync([
            'status' => CoexistenceSync::IMPORTANDO,
            'phase' => 0,
            'progress' => 40,
            'requested_at' => now()->subHours(30),
            'first_chunk_at' => now()->subHours(29),
            'last_chunk_at' => now()->subHours(29),
            'messages_imported' => 120,
            'conversations_touched' => 30,
            'contacts_imported' => 12,
        ]);

        $this->artisan('coexistencia:vigilar --quiet-notifications')->assertSuccessful();

        $sync->refresh();

        $this->assertSame(CoexistenceSync::FALLIDA, $sync->status);

        // Y dice lo que sí entró antes de pedir que se rehaga nada.
        $this->assertStringContainsString('120 mensajes', $sync->error_message);
        $this->assertStringContainsString('se conserva', $sync->error_message);
    }

    /**
     * Vencida pero en la última fase: es entregada, no fallida. Es el mismo caso
     * de Transintermet si nadie mira la pantalla en 24 horas.
     */
    public function test_vencer_en_la_ultima_fase_no_se_reporta_como_fallo(): void
    {
        $sync = $this->sync([
            'status' => CoexistenceSync::IMPORTANDO,
            'phase' => 2,
            'progress' => 99,
            'requested_at' => now()->subHours(26),
            'first_chunk_at' => now()->subHours(25),
            'last_chunk_at' => now()->subHours(25),
            'messages_imported' => 70035,
        ]);

        Notification::fake();

        $this->artisan('coexistencia:vigilar')->assertSuccessful();

        $this->assertSame(CoexistenceSync::COMPLETADA, $sync->refresh()->status);

        // Y no se molesta a nadie: terminar bien no es un suceso.
        Notification::assertNothingSent();
    }

    private function sync(array $datos): CoexistenceSync
    {
        return CoexistenceSync::create(array_merge([
            'instance_id' => $this->instance->id,
        ], $datos));
    }
}
