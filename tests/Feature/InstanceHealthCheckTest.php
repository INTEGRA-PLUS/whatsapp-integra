<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vigilante de instancias caídas.
 *
 * El 2026-09-05 aparecieron cinco empresas cuyo token o número ya no existían
 * en Meta —la más antigua llevaba seis meses— y las cinco se mostraban
 * "Activa" en verde, porque `active` es una casilla nuestra y no una
 * comprobación. Se descubrieron de casualidad, revisando otra cosa.
 *
 * Lo que se protege aquí son las dos mitades del arreglo: que la caída se
 * detecte, y que el aviso no se convierta en ruido diario — porque una alerta
 * que se repite todos los días es otra forma de que nadie la mire.
 */
class InstanceHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_instancia_viva_queda_marcada_ok(): void
    {
        Http::fake(['*' => Http::response(['id' => '123', 'display_phone_number' => '+57 300'], 200)]);
        $instance = $this->instancia();

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $instance->refresh();
        $this->assertSame('ok', $instance->health_status);
        $this->assertNotNull($instance->health_checked_at);
        $this->assertNull($instance->health_error);
    }

    /** La caída se detecta y el motivo se guarda para no repetir la consulta. */
    public function test_una_instancia_muerta_se_marca_y_guarda_el_motivo(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => "Unsupported get request. Object with ID '975117929018923' does not exist"],
        ], 400)]);

        $instance = $this->instancia();

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $instance->refresh();
        $this->assertSame('unreachable', $instance->health_status);
        $this->assertStringContainsString('does not exist', $instance->health_error);
    }

    /** Y se avisa a quien puede arreglarlo: los admins de esa empresa. */
    public function test_avisa_a_los_admins_de_la_empresa(): void
    {
        Notification::fake();
        Http::fake(['*' => Http::response(['error' => ['message' => 'token inválido']], 400)]);

        $instance = $this->instancia();
        $admin = $this->admin($instance->company_id);

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        Notification::assertSentTo($admin, SystemNotification::class);
    }

    /**
     * El aviso NO se repite mientras siga caída.
     *
     * Repetirlo cada día lo convierte en ruido, y el ruido es justo lo que hizo
     * que nadie mirara las cinco que ya llevaban meses caídas.
     */
    public function test_no_repite_el_aviso_al_dia_siguiente(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'token inválido']], 400)]);

        $instance = $this->instancia();
        $admin = $this->admin($instance->company_id);

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        // Segunda vuelta: sigue caída, pero el estado ya no cambia.
        Notification::fake();
        $this->artisan('whatsapp:health-check')->assertSuccessful();

        Notification::assertNothingSentTo($admin);
    }

    /** Si se recupera, vuelve a ok y el siguiente fallo sí vuelve a avisar. */
    public function test_al_recuperarse_vuelve_a_ok(): void
    {
        $instance = $this->instancia(['health_status' => 'unreachable', 'health_error' => 'lo que fuera']);
        Http::fake(['*' => Http::response(['id' => '123'], 200)]);

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $instance->refresh();
        $this->assertSame('ok', $instance->health_status);
        $this->assertNull($instance->health_error);
    }

    /** Una instancia sin token no se consulta: se marca caída de una. */
    public function test_una_instancia_sin_token_se_marca_caida_sin_llamar_a_meta(): void
    {
        Http::fake();
        $instance = $this->instancia(['access_token' => null]);

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $this->assertSame('unreachable', $instance->refresh()->health_status);
        Http::assertNothingSent();
    }

    /** Las inactivas no se revisan: nadie espera mensajes de ellas. */
    public function test_las_inactivas_no_se_revisan(): void
    {
        Http::fake();
        $this->instancia(['active' => false]);

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * El bug del 14-sep-2026: una cuenta de Instagram sana salía «Sin conexión».
     *
     * Instagram no tiene `phone_number_id` y nunca lo tendrá, pero el
     * health-check se lo exigía a todas las instancias por igual. Resultado:
     * @integracolombiasas recibía mensajes con un cartel rojo encima diciendo
     * «Meta no responde por esta cuenta. No entran ni salen mensajes». Se vio
     * preparando el screencast del App Review, delante de la pantalla que iba a
     * ver el revisor de Meta.
     */
    public function test_una_cuenta_de_instagram_configurada_queda_ok(): void
    {
        $instance = $this->instanciaDeInstagram();

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $instance->refresh();
        $this->assertSame('ok', $instance->health_status);
        $this->assertNull($instance->health_error);
    }

    /** Y no se le pregunta a Graph por ella: esa consulta miente demasiado. */
    public function test_a_instagram_no_se_le_consulta_graph(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Unsupported request - method type: get']], 400)]);

        $instance = $this->instanciaDeInstagram();

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $instance->refresh();
        $this->assertSame('ok', $instance->health_status);
        Http::assertNothingSent();
    }

    /** Lo que sí la tumba es faltarle el token o el identificador de cuenta. */
    public function test_una_cuenta_de_instagram_sin_token_se_marca_caida(): void
    {
        $instance = $this->instanciaDeInstagram(['access_token' => null]);

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $instance->refresh();
        $this->assertSame('unreachable', $instance->health_status);
        $this->assertStringContainsString('Instagram', (string) $instance->health_error);
    }

    /**
     * Conectado no es lo mismo que poder enviar.
     *
     * Una cuenta sana a la que se le venció la tarjeta del portafolio responde
     * a todo y no entrega nada. El medio de pago no se puede leer —Meta le
     * niega `primary_funding_id` a quien no es BSP— pero sí la consecuencia, y
     * llega antes de que empiecen a rebotar las facturas.
     *
     * @test
     */
    public function una_cuenta_bloqueada_para_enviar_se_marca_y_dice_quien_falla(): void
    {
        $this->fakeConSalud('BLOCKED', [
            ['entity_type' => 'WABA', 'can_send_message' => 'AVAILABLE'],
            ['entity_type' => 'BUSINESS', 'can_send_message' => 'BLOCKED', 'errors' => [
                ['description' => 'El método de pago no es válido'],
            ]],
        ]);

        $instance = $this->instancia();

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $instance->refresh();

        $this->assertSame('ok', $instance->health_status, 'La conexión está bien: lo que falla es el envío.');
        $this->assertSame('BLOCKED', $instance->puede_enviar);
        $this->assertStringContainsString('El portafolio del negocio', $instance->puede_enviar_motivo);
        $this->assertStringContainsString('método de pago', $instance->puede_enviar_motivo);
        $this->assertNotNull($instance->puede_enviar_visto_at);
    }

    /** Y cuando todo está disponible, queda dicho que sí puede. */
    public function test_una_cuenta_sana_queda_marcada_como_que_puede_enviar(): void
    {
        $this->fakeConSalud('AVAILABLE', [
            ['entity_type' => 'WABA', 'can_send_message' => 'AVAILABLE'],
            ['entity_type' => 'BUSINESS', 'can_send_message' => 'AVAILABLE'],
        ]);

        $instance = $this->instancia();

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $this->assertSame('AVAILABLE', $instance->refresh()->puede_enviar);
        $this->assertNull($instance->puede_enviar_motivo);
    }

    /**
     * El aviso de bloqueo tampoco se repite cada día.
     *
     * Es la misma razón que el de caída: una alarma diaria por algo que ya se
     * sabe se aprende a ignorar, y con ella se ignoran las que sí son nuevas.
     *
     * @test
     */
    public function el_aviso_de_bloqueo_no_se_repite(): void
    {
        Notification::fake();

        $this->fakeConSalud('BLOCKED', [
            ['entity_type' => 'BUSINESS', 'can_send_message' => 'BLOCKED'],
        ]);

        $instance = $this->instancia();
        $this->admin($instance->company_id);

        $this->artisan('whatsapp:health-check')->assertSuccessful();
        Notification::assertSentTimes(SystemNotification::class, 1);

        $this->artisan('whatsapp:health-check')->assertSuccessful();
        Notification::assertSentTimes(SystemNotification::class, 1);
    }

    /**
     * Si Meta no contesta por la salud, no se inventa un bloqueo.
     *
     * Pintar «no puede enviar» porque una consulta falló sería mandar a un
     * cliente a revisar su tarjeta por nada.
     *
     * @test
     */
    public function sin_respuesta_de_meta_no_se_inventa_un_bloqueo(): void
    {
        Http::fake([
            '*health_status*' => Http::response(['error' => ['message' => 'nope']], 400),
            '*' => Http::response(['id' => '123'], 200),
        ]);

        $instance = $this->instancia();

        $this->artisan('whatsapp:health-check')->assertSuccessful();

        $this->assertSame('ok', $instance->refresh()->health_status);
        $this->assertNull($instance->puede_enviar);
    }

    /** Respuestas de Meta: el número vivo y la salud que se le pida. */
    private function fakeConSalud(string $general, array $entidades): void
    {
        Http::fake(function ($request) use ($general, $entidades) {
            if (str_contains(urldecode($request->url()), 'health_status')) {
                return Http::response(['health_status' => [
                    'can_send_message' => $general,
                    'entities' => $entidades,
                ]], 200);
            }

            return Http::response(['id' => '123', 'display_phone_number' => '+57 300'], 200);
        });
    }

    private function instanciaDeInstagram(array $extra = []): Instance
    {
        return $this->instancia(array_merge([
            'channel' => Instance::CANAL_INSTAGRAM,
            'phone_number_id' => null,
            'waba_id' => null,
            'display_phone_number' => null,
            'external_account_id' => '17841458371253418',
            'access_token' => 'IGA-token-largo',
            'name' => 'integracolombiasas',
        ], $extra));
    }

    private function instancia(array $extra = []): Instance
    {
        $company = Company::create([
            'name' => 'Fibra ' . Str::random(4),
            'slug' => 'fibra-' . Str::random(6),
            'active' => true,
        ]);

        return Instance::create(array_merge([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea principal',
            'phone_number_id' => '975117929018923',
            'waba_id' => '25479282995026673',
            'display_phone_number' => '+57 312 4579765',
            'access_token' => 'EAA-token',
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
        ], $extra));
    }

    private function admin(int $companyId): User
    {
        return User::create([
            'company_id' => $companyId,
            'name' => 'Admin',
            'email' => 'admin-' . Str::random(6) . '@fibra.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);
    }
}
