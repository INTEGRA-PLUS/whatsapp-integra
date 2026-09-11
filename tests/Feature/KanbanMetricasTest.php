<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Las métricas de la cabecera del tablero.
 *
 * Hasta el 9-sep-2026 esa cabecera mostraba un «Pipeline Total» que era el
 * número de conversaciones multiplicado por 150.000 pesos inventados, y una
 * «Conversión» fija del 94% escrita a mano, idéntica en todas las empresas.
 * Parecían datos de negocio en una pantalla que se enseña a clientes.
 *
 * Ahora sólo se muestra lo que se puede calcular, y esto lo comprueba.
 */
class KanbanMetricasTest extends TestCase
{
    use RefreshDatabase;

    public function test_las_metricas_del_tablero_son_reales(): void
    {
        $company = Company::create(['name' => 'Cliente', 'slug' => 'cliente', 'active' => true]);
        $user = User::create([
            'company_id' => $company->id, 'name' => 'Admin', 'email' => 'a@cliente.test',
            'password' => bcrypt('secreto123'), 'role' => 'admin', 'active' => true,
        ]);
        $instance = Instance::create([
            'company_id' => $company->id, 'uuid' => (string) Str::uuid(), 'name' => 'Línea',
            'phone_number_id' => 'pnid', 'waba_id' => 'waba', 'type' => 'meta',
            'status' => 'active', 'active' => true,
        ]);

        $columna = \App\Models\KanbanColumn::create([
            'company_id' => $company->id, 'name' => 'Nuevos', 'position' => 0,
            'tag_id' => \App\Models\Tag::create(['company_id' => $company->id, 'name' => 'Nuevos', 'color' => '#59E01F'])->id,
        ]);

        // Dos en el tablero (una reciente y una parada) y una fuera de él.
        $this->conversacion($instance, $columna->id, now());
        $this->conversacion($instance, $columna->id, now()->subDays(30));
        $this->conversacion($instance, null, now());

        $this->actingAs($user)->get('/kanban')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Chat/Kanban')
                ->where('total_conversations', 3)
                ->where('en_tablero', 2)
                ->where('estancadas', 1));
    }

    private function conversacion(Instance $instance, ?int $columna, $ultimo): void
    {
        WhatsAppConversation::create([
            'instance_id' => $instance->id,
            'wa_id' => (string) Str::uuid(),
            'phone_number' => '57300'.random_int(1000000, 9999999),
            'status' => 'open',
            'kanban_column_id' => $columna,
            'last_message_at' => $ultimo,
        ]);
    }
}
