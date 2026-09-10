<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\KanbanColumn;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Esconder etapas del tablero es una preferencia de cada usuario.
 *
 * Con doce columnas, quien está en soporte no quiere ver las cuatro de
 * facturación, y quien factura quiere justo las otras. Por eso se guarda en el
 * usuario y no en la empresa: esconder una etapa no puede quitársela a los
 * compañeros.
 */
class EtapasOcultasTest extends TestCase
{
    use RefreshDatabase;

    public function test_se_guardan_para_el_usuario_y_sobreviven_a_la_sesion(): void
    {
        [$user] = $this->empresa();
        $facturacion = $this->columna($user->company_id, 'FACTURACION');
        $soporte = $this->columna($user->company_id, 'SOPORTE');

        $this->actingAs($user)
            ->putJson('/api/kanban/etapas-ocultas', ['ocultas' => [$facturacion->id]])
            ->assertOk()
            ->assertJson(['ocultas' => [$facturacion->id]]);

        // En la base, no en la sesión: `fresh()` vuelve a leer del disco.
        $this->assertSame([$facturacion->id], $user->fresh()->etapasOcultas());
        $this->assertNotContains($soporte->id, $user->fresh()->etapasOcultas());
    }

    public function test_esconder_una_etapa_no_se_la_quita_a_los_companeros(): void
    {
        [$user] = $this->empresa();
        $otro = User::create([
            'company_id' => $user->company_id, 'name' => 'Yohan', 'email' => 'yohan@star-net.test',
            'password' => bcrypt('secreto123'), 'role' => 'agent', 'active' => true,
        ]);
        $facturacion = $this->columna($user->company_id, 'FACTURACION');

        $this->actingAs($user)
            ->putJson('/api/kanban/etapas-ocultas', ['ocultas' => [$facturacion->id]])
            ->assertOk();

        $this->assertSame([], $otro->fresh()->etapasOcultas());
    }

    public function test_no_se_guardan_etapas_de_otra_empresa(): void
    {
        [$user] = $this->empresa();
        [$ajeno] = $this->empresa('Megastore', 'megastore');

        $mia = $this->columna($user->company_id, 'MIA');
        $suya = $this->columna($ajeno->company_id, 'SUYA');

        $this->actingAs($user)
            ->putJson('/api/kanban/etapas-ocultas', ['ocultas' => [$mia->id, $suya->id]])
            ->assertOk();

        $this->assertSame([$mia->id], $user->fresh()->etapasOcultas());
    }

    public function test_la_lista_vacia_vuelve_a_enseñarlas_todas(): void
    {
        [$user] = $this->empresa();
        $una = $this->columna($user->company_id, 'UNA');

        $this->actingAs($user)->putJson('/api/kanban/etapas-ocultas', ['ocultas' => [$una->id]])->assertOk();
        $this->assertSame([$una->id], $user->fresh()->etapasOcultas());

        $this->actingAs($user)->putJson('/api/kanban/etapas-ocultas', ['ocultas' => []])->assertOk();
        $this->assertSame([], $user->fresh()->etapasOcultas());
    }

    /**
     * `preferencias` es un cajón compartido, como `instances.meta`: guardar las
     * etapas no puede borrar lo que dejen ahí otras pantallas.
     */
    public function test_guardar_las_etapas_no_pisa_las_demas_preferencias(): void
    {
        [$user] = $this->empresa();
        $una = $this->columna($user->company_id, 'UNA');

        $user->preferencias = ['tema' => 'oscuro'];
        $user->save();

        $this->actingAs($user)->putJson('/api/kanban/etapas-ocultas', ['ocultas' => [$una->id]])->assertOk();

        $this->assertSame('oscuro', $user->fresh()->preferencias['tema']);
        $this->assertSame([$una->id], $user->fresh()->etapasOcultas());
    }

    public function test_el_tablero_recibe_las_etapas_ocultas_del_usuario(): void
    {
        [$user, $instance] = $this->empresa();
        $una = $this->columna($user->company_id, 'UNA');

        $user->guardarEtapasOcultas([$una->id]);

        $this->actingAs($user)
            ->get('/kanban')
            ->assertOk()
            ->assertInertia(fn ($pagina) => $pagina
                ->component('Chat/Kanban')
                ->where('etapas_ocultas', [$una->id]));
    }

    private function columna(int $companyId, string $nombre): KanbanColumn
    {
        $tag = Tag::create(['company_id' => $companyId, 'name' => $nombre, 'color' => '#76C652']);

        return KanbanColumn::where('company_id', $companyId)->where('tag_id', $tag->id)->firstOrFail();
    }

    /**
     * @return array{0: User, 1: Instance}
     */
    private function empresa(string $nombre = 'Star NET', string $slug = 'star-net'): array
    {
        $company = Company::create(['name' => $nombre, 'slug' => $slug, 'active' => true]);

        $user = User::create([
            'company_id' => $company->id, 'name' => 'Admin', 'email' => 'admin@'.$slug.'.test',
            'password' => bcrypt('secreto123'), 'role' => 'admin', 'active' => true,
        ]);

        $instance = Instance::create([
            'company_id' => $company->id, 'uuid' => (string) Str::uuid(), 'name' => 'Línea',
            'phone_number_id' => 'pnid-'.$slug, 'waba_id' => 'waba-'.$slug, 'type' => 'meta',
            'status' => 'active', 'active' => true,
        ]);

        return [$user, $instance];
    }
}
