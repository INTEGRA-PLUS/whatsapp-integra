<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Abrir el chat con un usuario que no tiene empresa.
 *
 * El 9-sep-2026, entrando con `master@sistema.com` —que en esa base no tenía
 * `company_id`— el chat devolvía un 500 entero:
 *
 *   TypeError: activeChatIntegrations(): Argument #1 ($companyId) must be of
 *   type int, null given
 *
 * Y `/` redirige a `/chat`, así que cualquier usuario sin empresa se estrella
 * contra esto nada más entrar. La pantalla puede estar vacía —sin empresa no
 * hay instancias ni integraciones que mostrar—, pero no puede reventar.
 */
class ChatSinEmpresaTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_usuario_sin_empresa_no_rompe_el_chat(): void
    {
        $user = User::create([
            'company_id' => null,
            'name' => 'Master sin empresa',
            'email' => 'master@ejemplo.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->get('/chat')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Chat/Index')
                ->where('integrations', [])
                ->has('instances', 0));
    }
}
