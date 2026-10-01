<?php

namespace Tests\Feature;

use App\Console\Commands\RevisarIa;
use App\Models\Company;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La revisión diaria de la IA de la plataforma.
 *
 * 28-sep-2026: Ollama llevaba dos días rechazando todo por el pago vencido y se
 * supo porque un cliente dijo que el resumen no servía.
 */
class RevisarIaTest extends TestCase
{
    use RefreshDatabase;

    private User $master;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.resumen.webhook_url' => 'https://n8n.test/webhook/resumen',
            'services.resumen.api_key' => 'clave',
        ]);
        Cache::forget(RevisarIa::ESTADO);
        Notification::fake();

        $empresa = Company::create(['name' => 'Plataforma', 'slug' => 'plataforma-'.Str::random(4), 'active' => true]);
        $this->master = User::create([
            'company_id' => $empresa->id, 'name' => 'Master', 'email' => Str::random(6).'@test.local',
            'password' => bcrypt('x'), 'role' => 'master', 'active' => true,
        ]);
    }

    public function test_si_ollama_rechaza_por_pago_avisa_a_los_master_con_la_causa(): void
    {
        $this->iaResponde(500, ['message' => 'your subscription payment is past due']);

        $this->artisan('ia:revisar')->assertSuccessful();

        Notification::assertSentTo($this->master, SystemNotification::class, fn ($n) => str_contains($n->title.$n->body, 'pago de la suscripción está vencido'));
    }

    /** La misma alerta cada día es la que se aprende a ignorar. */
    public function test_no_repite_el_aviso_mientras_siga_caida(): void
    {
        $this->iaResponde(500, ['message' => 'Error in workflow']);

        $this->artisan('ia:revisar');
        $this->artisan('ia:revisar');

        Notification::assertSentToTimes($this->master, SystemNotification::class, 1);
    }

    public function test_avisa_cuando_vuelve(): void
    {
        Cache::forever(RevisarIa::ESTADO, 'caida');
        $this->iaResponde(200, ['resumen' => 'El cliente preguntó por su pago y quedó registrado.', 'puntos' => [], 'pendientes' => []]);

        $this->artisan('ia:revisar')->assertSuccessful();

        Notification::assertSentTo($this->master, SystemNotification::class, fn ($n) => str_contains($n->title.$n->body, 'volvió a responder'));
        $this->assertSame('ok', Cache::get(RevisarIa::ESTADO));
    }

    public function test_si_todo_va_bien_no_avisa(): void
    {
        $this->iaResponde(200, ['resumen' => 'Todo bien.', 'puntos' => [], 'pendientes' => []]);

        $this->artisan('ia:revisar')->assertSuccessful();

        Notification::assertNothingSent();
    }

    private function iaResponde(int $estado, array $cuerpo): void
    {
        Http::fake(['n8n.test/*' => Http::response($cuerpo, $estado)]);
    }
}
