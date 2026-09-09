<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\KanbanColumn;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Arrastrar una tarjeta ya no le borra las etiquetas de las demás dimensiones.
 *
 * Star NET tenía 43 columnas que no eran un embudo, sino **seis tableros
 * distintos en la misma fila**: áreas (COMERCIAL, MESA DE AYUDA, FACTURACION),
 * tipos de falla (Sin servicio, Lentitud, Intermitencia), embudo comercial,
 * cartera, estado del ticket y quince municipios. La gente etiquetaba por
 * varias a la vez —119 de las 396 tarjetas del tablero llevaban más de una
 * etiqueta-columna— porque una conversación es de FACTURACION *y* de MONTERIA
 * *y* está pendiente del cliente.
 *
 * Y entonces `moveCard` desenganchaba todas las etiquetas de columna menos la
 * de destino. Un solo arrastre de una tarjeta con «MESA DE AYUDA + Sin servicio
 * + Falla de zona + TOLU VIEJO» dejaba una. Sin avisar y sin vuelta atrás: no
 * hay SoftDeletes en el proyecto.
 *
 * Con el grupo, mover dentro de «Estado» sólo toca el estado.
 */
class KanbanGruposTest extends TestCase
{
    use RefreshDatabase;

    public function test_mover_dentro_de_un_grupo_no_toca_las_etiquetas_de_los_otros(): void
    {
        [$user, $instance] = $this->empresa();

        $sinServicio = $this->columna($user->company_id, 'Sin servicio', 'Tipo de falla');
        $resuelto    = $this->columna($user->company_id, 'Resuelto', 'Tipo de falla');
        $tolu        = $this->columna($user->company_id, 'TOLU VIEJO', 'Zona');
        $mesaAyuda   = $this->columna($user->company_id, 'MESA DE AYUDA', 'Área');

        $conv = $this->conversacion($instance, $sinServicio);
        $conv->tags()->attach([$sinServicio->tag_id, $tolu->tag_id, $mesaAyuda->tag_id]);

        $this->actingAs($user)
            ->postJson("/api/kanban/conversations/{$conv->id}/move", ['column_id' => $resuelto->id])
            ->assertOk();

        $etiquetas = $conv->fresh()->tags->pluck('id');

        // Cambió la del grupo que se movió...
        $this->assertTrue($etiquetas->contains($resuelto->tag_id));
        $this->assertFalse($etiquetas->contains($sinServicio->tag_id));

        // ...y las de los otros grupos siguen ahí. Esto es lo que se perdía.
        $this->assertTrue($etiquetas->contains($tolu->tag_id), 'Se borró el municipio');
        $this->assertTrue($etiquetas->contains($mesaAyuda->tag_id), 'Se borró el área');
    }

    /**
     * Dentro de un mismo grupo sí se sustituye: una tarjeta no puede estar a la
     * vez en «Sin servicio» y en «Resuelto».
     */
    public function test_dentro_del_grupo_la_etiqueta_se_sustituye(): void
    {
        [$user, $instance] = $this->empresa();

        $nuevo    = $this->columna($user->company_id, 'Nuevo', 'Estado');
        $proceso  = $this->columna($user->company_id, 'En proceso', 'Estado');
        $resuelto = $this->columna($user->company_id, 'Resuelto', 'Estado');

        $conv = $this->conversacion($instance, $nuevo);
        $conv->tags()->attach([$nuevo->tag_id]);

        $this->actingAs($user)->postJson("/api/kanban/conversations/{$conv->id}/move", ['column_id' => $proceso->id])->assertOk();
        $this->actingAs($user)->postJson("/api/kanban/conversations/{$conv->id}/move", ['column_id' => $resuelto->id])->assertOk();

        $etiquetas = $conv->fresh()->tags->pluck('id');
        $this->assertSame([$resuelto->tag_id], $etiquetas->all());
    }

    /**
     * Las 114 columnas que ya existen no tienen grupo, y «sin grupo» es un grupo
     * como cualquier otro: entre ellas se siguen sustituyendo, igual que antes.
     * Nada cambia hasta que alguien organice su tablero.
     */
    public function test_las_columnas_sin_grupo_se_comportan_como_hasta_ahora(): void
    {
        [$user, $instance] = $this->empresa();

        $una = $this->columna($user->company_id, 'Una', null);
        $dos = $this->columna($user->company_id, 'Dos', null);

        $conv = $this->conversacion($instance, $una);
        $conv->tags()->attach([$una->tag_id]);

        $this->actingAs($user)->postJson("/api/kanban/conversations/{$conv->id}/move", ['column_id' => $dos->id])->assertOk();

        $this->assertSame([$dos->tag_id], $conv->fresh()->tags->pluck('id')->all());
    }

    /** Y una columna sin grupo no arrastra a las que sí lo tienen. */
    public function test_una_columna_sin_grupo_no_borra_las_etiquetas_agrupadas(): void
    {
        [$user, $instance] = $this->empresa();

        $suelta = $this->columna($user->company_id, 'Suelta', null);
        $otra   = $this->columna($user->company_id, 'Otra suelta', null);
        $zona   = $this->columna($user->company_id, 'MONTERIA', 'Zona');

        $conv = $this->conversacion($instance, $suelta);
        $conv->tags()->attach([$suelta->tag_id, $zona->tag_id]);

        $this->actingAs($user)->postJson("/api/kanban/conversations/{$conv->id}/move", ['column_id' => $otra->id])->assertOk();

        $etiquetas = $conv->fresh()->tags->pluck('id');
        $this->assertTrue($etiquetas->contains($zona->tag_id));
        $this->assertFalse($etiquetas->contains($suelta->tag_id));
    }

    /** Las etiquetas que no son columna nunca se han tocado, y siguen sin tocarse. */
    public function test_las_etiquetas_que_no_son_columna_se_quedan(): void
    {
        [$user, $instance] = $this->empresa();

        $una = $this->columna($user->company_id, 'Una', 'Estado');
        $dos = $this->columna($user->company_id, 'Dos', 'Estado');

        // Una etiqueta suelta: el observador le crea columna, así que se la
        // quitamos para que sea de verdad una etiqueta sin columna.
        $suelta = Tag::create(['company_id' => $user->company_id, 'name' => 'VIP', 'color' => '#000000']);
        KanbanColumn::where('tag_id', $suelta->id)->delete();

        $conv = $this->conversacion($instance, $una);
        $conv->tags()->attach([$una->tag_id, $suelta->id]);

        $this->actingAs($user)->postJson("/api/kanban/conversations/{$conv->id}/move", ['column_id' => $dos->id])->assertOk();

        $this->assertTrue($conv->fresh()->tags->pluck('id')->contains($suelta->id));
    }

    /** Una etapa se puede crear ya dentro de un grupo. */
    public function test_una_etapa_puede_nacer_con_grupo(): void
    {
        [$user] = $this->empresa();

        $this->actingAs($user)
            ->postJson('/api/kanban/columns', ['name' => 'MONTERIA', 'grupo' => 'Zona'])
            ->assertCreated()
            ->assertJsonPath('grupo', 'Zona');
    }

    /** Y una que ya existe se puede mover a un grupo. */
    public function test_una_etapa_existente_se_puede_agrupar(): void
    {
        [$user] = $this->empresa();
        $col = $this->columna($user->company_id, 'FACTURACION', null);

        $this->actingAs($user)
            ->putJson('/api/kanban/columns/'.$col->id, ['grupo' => 'Área'])
            ->assertOk();

        $this->assertSame('Área', $col->fresh()->grupo);
    }

    private function columna(int $companyId, string $nombre, ?string $grupo): KanbanColumn
    {
        $tag = Tag::create(['company_id' => $companyId, 'name' => $nombre, 'color' => '#64748b']);

        // El TagObserver ya creó la columna; sólo hay que ponerle el grupo.
        $col = KanbanColumn::where('company_id', $companyId)->where('tag_id', $tag->id)->firstOrFail();
        $col->update(['grupo' => $grupo]);

        return $col->fresh();
    }

    private function conversacion(Instance $instance, KanbanColumn $columna): WhatsAppConversation
    {
        return WhatsAppConversation::create([
            'instance_id'      => $instance->id,
            'wa_id'            => '573001112233',
            'phone_number'     => '573001112233',
            'status'           => 'open',
            'kanban_column_id' => $columna->id,
        ]);
    }

    /**
     * @return array{0: User, 1: Instance}
     */
    private function empresa(): array
    {
        $company = Company::create(['name' => 'Star NET', 'slug' => 'star-net', 'active' => true]);

        $user = User::create([
            'company_id' => $company->id, 'name' => 'Admin', 'email' => 'admin@starnet.test',
            'password' => bcrypt('secreto123'), 'role' => 'admin', 'active' => true,
        ]);

        $instance = Instance::create([
            'company_id' => $company->id, 'uuid' => (string) Str::uuid(), 'name' => 'Línea',
            'phone_number_id' => 'pnid', 'waba_id' => 'waba', 'type' => 'meta',
            'status' => 'active', 'active' => true,
        ]);

        return [$user, $instance];
    }
}
