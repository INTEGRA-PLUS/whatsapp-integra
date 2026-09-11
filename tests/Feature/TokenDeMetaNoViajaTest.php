<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El token de Meta no sale del servidor.
 *
 * `ChatController@index` manda las instancias completas a Inertia, y
 * `access_token` no estaba en `$hidden`: **viajaba dentro del HTML de
 * `/chat`**. Con ese token se envían mensajes como el cliente y se lee su WABA
 * entera, así que cualquier agente con acceso al chat —o cualquiera mirando el
 * código fuente de la página— lo tenía delante. Comprobado renderizando la
 * pantalla el 11-sep-2026.
 *
 * Estas pruebas fijan las dos mitades del arreglo: que deja de serializarse, y
 * que el formulario de edición —que antes lo traía relleno— no deja la línea
 * muda al guardarse vacío.
 */
class TokenDeMetaNoViajaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pantalla_del_chat_no_lleva_el_token(): void
    {
        [$user] = $this->empresa();

        $respuesta = $this->actingAs($user)->get('/chat')->assertOk();

        $respuesta->assertDontSee('EAAsecreto', false);
        $respuesta->assertDontSee('access_token', false);
    }

    public function test_el_token_sigue_disponible_en_el_servidor(): void
    {
        [$user, $instancia] = $this->empresa();

        // Esconderlo es cosa de la serialización: el código que habla con Meta
        // lo sigue leyendo igual.
        $this->assertSame('EAAsecreto-de-prueba', $instancia->fresh()->access_token);
        $this->assertArrayNotHasKey('access_token', $instancia->fresh()->toArray());
    }

    public function test_editar_la_linea_sin_tocar_el_token_lo_conserva(): void
    {
        [$user, $instancia] = $this->empresa();

        $this->actingAs($user)->put("/instances/{$instancia->id}", [
            'name' => 'Soporte renombrado',
            'phone_number_id' => $instancia->phone_number_id,
            'waba_id' => $instancia->waba_id,
            'display_phone_number' => '+57 300 111 2233',
            'access_token' => '',   // el formulario ya no lo trae
            'active' => true,
        ])->assertRedirect();

        $fresca = $instancia->fresh();
        $this->assertSame('Soporte renombrado', $fresca->name);
        $this->assertSame('EAAsecreto-de-prueba', $fresca->access_token, 'Renombrar dejó la línea sin token');
    }

    public function test_mandar_un_token_nuevo_si_lo_cambia(): void
    {
        [$user, $instancia] = $this->empresa();

        $this->actingAs($user)->put("/instances/{$instancia->id}", [
            'name' => $instancia->name,
            'phone_number_id' => $instancia->phone_number_id,
            'waba_id' => $instancia->waba_id,
            'access_token' => 'EAAotro-token',
            'active' => true,
        ])->assertRedirect();

        $this->assertSame('EAAotro-token', $instancia->fresh()->access_token);
    }

    /**
     * @return array{0: User, 1: Instance}
     */
    private function empresa(string $slug = 'star-net'): array
    {
        $company = Company::create(['name' => 'Star NET', 'slug' => $slug, 'active' => true]);

        $user = User::create([
            'company_id' => $company->id, 'name' => 'Admin', 'email' => 'admin@'.$slug.'.test',
            'password' => bcrypt('secreto123'), 'role' => 'admin', 'active' => true,
        ]);

        $instance = Instance::create([
            'company_id' => $company->id, 'uuid' => (string) Str::uuid(), 'name' => 'Soporte',
            'phone_number_id' => 'pnid-'.$slug, 'waba_id' => 'waba-'.$slug, 'type' => 'meta',
            'status' => 'active', 'active' => true, 'channel' => 'whatsapp',
            'access_token' => 'EAAsecreto-de-prueba',
        ]);

        return [$user, $instance];
    }
}
