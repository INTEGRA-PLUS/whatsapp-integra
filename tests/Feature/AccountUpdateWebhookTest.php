<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El aviso de Meta cuando una cuenta se desconecta.
 *
 * El 9-sep-2026 había **doce instancias caídas** de cuarenta y nueve: Redes
 * Tevesat con 5.318 conversaciones callada desde agosto, TELNEXT con 2.273
 * desde julio, y alguna desde marzo. Ninguna dio señal: el único rastro era la
 * tarea horaria de la plantilla de respaldo fallando en el log, y para verlo
 * había que ir a buscarlo.
 *
 * Meta avisa de esto en el momento, por el webhook `account_update`, y hasta
 * ahora el campo se descartaba sin leerlo. En coexistencia basta con que el
 * cliente cambie de teléfono o reinstale la app para que su número salga de
 * Cloud API, así que no es un caso raro.
 *
 * Se marca el estado que ya pinta la tarjeta roja en Instancias y se avisa a
 * los administradores de esa empresa, igual que hace el chequeo diario, pero el
 * mismo día en que ocurre.
 */
class AccountUpdateWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_desconexion_marca_la_instancia_y_avisa_a_los_admins(): void
    {
        Notification::fake();
        [$instancia, $admin] = $this->instanciaConAdmin();

        $this->postSignedWebhook($this->payload($instancia->waba_id, 'ACCOUNT_OFFBOARDED'))
            ->assertOk();

        $instancia->refresh();
        $this->assertSame('unreachable', $instancia->health_status);
        $this->assertStringContainsString('ACCOUNT_OFFBOARDED', $instancia->health_error);
        $this->assertNotNull($instancia->health_checked_at);

        Notification::assertSentTo($admin, SystemNotification::class);
    }

    /** `PARTNER_REMOVED` es la otra cara: el cliente nos quitó el acceso. */
    public function test_tambien_reconoce_que_nos_quitaron_el_acceso(): void
    {
        Notification::fake();
        [$instancia] = $this->instanciaConAdmin();

        $this->postSignedWebhook($this->payload($instancia->waba_id, 'PARTNER_REMOVED'))->assertOk();

        $this->assertSame('unreachable', $instancia->fresh()->health_status);
    }

    /** Y cuando vuelve, vuelve: sin esperar al chequeo del día siguiente. */
    public function test_una_reconexion_devuelve_la_instancia_a_la_normalidad(): void
    {
        Notification::fake();
        [$instancia] = $this->instanciaConAdmin();
        $instancia->update(['health_status' => 'unreachable', 'health_error' => 'lo que fuera']);

        $this->postSignedWebhook($this->payload($instancia->waba_id, 'ACCOUNT_RECONNECTED'))->assertOk();

        $instancia->refresh();
        $this->assertSame('ok', $instancia->health_status);
        $this->assertNull($instancia->health_error);
    }

    /**
     * Avisar dos veces de lo mismo es lo que hace que nadie mire los avisos:
     * Meta reintenta sus webhooks, y el segundo no debe volver a notificar.
     */
    public function test_no_avisa_dos_veces_de_la_misma_caida(): void
    {
        Notification::fake();
        [$instancia, $admin] = $this->instanciaConAdmin();

        $this->postSignedWebhook($this->payload($instancia->waba_id, 'ACCOUNT_OFFBOARDED'))->assertOk();
        $this->postSignedWebhook($this->payload($instancia->waba_id, 'ACCOUNT_OFFBOARDED'))->assertOk();

        Notification::assertSentToTimes($admin, SystemNotification::class, 1);
    }

    /** Un evento que no conocemos se registra y no rompe el lote. */
    public function test_un_evento_desconocido_no_rompe_el_webhook(): void
    {
        Notification::fake();
        [$instancia] = $this->instanciaConAdmin();

        $this->postSignedWebhook($this->payload($instancia->waba_id, 'ALGO_QUE_META_INVENTE'))
            ->assertOk();

        $this->assertSame('ok', $instancia->fresh()->health_status);
    }

    /** Un aviso sobre una WABA que no es nuestra se ignora sin más. */
    public function test_una_waba_desconocida_se_ignora(): void
    {
        Notification::fake();
        [$instancia] = $this->instanciaConAdmin();

        $this->postSignedWebhook($this->payload('waba-de-otro', 'ACCOUNT_OFFBOARDED'))->assertOk();

        $this->assertSame('ok', $instancia->fresh()->health_status);
        Notification::assertNothingSent();
    }

    private function payload(string $wabaId, string $evento): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $wabaId,
                'time' => now()->timestamp,
                'changes' => [[
                    'field' => 'account_update',
                    'value' => ['event' => $evento],
                ]],
            ]],
        ];
    }

    /**
     * @return array{0: Instance, 1: User}
     */
    private function instanciaConAdmin(): array
    {
        $company = Company::create(['name' => 'Cliente', 'slug' => 'cliente', 'active' => true]);

        $admin = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => 'admin@cliente.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);

        $instancia = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea principal',
            'phone_number_id' => 'pnid-1',
            'waba_id' => 'waba-1',
            'display_phone_number' => '+57 300 000 0000',
            'access_token' => 'token',
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
            'health_status' => 'ok',
        ]);

        return [$instancia, $admin];
    }
}
