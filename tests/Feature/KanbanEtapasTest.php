<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\KanbanColumn;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Crear y renombrar etapas del tablero.
 *
 * El 9-sep-2026, de las **114 columnas de toda la flota**, 14 se llamaban
 * literalmente «Nueva Etapa» —cuatro de las siete de INCO INTEGRATEC, dos de
 * las diez de CMNET— y 22 repetían el nombre de otra de su misma empresa:
 * «CLIENTE» tres veces en CMNET, «COVEÑAS» tres y «MONTERIA» dos en Star NET.
 *
 * Las dos cosas salían del mismo botón: pulsar «Nueva Etapa» creaba la columna
 * al instante, con ese nombre puesto, al final del tablero y fuera de la vista.
 * Y como cada columna **es una etiqueta** —`kanban_columns.tag_id`—, cada
 * pulsación dejaba también una etiqueta basura en el chat.
 *
 * Dos etapas con el mismo nombre no son sólo feas: al arrastrar una tarjeta,
 * `moveCard` sincroniza la etiqueta de destino, así que la etiqueta que acaba
 * viendo el agente depende de cuál de las dos columnas gemelas se usó.
 */
class KanbanEtapasTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_etapa_se_crea_con_el_nombre_que_le_dan(): void
    {
        [$user] = $this->empresaConAdmin();

        $this->actingAs($user)
            ->postJson('/api/kanban/columns', ['name' => 'Cotización enviada'])
            ->assertCreated()
            ->assertJsonPath('name', 'Cotización enviada');

        // La etiqueta que la respalda nace con el mismo nombre.
        $this->assertDatabaseHas('tags', ['name' => 'Cotización enviada']);
    }

    public function test_no_deja_crear_dos_etapas_con_el_mismo_nombre(): void
    {
        [$user] = $this->empresaConAdmin();

        $this->actingAs($user)->postJson('/api/kanban/columns', ['name' => 'Soporte'])->assertCreated();

        $this->actingAs($user)
            ->postJson('/api/kanban/columns', ['name' => 'Soporte'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');

        $this->assertSame(1, KanbanColumn::count());
        $this->assertSame(1, Tag::count());
    }

    /** «COVEÑAS» y «coveñas» son la misma etapa para quien mira el tablero. */
    public function test_el_nombre_repetido_se_detecta_sin_mirar_mayusculas_ni_espacios(): void
    {
        [$user] = $this->empresaConAdmin();

        $this->actingAs($user)->postJson('/api/kanban/columns', ['name' => 'COVEÑAS'])->assertCreated();

        $this->actingAs($user)
            ->postJson('/api/kanban/columns', ['name' => '  coveñas  '])
            ->assertStatus(422);

        $this->assertSame(1, KanbanColumn::count());
    }

    /** Renombrar tampoco puede acabar en dos iguales. */
    public function test_no_deja_renombrar_una_etapa_al_nombre_de_otra(): void
    {
        [$user] = $this->empresaConAdmin();

        $this->actingAs($user)->postJson('/api/kanban/columns', ['name' => 'Soporte'])->assertCreated();
        $segunda = $this->actingAs($user)->postJson('/api/kanban/columns', ['name' => 'Instalaciones'])->json();

        $this->actingAs($user)
            ->putJson('/api/kanban/columns/'.$segunda['id'], ['name' => 'Soporte'])
            ->assertStatus(422);

        $this->assertSame('Instalaciones', KanbanColumn::find($segunda['id'])->name);
    }

    /** Pero sí renombrarse a sí misma: cambiar sólo el acento o la caja. */
    public function test_una_etapa_puede_cambiar_su_propio_nombre(): void
    {
        [$user] = $this->empresaConAdmin();
        $col = $this->actingAs($user)->postJson('/api/kanban/columns', ['name' => 'Soporte'])->json();

        $this->actingAs($user)
            ->putJson('/api/kanban/columns/'.$col['id'], ['name' => 'SOPORTE'])
            ->assertOk();

        $this->assertSame('SOPORTE', KanbanColumn::find($col['id'])->name);
    }

    /** El nombre repetido lo es dentro de una empresa, no entre empresas. */
    public function test_otra_empresa_puede_tener_una_etapa_con_ese_nombre(): void
    {
        [$unUsuario] = $this->empresaConAdmin('una');
        [$otroUsuario] = $this->empresaConAdmin('otra');

        $this->actingAs($unUsuario)->postJson('/api/kanban/columns', ['name' => 'Soporte'])->assertCreated();
        $this->actingAs($otroUsuario)->postJson('/api/kanban/columns', ['name' => 'Soporte'])->assertCreated();

        $this->assertSame(2, KanbanColumn::count());
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function empresaConAdmin(string $sufijo = 'a'): array
    {
        $company = Company::create([
            'name' => 'Empresa '.$sufijo,
            'slug' => 'empresa-'.$sufijo,
            'active' => true,
        ]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => 'admin-'.$sufijo.'@ejemplo.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);

        return [$user, $company];
    }
}
