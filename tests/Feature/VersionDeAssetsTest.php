<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * El endpoint que evita que un despliegue le arranque la página al usuario.
 *
 * Inertia compara la versión de los assets en cada petición y, si no coincide,
 * responde 409 y el navegador recarga en el acto. Es correcto —el JavaScript
 * viejo no debe hablar con el backend nuevo— pero se lleva por delante lo que
 * el agente estuviera escribiendo.
 *
 * Preguntando la versión aparte, con una petición que nunca provoca ese 409, la
 * pantalla puede avisar y dejar que el usuario recargue cuando le convenga. Todo
 * eso se sostiene sobre una condición: que este endpoint devuelva **exactamente
 * el mismo valor** que Inertia compara. Si se separan, el aviso no sale nunca o
 * sale siempre.
 */
class VersionDeAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_devuelve_la_misma_version_que_compara_inertia(): void
    {
        $laDeInertia = (new HandleInertiaRequests)->version(Request::create('/'));

        $this->getJson(route('version'))
            ->assertOk()
            ->assertJson(['version' => $laDeInertia]);
    }

    /**
     * Se consulta también desde la pantalla de entrar, así que no puede exigir
     * sesión.
     */
    public function test_se_puede_consultar_sin_haber_entrado(): void
    {
        $this->getJson(route('version'))->assertOk();
    }

    /**
     * La respuesta no puede llevar nada más: es pública y se pide cada minuto.
     */
    public function test_no_dice_nada_mas_que_la_version(): void
    {
        $cuerpo = $this->getJson(route('version'))->json();

        $this->assertSame(['version'], array_keys($cuerpo));
    }

    /**
     * Y sobre todo: preguntar la versión no puede disparar el 409 que este
     * trabajo existe para evitar.
     */
    public function test_preguntar_no_provoca_la_recarga_forzada(): void
    {
        $res = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => 'una-version-vieja-cualquiera',
        ])->getJson(route('version'));

        $res->assertOk();
        $this->assertNull($res->headers->get('x-inertia-location'));
    }
}
