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

    // ── Dónde cae cada tarjeta ─────────────────────────────────────────────
    //
    // La columna sale de la **etiqueta**, no de `kanban_column_id`: ese campo
    // guarda una sola columna, y con grupos una conversación está a la vez en
    // una de «Estado», otra de «Zona» y otra de «Área». Por `kanban_column_id`,
    // el tablero de Zona no encontraría ni una tarjeta.

    public function test_la_misma_tarjeta_sale_en_el_tablero_de_cada_grupo(): void
    {
        [$user, $instance] = $this->empresa();

        $proceso = $this->columna($user->company_id, 'En proceso', 'Estado');
        $this->columna($user->company_id, 'Nuevo', 'Estado');
        $monteria = $this->columna($user->company_id, 'MONTERIA', 'Zona');

        $conv = $this->conversacion($instance, $proceso);
        $conv->tags()->attach([$proceso->tag_id, $monteria->tag_id]);

        $this->actingAs($user)
            ->getJson("/api/kanban/columns/{$proceso->id}/cards")
            ->assertOk()
            ->assertJsonPath('data.0.id', $conv->id);

        $this->actingAs($user)
            ->getJson("/api/kanban/columns/{$monteria->id}/cards")
            ->assertOk()
            ->assertJsonPath('data.0.id', $conv->id);
    }

    /**
     * La columna marcada como bandeja recoge lo que no lleva ninguna etiqueta
     * de su grupo. Sin eso, las conversaciones nuevas no aparecerían en
     * ninguna parte del CRM.
     */
    public function test_la_bandeja_del_grupo_recoge_lo_que_no_esta_clasificado(): void
    {
        [$user, $instance] = $this->empresa();

        $nuevo    = $this->columna($user->company_id, 'Nuevo', 'Estado', bandeja: true);
        $this->columna($user->company_id, 'Resuelto', 'Estado');
        $monteria = $this->columna($user->company_id, 'MONTERIA', 'Zona');

        // Etiquetada de Zona, pero sin ninguna etiqueta de Estado.
        $conv = $this->conversacion($instance, $monteria);
        $conv->tags()->attach([$monteria->tag_id]);

        $this->actingAs($user)
            ->getJson("/api/kanban/columns/{$nuevo->id}/cards")
            ->assertOk()
            ->assertJsonPath('data.0.id', $conv->id);
    }

    /**
     * Y un grupo **sin** bandeja no las recoge, que es justo lo que hace falta
     * en «Zona»: una conversación sin municipio no es de Cereté por ser Cereté
     * la primera columna. Aquí la suma de las columnas es menor que el total, y
     * está bien que lo sea.
     */
    public function test_un_grupo_sin_bandeja_no_muestra_lo_no_clasificado(): void
    {
        [$user, $instance] = $this->empresa();

        $cerete   = $this->columna($user->company_id, 'CERETE', 'Zona');
        $monteria = $this->columna($user->company_id, 'MONTERIA', 'Zona');

        $sinZona = $this->conversacion($instance, null, '573001112233');
        $conZona = $this->conversacion($instance, $monteria, '573004445566');
        $conZona->tags()->attach([$monteria->tag_id]);

        $this->actingAs($user)->getJson("/api/kanban/columns/{$cerete->id}/cards")
            ->assertJsonCount(0, 'data');

        $this->actingAs($user)->getJson("/api/kanban/counts?grupo=Zona")
            ->assertJson([$cerete->id => 0, $monteria->id => 1]);

        // La conversación sin zona no ha desaparecido: sigue en el otro grupo.
        $this->assertNotNull($sinZona->fresh());
    }

    /** Dos bandejas en un grupo mostrarían lo mismo en dos columnas. */
    public function test_marcar_una_bandeja_desmarca_la_anterior(): void
    {
        [$user] = $this->empresa();

        $nuevo    = $this->columna($user->company_id, 'Nuevo', 'Estado', bandeja: true);
        $resuelto = $this->columna($user->company_id, 'Resuelto', 'Estado');

        $this->actingAs($user)
            ->putJson('/api/kanban/columns/'.$resuelto->id, ['es_bandeja' => true])
            ->assertOk()
            ->assertJsonPath('es_bandeja', true);

        $this->assertFalse($nuevo->fresh()->es_bandeja);
    }

    /** Pero la bandeja de otro grupo no se toca. */
    public function test_la_bandeja_de_otro_grupo_se_queda_como_esta(): void
    {
        [$user] = $this->empresa();

        $nuevo = $this->columna($user->company_id, 'Nuevo', 'Estado', bandeja: true);
        $zona  = $this->columna($user->company_id, 'CERETE', 'Zona');

        $this->actingAs($user)->putJson('/api/kanban/columns/'.$zona->id, ['es_bandeja' => true])->assertOk();

        $this->assertTrue($nuevo->fresh()->es_bandeja);
    }

    /**
     * Herencia de cuando todas las columnas estaban revueltas: 119 de las 396
     * tarjetas de Star NET llevaban varias etiquetas-columna. Con grupos, si
     * dos son del mismo grupo la tarjeta se queda en la primera, no sale dos
     * veces.
     */
    public function test_con_dos_etiquetas_del_mismo_grupo_la_tarjeta_no_se_duplica(): void
    {
        [$user, $instance] = $this->empresa();

        $nuevo    = $this->columna($user->company_id, 'Nuevo', 'Estado');
        $resuelto = $this->columna($user->company_id, 'Resuelto', 'Estado');

        $conv = $this->conversacion($instance, $resuelto);
        $conv->tags()->attach([$nuevo->tag_id, $resuelto->tag_id]);

        $this->actingAs($user)->getJson("/api/kanban/columns/{$nuevo->id}/cards")
            ->assertJsonCount(1, 'data');

        $this->actingAs($user)->getJson("/api/kanban/columns/{$resuelto->id}/cards")
            ->assertJsonCount(0, 'data');
    }

    public function test_los_contadores_son_los_del_grupo_que_se_esta_viendo(): void
    {
        [$user, $instance] = $this->empresa();

        $nuevo    = $this->columna($user->company_id, 'Nuevo', 'Estado', bandeja: true);
        $resuelto = $this->columna($user->company_id, 'Resuelto', 'Estado');
        $monteria = $this->columna($user->company_id, 'MONTERIA', 'Zona', bandeja: true);
        $cerete   = $this->columna($user->company_id, 'CERETE', 'Zona');

        $uno = $this->conversacion($instance, $resuelto, '573001112233');
        $uno->tags()->attach([$resuelto->tag_id, $cerete->tag_id]);

        $dos = $this->conversacion($instance, $resuelto, '573004445566');
        $dos->tags()->attach([$resuelto->tag_id, $monteria->tag_id]);

        // Una tercera sin clasificar, que cae en la bandeja de cada grupo.
        $this->conversacion($instance, null, '573007778899');

        $this->actingAs($user)->getJson('/api/kanban/counts?grupo=Estado')
            ->assertOk()
            ->assertJson([$nuevo->id => 1, $resuelto->id => 2]);

        $this->actingAs($user)->getJson('/api/kanban/counts?grupo=Zona')
            ->assertOk()
            ->assertJson([$monteria->id => 2, $cerete->id => 1]);
    }

    /** Los filtros son las columnas de los otros grupos, y se acumulan. */
    public function test_un_filtro_de_otro_grupo_recorta_el_tablero(): void
    {
        [$user, $instance] = $this->empresa();

        $resuelto = $this->columna($user->company_id, 'Resuelto', 'Estado');
        $monteria = $this->columna($user->company_id, 'MONTERIA', 'Zona');
        $cerete   = $this->columna($user->company_id, 'CERETE', 'Zona');

        $deMonteria = $this->conversacion($instance, $resuelto, '573001112233');
        $deMonteria->tags()->attach([$resuelto->tag_id, $monteria->tag_id]);

        $deCerete = $this->conversacion($instance, $resuelto, '573004445566');
        $deCerete->tags()->attach([$resuelto->tag_id, $cerete->tag_id]);

        $this->actingAs($user)
            ->getJson("/api/kanban/columns/{$resuelto->id}/cards?filtros[]={$monteria->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $deMonteria->id);

        $this->actingAs($user)
            ->getJson("/api/kanban/counts?grupo=Estado&filtros[]={$monteria->id}")
            ->assertJson([$resuelto->id => 1]);
    }

    /** Y el tablero de una empresa no ve las conversaciones de otra. */
    public function test_el_tablero_no_muestra_conversaciones_de_otra_empresa(): void
    {
        [$user, $instance] = $this->empresa();
        $columna = $this->columna($user->company_id, 'Nuevo', 'Estado');

        [, $instanceAjena] = $this->empresa('Vecina', 'vecina');
        $this->conversacion($instanceAjena, null, '573009998877');

        $this->actingAs($user)
            ->getJson("/api/kanban/columns/{$columna->id}/cards")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function columna(int $companyId, string $nombre, ?string $grupo, bool $bandeja = false): KanbanColumn
    {
        $tag = Tag::create(['company_id' => $companyId, 'name' => $nombre, 'color' => '#64748b']);

        // El TagObserver ya creó la columna; sólo hay que ponerle el grupo.
        $col = KanbanColumn::where('company_id', $companyId)->where('tag_id', $tag->id)->firstOrFail();
        $col->update(['grupo' => $grupo, 'es_bandeja' => $bandeja]);

        return $col->fresh();
    }

    private function conversacion(Instance $instance, ?KanbanColumn $columna, string $numero = '573001112233'): WhatsAppConversation
    {
        return WhatsAppConversation::create([
            'instance_id'      => $instance->id,
            'wa_id'            => $numero,
            'phone_number'     => $numero,
            'status'           => 'open',
            'kanban_column_id' => $columna?->id,
        ]);
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
