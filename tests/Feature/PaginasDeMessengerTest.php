<?php

namespace Tests\Feature;

use App\Services\MessengerLoginService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Qué páginas puede conectar quien acaba de autorizar.
 *
 * EL FALLO QUE SE PREVIENE: el 27-sep-2026 Facebook concedió los cuatro
 * permisos sobre la página Integra y `/me/accounts` vino vacío, tres veces
 * seguidas. Con el inicio de sesión para empresas la página concedida no
 * siempre sale ahí; el token sí sabe a qué páginas dio acceso.
 */
class PaginasDeMessengerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.app_id' => '865904982715022',
            'services.meta.app_secret' => 'secreto-de-la-app',
        ]);
    }

    public function test_si_me_accounts_viene_vacio_usa_las_paginas_que_concedio_el_token(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/me/accounts')) {
                return Http::response(['data' => []]);
            }

            if (str_contains($r->url(), '/debug_token')) {
                return Http::response(['data' => ['granular_scopes' => [
                    ['scope' => 'pages_show_list', 'target_ids' => ['1426150013911590']],
                    ['scope' => 'pages_messaging', 'target_ids' => ['1426150013911590']],
                ]]]);
            }

            if (str_contains($r->url(), '/1426150013911590')) {
                return Http::response([
                    'id' => '1426150013911590',
                    'name' => 'Integra',
                    'access_token' => 'token-de-la-pagina',
                    'tasks' => ['MESSAGING', 'MODERATE'],
                ]);
            }

            return Http::response([], 404);
        });

        $paginas = app(MessengerLoginService::class)->paginas('token-de-usuario');

        $this->assertCount(1, $paginas);
        $this->assertSame('1426150013911590', $paginas[0]['id']);
        $this->assertSame('Integra', $paginas[0]['nombre']);
        $this->assertSame('token-de-la-pagina', $paginas[0]['token']);
        $this->assertTrue($paginas[0]['puede_mensajear']);

        // debug_token se firma con el token de la app, no con el del usuario.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/debug_token')
            && ($r->data()['access_token'] ?? null) === '865904982715022|secreto-de-la-app');
    }

    /** Si `/me/accounts` trae páginas, no se pregunta nada más. */
    public function test_con_me_accounts_lleno_no_hace_falta_el_plan_b(): void
    {
        Http::fake(['*/me/accounts*' => Http::response(['data' => [[
            'id' => '1426150013911590',
            'name' => 'Integra',
            'access_token' => 'token-de-la-pagina',
            'tasks' => ['MESSAGING'],
        ]]])]);

        $this->assertCount(1, app(MessengerLoginService::class)->paginas('token-de-usuario'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/debug_token'));
    }

    /** Sin páginas en ningún lado, lista vacía: el aviso lo pone quien llama. */
    public function test_sin_paginas_concedidas_devuelve_vacio(): void
    {
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/debug_token')) {
                return Http::response(['data' => ['granular_scopes' => []]]);
            }

            return Http::response(['data' => []]);
        });

        $this->assertSame([], app(MessengerLoginService::class)->paginas('token-de-usuario'));
    }
}
