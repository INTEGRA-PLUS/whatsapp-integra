<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El ERP se autentica con la credencial de una línea que se apagó.
 *
 * Nac Technology, 23-sep-2026: al reconectar el número, Meta le dio un
 * `phone_number_id` y un WABA nuevos, la instancia vieja se apagó y el ERP
 * siguió configurado con ella. El API sólo acepta instancias activas, así que
 * cada factura habría recibido un 401 mientras el panel prometía que no hacía
 * falta tocar nada del otro lado.
 */
class CredencialApagadaDelErpTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_panel_avisa_si_el_erp_entra_por_una_linea_apagada(): void
    {
        [$empresa, $vieja] = $this->empresaReconectada();

        $this->actingAs($this->adminDe($empresa))
            ->get('/integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('credencialApagada.phone_number_id', $vieja->phone_number_id)
                ->where('credencialApagada.credencial', 'phone_number_id'));
    }

    /** Si el ERP ya entra por la activa, no hay nada que avisar. */
    public function test_no_avisa_si_la_ultima_credencial_es_de_una_activa(): void
    {
        [$empresa, , $nueva] = $this->empresaReconectada();
        $nueva->forceFill(['api_last_seen_at' => now(), 'api_last_seen_via' => 'token'])->save();

        $this->actingAs($this->adminDe($empresa))
            ->get('/integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('credencialApagada', null));
    }

    /** La línea apagada de otra empresa no es asunto de ésta. */
    public function test_no_mira_las_lineas_de_otra_empresa(): void
    {
        $this->empresaReconectada('vecina');
        $empresa = Company::create(['name' => 'Otra', 'slug' => 'otra-'.Str::random(5), 'active' => true]);

        $this->actingAs($this->adminDe($empresa))
            ->get('/integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('credencialApagada', null));
    }

    /** El 401 queda en el log con la empresa, que el log de acceso no guarda. */
    public function test_el_401_de_una_credencial_apagada_deja_rastro_con_la_empresa(): void
    {
        [$empresa, $vieja, $nueva] = $this->empresaReconectada();

        Log::shouldReceive('channel')->with('whatsapp')->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(fn ($mensaje, $contexto) => str_contains($mensaje, 'línea apagada')
            && $contexto['instance_id'] === $vieja->id
            && $contexto['company_id'] === $empresa->id
            && $contexto['linea_activa'] === $nueva->id);

        $this->withHeader('X-Instance-Token', $vieja->phone_number_id)
            ->getJson('/api/v1/config')
            ->assertStatus(401);
    }

    /** Un token que no es de nadie no se registra: sería guardar lo que alguien probó. */
    public function test_un_token_inventado_no_deja_rastro(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('warning')->never();

        $this->withHeader('X-Instance-Token', 'no-existe')
            ->getJson('/api/v1/config')
            ->assertStatus(401);
    }

    /**
     * @return array{0: Company, 1: Instance, 2: Instance}
     */
    private function empresaReconectada(string $sufijo = 'nac'): array
    {
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        $empresa = Company::create([
            'name' => 'Nac '.$sufijo,
            'slug' => $sufijo.'-'.Str::random(5),
            'active' => true,
        ]);

        $linea = fn (string $nombre, bool $activa) => Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => $nombre,
            'phone_number_id' => 'pnid-'.Str::random(8),
            'display_phone_number' => '+573124386315',
            'waba_id' => 'waba-'.Str::random(5),
            'type' => 'meta',
            'active' => $activa,
            'access_token' => 'token',
        ]);

        $vieja = $linea('principal', false);
        $vieja->forceFill(['api_last_seen_at' => now()->subDay(), 'api_last_seen_via' => 'phone_number_id'])->save();

        $nueva = $linea('Nac Technology', true);

        return [$empresa, $vieja, $nueva];
    }

    private function adminDe(Company $empresa): User
    {
        return User::create([
            'company_id' => $empresa->id, 'name' => 'Admin',
            'email' => Str::random(8).'@test.local', 'password' => bcrypt('x'),
            'role' => 'admin', 'active' => true,
        ]);
    }
}
