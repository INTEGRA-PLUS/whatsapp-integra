<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\AvisosDePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los avisos de plan.
 *
 * El sistema sabía desde el principio quién se había pasado y a quién se le
 * acababa el crédito, pero había que entrar al panel a mirarlo. Lo que se
 * protege aquí no es que el aviso salga —eso se ve— sino las dos formas de que
 * un aviso deje de servir:
 *
 * 1. **Que repita.** Una campana que suena cada día por lo mismo se aprende a
 *    ignorar, y con ella se ignoran los avisos que sí son nuevos.
 * 2. **Que se pierda.** Un aviso que se apunta antes de mandarse desaparece si
 *    el envío falla, y nadie se entera nunca.
 */
class AvisosDePlanTest extends TestCase
{
    use RefreshDatabase;

    /** Pasarse de agentes se avisa, con el número y el plan que le tocaría. */
    public function test_avisa_de_quien_se_paso_de_su_plan(): void
    {
        $company = $this->empresa(['plan' => 'basico']);
        $this->agentes($company, config('planes.crm.basico.agentes') + 1);

        $avisos = collect(AvisosDePlan::calcular())->where('company.id', $company->id);
        $aviso = $avisos->firstWhere('motivo', 'plan_corto');

        $this->assertNotNull($aviso, 'Tendría que avisar de que se pasó.');
        $this->assertStringContainsString('agentes', $aviso['cuerpo']);
        $this->assertStringContainsString('No se le ha cortado nada', $aviso['cuerpo']);
    }

    /**
     * La sugerencia mira las tres cosas, incluidas las líneas.
     *
     * Con las líneas fuera, una empresa con dos líneas en un plan de una salía
     * avisada de que se pasó **y con la sugerencia de quedarse donde está**:
     * «tiene 2 líneas de 1, le correspondería Básico». Salió en la primera
     * pasada contra datos reales, con GLOBAL CONEXIT. Una recomendación que se
     * contradice enseña a no leerlas.
     */
    public function test_la_sugerencia_no_se_contradice_con_el_aviso(): void
    {
        $company = $this->empresa(['plan' => 'basico']);
        $this->lineas($company, config('planes.crm.basico.lineas') + 1);

        $aviso = collect(AvisosDePlan::calcular())->firstWhere('motivo', 'plan_corto');

        $this->assertNotNull($aviso);
        $this->assertStringContainsString('líneas', $aviso['cuerpo']);
        $this->assertStringNotContainsString(
            'Le correspondería Básico',
            $aviso['cuerpo'],
            'No puede sugerir el mismo plan del que se acaba de pasar.'
        );
    }

    /**
     * Las empresas internas no generan avisos.
     *
     * `PRUEBAS` y `Meta App Review` existen para probar lo que aún no se vende,
     * así que se pasan de todo por definición. Avisar de ellas llenaría la
     * campana de ruido y enseñaría a ignorarla.
     */
    public function test_las_internas_no_generan_avisos(): void
    {
        $company = $this->empresa(['plan' => 'basico', 'interna' => true]);
        $this->agentes($company, 9);

        $this->assertSame([], AvisosDePlan::calcular());
    }

    /**
     * Quien paga IA y no la usa también se avisa.
     *
     * Es el que más caro sale callado: un cliente que paga por algo que no usa
     * es un cliente que se va a dar de baja, y llamarle a tiempo lo evita.
     */
    public function test_avisa_de_quien_paga_ia_y_no_la_usa(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(14));

        $company = $this->empresa(['plan' => 'basico', 'ia' => 'esencial']);

        $aviso = collect(AvisosDePlan::calcular())->firstWhere('motivo', 'ia_sin_usar');

        $this->assertNotNull($aviso);
        $this->assertStringContainsString('IA Esencial', $aviso['cuerpo']);
    }

    /**
     * Pero no en los primeros días del mes, cuando nadie ha gastado todavía.
     *
     * Avisar el día 2 de que un cliente «no usa la IA» es ruido garantizado: no
     * la ha usado nadie. Y el ruido es lo que hace que se ignore la campana.
     */
    public function test_no_avisa_de_ia_sin_usar_al_principio_del_mes(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(2));

        $this->empresa(['plan' => 'basico', 'ia' => 'esencial']);

        $this->assertNull(collect(AvisosDePlan::calcular())->firstWhere('motivo', 'ia_sin_usar'));
    }

    /** Sin complemento no se avisa de IA: no hay nada que consumir. */
    public function test_sin_complemento_no_hay_avisos_de_ia(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(14));

        $this->empresa(['plan' => 'basico', 'ia' => 'ninguno']);

        $motivos = array_column(AvisosDePlan::calcular(), 'motivo');

        $this->assertNotContains('ia_sin_usar', $motivos);
        $this->assertNotContains('credito_agotado', $motivos);
    }

    /** El mismo aviso no se manda dos veces. */
    public function test_no_repite_el_mismo_aviso(): void
    {
        Notification::fake();

        $company = $this->empresa(['plan' => 'basico']);
        $this->agentes($company, 9);
        $this->master();

        $this->artisan('planes:avisar')->assertSuccessful();
        Notification::assertSentTimes(SystemNotification::class, 1);

        // Segunda pasada: nada nuevo que contar.
        $this->artisan('planes:avisar')->assertSuccessful();
        Notification::assertSentTimes(SystemNotification::class, 1);
    }

    /**
     * Pero si cambia EN QUÉ se pasó, vuelve a avisar.
     *
     * Primero se pasa de agentes y un mes después también de contactos: son dos
     * conversaciones distintas con el cliente, y quedarse con la primera hace
     * que la segunda no se tenga nunca.
     */
    public function test_vuelve_a_avisar_si_se_pasa_de_otra_cosa(): void
    {
        Notification::fake();

        $company = $this->empresa(['plan' => 'basico']);
        $this->agentes($company, 9);
        $this->master();

        $this->artisan('planes:avisar')->assertSuccessful();
        Notification::assertSentTimes(SystemNotification::class, 1);

        $this->contactos($company, config('planes.crm.basico.contactos') + 1);

        $this->artisan('planes:avisar')->assertSuccessful();
        Notification::assertSentTimes(SystemNotification::class, 2);
    }

    /**
     * Sin nadie a quien avisar NO se apunta nada.
     *
     * Si se apuntara, el día que exista un master los avisos ya estarían
     * marcados como dados y no saldría ninguno. El fallo sería silencioso y
     * permanente.
     */
    public function test_sin_master_no_se_apunta_nada(): void
    {
        Notification::fake();

        $company = $this->empresa(['plan' => 'basico']);
        $this->agentes($company, 9);

        $this->artisan('planes:avisar')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame([], (array) data_get($company->refresh()->settings, 'avisos_de_plan', []));
    }

    /**
     * Apuntar el aviso no borra lo que otras funciones guarden en `settings`.
     *
     * `settings` es un cajón compartido. Asignarlo entero en vez de fusionarlo
     * es el error que ya costó una vez con `instances.meta`, y no deja rastro:
     * la otra función simplemente deja de funcionar.
     */
    public function test_apuntar_no_pisa_lo_que_guarden_otros(): void
    {
        $company = $this->empresa(['settings' => ['otra_cosa' => 'no me borres']]);

        AvisosDePlan::apuntar($company, 'plan_corto:agentes:basico');

        $this->assertSame('no me borres', data_get($company->refresh()->settings, 'otra_cosa'));
        $this->assertTrue(AvisosDePlan::yaAvisado($company->refresh(), 'plan_corto:agentes:basico'));
    }

    private function empresa(array $extra = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Fibra '.uniqid(),
            'slug' => 'fibra-'.uniqid(),
            'active' => true,
        ], $extra));
    }

    private function agentes(Company $company, int $cuantos): void
    {
        for ($i = 0; $i < $cuantos; $i++) {
            User::create([
                'company_id' => $company->id,
                'name' => 'Agente '.$i,
                'email' => Str::uuid().'@x.test',
                'password' => bcrypt('secreto123'),
                'role' => 'agent',
                'active' => true,
            ]);
        }
    }

    private function lineas(Company $company, int $cuantas): void
    {
        for ($i = 0; $i < $cuantas; $i++) {
            \App\Models\Instance::create([
                'company_id' => $company->id,
                'uuid' => (string) Str::uuid(),
                'name' => 'Línea '.$i,
                'phone_number_id' => 'T-'.Str::random(10),
                'waba_id' => 'W-'.Str::random(10),
                'type' => 'meta',
                'status' => 'active',
                'active' => true,
                'access_token' => '',
            ]);
        }
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

    /** Un master de verdad: sin rol Spatie, `isMaster()` devuelve false. */
    private function master(): User
    {
        $company = $this->empresa(['interna' => true]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Master',
            'email' => Str::uuid().'@x.test',
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
