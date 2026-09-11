<?php

namespace Tests\Feature;

use App\Console\Commands\FallbackTemplateStatus;
use App\Models\Company;
use App\Models\Instance;
use App\Services\WhatsAppFallbackTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * La tarea horaria de la plantilla de respaldo, con clientes desconectados.
 *
 * `whatsapp:fallback-template` corre cada hora. Dos instancias —Netencia e
 * INTERFIBRA AMAGA— llevaban desde el 11-ago-2026 haciéndola fallar: sus WABAs
 * dejaron de estar compartidas con la app y Meta responde *"does not exist or
 * missing permissions"* a cualquier consulta. El comando salía con código 1 y
 * el planificador registraba un error por hora: **371 en el log**, que tapaban
 * cualquier fallo nuevo. De hecho tapaban tanto que, tras un despliegue, hubo
 * que descartarlos uno a uno para saber si algo se había roto de verdad.
 *
 * El health-check ya marca esas instancias como `unreachable`, así que aquí no
 * hay nada que preguntar: se omiten, se avisa una vez en la salida, y el
 * comando no las cuenta como fallo suyo. Lo que sí sigue fallando es una
 * instancia sana sin plantilla utilizable, que es el problema que esta tarea
 * existe para detectar.
 */
class FallbackTemplateOmiteInalcanzablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_consulta_a_meta_por_las_instancias_inalcanzables(): void
    {
        $this->instancia('Netencia', 'unreachable');

        // Si el comando intentara resolver su plantilla, este mock lo delataría.
        $servicio = Mockery::mock(WhatsAppFallbackTemplateService::class);
        $servicio->shouldNotReceive('ensure');
        $this->app->instance(WhatsAppFallbackTemplateService::class, $servicio);

        $this->artisan('whatsapp:fallback-template')
            ->expectsOutputToContain('omitidas porque Meta no responde')
            ->assertSuccessful();
    }

    /** Una instancia sana sin respaldo sí tiene que hacer fallar la tarea. */
    public function test_una_instancia_sana_sin_respaldo_sigue_fallando(): void
    {
        $this->instancia('Cliente sano', 'ok');

        $servicio = Mockery::mock(WhatsAppFallbackTemplateService::class);
        $servicio->shouldReceive('ensure')->once()->andReturn([
            'status' => 'error',
            'name' => 'aviso_automatico_cliente',
            'language' => 'es',
            'last_error' => 'lo que sea',
        ]);
        $this->app->instance(WhatsAppFallbackTemplateService::class, $servicio);

        $this->artisan('whatsapp:fallback-template')
            ->expectsOutputToContain('sin respaldo utilizable')
            ->assertFailed();
    }

    /** Y una inalcanzable no arrastra a la sana: la sana se consulta igual. */
    public function test_la_inalcanzable_no_impide_revisar_a_las_demas(): void
    {
        $this->instancia('Netencia', 'unreachable');
        $this->instancia('Cliente sano', 'ok');

        $servicio = Mockery::mock(WhatsAppFallbackTemplateService::class);
        $servicio->shouldReceive('ensure')->once()->andReturn([
            'status' => WhatsAppFallbackTemplateService::STATUS_APPROVED,
            'name' => 'aviso_automatico_cliente',
            'language' => 'es',
        ]);
        $this->app->instance(WhatsAppFallbackTemplateService::class, $servicio);

        $this->artisan('whatsapp:fallback-template')->assertSuccessful();
    }

    private function instancia(string $empresa, string $salud): Instance
    {
        $company = Company::create([
            'name' => $empresa,
            'slug' => Str::slug($empresa),
            'active' => true,
        ]);

        return Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Instancia de '.$empresa,
            'phone_number_id' => 'pnid-'.Str::slug($empresa),
            'waba_id' => 'waba-'.Str::slug($empresa),
            'access_token' => 'token-de-prueba',
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
            'health_status' => $salud,
        ]);
    }
}
