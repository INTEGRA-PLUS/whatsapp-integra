<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La renovación de los tokens de Instagram.
 *
 * Los de WhatsApp que usamos son de usuario del sistema y no expiran. Los de
 * Instagram duran 60 días, así que sin esta tarea cada cuenta conectada se cae
 * sola a los dos meses y nos enteramos por la llamada del cliente.
 */
class RenovarTokensDeInstagramTest extends TestCase
{
    use RefreshDatabase;

    public function test_renueva_la_que_esta_por_caducar(): void
    {
        Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response([
            'access_token' => 'el-token-renovado',
            'expires_in' => 5_184_000,
        ])]);

        $linea = $this->lineaDeInstagram(caducaEn: 3);

        $this->artisan('instagram:renovar-tokens')->assertSuccessful();

        $linea->refresh();
        $this->assertSame('el-token-renovado', $linea->access_token);
        $this->assertEqualsWithDelta(60, now()->diffInDays($linea->token_expires_at), 1);
    }

    public function test_no_toca_la_que_todavia_tiene_margen(): void
    {
        Http::fake();

        $linea = $this->lineaDeInstagram(caducaEn: 45);

        $this->artisan('instagram:renovar-tokens')->assertSuccessful();

        $this->assertSame('el-token-de-antes', $linea->refresh()->access_token);
        Http::assertNothingSent();
    }

    /**
     * Una línea de WhatsApp no tiene fecha de caducidad y no debe entrar aquí:
     * su token es de usuario del sistema y no se renueva por esta vía.
     */
    public function test_no_mira_las_lineas_de_whatsapp(): void
    {
        Http::fake();

        $this->lineaDeInstagram(caducaEn: 3, canal: Instance::CANAL_WHATSAPP, caduca: false);

        $this->artisan('instagram:renovar-tokens')->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * Una renovación fallida es una cuenta que va a dejar de funcionar en días.
     * Tiene que salir con error para que el scheduler lo note, en vez de pasar
     * en silencio.
     */
    public function test_si_meta_falla_el_comando_sale_con_error(): void
    {
        Http::fake(['graph.instagram.com/refresh_access_token*' => Http::response([
            'error' => ['message' => 'Error validating access token'],
        ], 400)]);

        $linea = $this->lineaDeInstagram(caducaEn: 2);

        $this->artisan('instagram:renovar-tokens')->assertFailed();

        // Y sobre todo: no se pisa el token bueno con nada.
        $this->assertSame('el-token-de-antes', $linea->refresh()->access_token);
    }

    private function lineaDeInstagram(int $caducaEn, string $canal = Instance::CANAL_INSTAGRAM, bool $caduca = true): Instance
    {
        $empresa = Company::create([
            'name' => 'Empresa',
            'slug' => 'e-'.Str::random(8),
            'active' => true,
        ]);

        return Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => '@cuenta',
            'channel' => $canal,
            'external_account_id' => $canal === Instance::CANAL_INSTAGRAM ? '1784140000'.random_int(1000, 9999) : null,
            'phone_number_id' => $canal === Instance::CANAL_WHATSAPP ? (string) random_int(1000000000000, 9999999999999) : null,
            'type' => 'meta',
            'active' => true,
            'access_token' => 'el-token-de-antes',
            'token_expires_at' => $caduca ? now()->addDays($caducaEn) : null,
        ]);
    }
}
