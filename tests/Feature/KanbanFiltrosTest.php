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
 * Los filtros del tablero.
 *
 * El que estaba acumulaba **todos** los chips con «y». Marcar dos municipios
 * pedía entonces una conversación que estuviera en Cereté *y* en Montería a la
 * vez, y devolvía cero siempre: la pantalla dejaba marcar varios y el resultado
 * era vaciar el tablero.
 *
 * La regla que sí significa lo que parece: dentro de un mismo grupo los chips
 * se suman (Cereté **o** Montería), y entre grupos distintos se acumulan
 * (Montería **y** Facturación).
 */
class KanbanFiltrosTest extends TestCase
{
    use RefreshDatabase;

    public function test_dos_etapas_del_mismo_grupo_se_suman(): void
    {
        [$user, $instance] = $this->empresa();

        $nuevo = $this->columna($user->company_id, 'Nuevo', 'Estado', bandeja: true);
        $cerete = $this->columna($user->company_id, 'CERETE', 'Zona');
        $monteria = $this->columna($user->company_id, 'MONTERIA', 'Zona');
        $tolu = $this->columna($user->company_id, 'TOLU VIEJO', 'Zona');

        $deCerete = $this->conversacion($instance, '573001110001');
        $deMonteria = $this->conversacion($instance, '573001110002');
        $deTolu = $this->conversacion($instance, '573001110003');

        $deCerete->tags()->attach([$nuevo->tag_id, $cerete->tag_id]);
        $deMonteria->tags()->attach([$nuevo->tag_id, $monteria->tag_id]);
        $deTolu->tags()->attach([$nuevo->tag_id, $tolu->tag_id]);

        $respuesta = $this->actingAs($user)->getJson(
            "/api/kanban/columns/{$nuevo->id}/cards?grupo=Estado&filtros[]={$cerete->id}&filtros[]={$monteria->id}"
        )->assertOk();

        $ids = collect($respuesta->json('data'))->pluck('id')->sort()->values()->all();

        // Los dos municipios marcados, y no el tercero. Antes esto era [].
        $this->assertSame([$deCerete->id, $deMonteria->id], $ids);
    }

    public function test_etapas_de_grupos_distintos_se_acumulan(): void
    {
        [$user, $instance] = $this->empresa();

        $nuevo = $this->columna($user->company_id, 'Nuevo', 'Estado', bandeja: true);
        $monteria = $this->columna($user->company_id, 'MONTERIA', 'Zona');
        $facturacion = $this->columna($user->company_id, 'FACTURACION', 'Área');

        $ambas = $this->conversacion($instance, '573001110001');
        $soloUna = $this->conversacion($instance, '573001110002');

        $ambas->tags()->attach([$nuevo->tag_id, $monteria->tag_id, $facturacion->tag_id]);
        $soloUna->tags()->attach([$nuevo->tag_id, $monteria->tag_id]);

        $respuesta = $this->actingAs($user)->getJson(
            "/api/kanban/columns/{$nuevo->id}/cards?grupo=Estado&filtros[]={$monteria->id}&filtros[]={$facturacion->id}"
        )->assertOk();

        // Aquí sí manda la «y»: quien está en Montería pero no en Facturación
        // se queda fuera.
        $this->assertSame([$ambas->id], collect($respuesta->json('data'))->pluck('id')->all());
    }

    public function test_se_puede_filtrar_por_agente_y_por_sin_asignar(): void
    {
        [$user, $instance] = $this->empresa();
        $otro = User::create([
            'company_id' => $user->company_id, 'name' => 'Yohan', 'email' => 'yohan@star-net.test',
            'password' => bcrypt('secreto123'), 'role' => 'agent', 'active' => true,
        ]);

        $nuevo = $this->columna($user->company_id, 'Nuevo', 'Estado', bandeja: true);

        $deYohan = $this->conversacion($instance, '573001110001');
        $sinNadie = $this->conversacion($instance, '573001110002');
        $deOtroMas = $this->conversacion($instance, '573001110003');

        $deYohan->update(['assigned_to' => $otro->id]);
        $deOtroMas->update(['assigned_to' => $user->id]);

        $soloYohan = $this->actingAs($user)->getJson(
            "/api/kanban/columns/{$nuevo->id}/cards?grupo=Estado&agentes[]={$otro->id}"
        )->assertOk();
        $this->assertSame([$deYohan->id], collect($soloYohan->json('data'))->pluck('id')->all());

        // «Sin asignar» viaja en la misma lista, porque en la pantalla es una
        // opción más del mismo desplegable.
        $conSinAsignar = $this->actingAs($user)->getJson(
            "/api/kanban/columns/{$nuevo->id}/cards?grupo=Estado&agentes[]={$otro->id}&agentes[]=sin_asignar"
        )->assertOk();

        $ids = collect($conSinAsignar->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$deYohan->id, $sinNadie->id], $ids);
    }

    public function test_se_puede_filtrar_por_una_sola_conversacion(): void
    {
        [$user, $instance] = $this->empresa();
        $nuevo = $this->columna($user->company_id, 'Nuevo', 'Estado', bandeja: true);

        $buscada = $this->conversacion($instance, '573001110001');
        $this->conversacion($instance, '573001110002');

        $respuesta = $this->actingAs($user)->getJson(
            "/api/kanban/columns/{$nuevo->id}/cards?grupo=Estado&conversacion={$buscada->id}"
        )->assertOk();

        $this->assertSame([$buscada->id], collect($respuesta->json('data'))->pluck('id')->all());
    }

    public function test_el_filtro_de_estancadas_deja_solo_lo_que_lleva_una_semana_quieto(): void
    {
        [$user, $instance] = $this->empresa();
        $nuevo = $this->columna($user->company_id, 'Nuevo', 'Estado', bandeja: true);

        $vieja = $this->conversacion($instance, '573001110001');
        $hoy = $this->conversacion($instance, '573001110002');

        $vieja->update(['last_message_at' => now()->subDays(10)]);
        $hoy->update(['last_message_at' => now()]);

        $respuesta = $this->actingAs($user)->getJson(
            "/api/kanban/columns/{$nuevo->id}/cards?grupo=Estado&estancadas=1"
        )->assertOk();

        $this->assertSame([$vieja->id], collect($respuesta->json('data'))->pluck('id')->all());
    }

    /**
     * El número de la cabecera de la etapa tiene que contar lo mismo que se ve
     * debajo. Antes el buscador no entraba en los conteos: la cabecera decía
     * «3.184» sobre una columna que enseñaba dos tarjetas.
     */
    public function test_los_conteos_respetan_el_buscador_y_los_filtros(): void
    {
        [$user, $instance] = $this->empresa();
        $nuevo = $this->columna($user->company_id, 'Nuevo', 'Estado', bandeja: true);

        $isnardo = $this->conversacion($instance, '573001110001');
        $isnardo->update(['name' => 'ISNARDO SALAZAR']);
        $this->conversacion($instance, '573001110002')->update(['name' => 'Otra persona']);
        $this->conversacion($instance, '573001110003')->update(['name' => 'Y otra más']);

        $sinBuscar = $this->actingAs($user)->getJson('/api/kanban/counts?grupo=Estado')->assertOk();
        $this->assertSame(3, $sinBuscar->json((string) $nuevo->id));

        $buscando = $this->actingAs($user)
            ->getJson('/api/kanban/counts?grupo=Estado&search=ISNARDO')
            ->assertOk();

        $this->assertSame(1, $buscando->json((string) $nuevo->id));
    }

    public function test_el_desplegable_de_contactos_encuentra_por_nombre_y_no_cruza_de_empresa(): void
    {
        [$user, $instance] = $this->empresa();
        [$ajeno, $instanciaAjena] = $this->empresa('Megastore', 'megastore');

        $this->conversacion($instance, '573001110001')->update(['name' => 'ISNARDO SALAZAR']);
        $this->conversacion($instanciaAjena, '573009990001')->update(['name' => 'ISNARDO SALAZAR']);

        $respuesta = $this->actingAs($user)->getJson('/api/kanban/contactos?q=ISNARDO')->assertOk();

        $this->assertCount(1, $respuesta->json());
        $this->assertSame('ISNARDO SALAZAR', $respuesta->json('0.nombre'));
    }

    private function columna(int $companyId, string $nombre, ?string $grupo, bool $bandeja = false): KanbanColumn
    {
        $tag = Tag::create(['company_id' => $companyId, 'name' => $nombre, 'color' => '#76C652']);

        // El TagObserver ya creó la columna; sólo hay que ponerle el grupo.
        $col = KanbanColumn::where('company_id', $companyId)->where('tag_id', $tag->id)->firstOrFail();
        $col->update(['grupo' => $grupo, 'es_bandeja' => $bandeja]);

        return $col->fresh();
    }

    private function conversacion(Instance $instance, string $numero): WhatsAppConversation
    {
        return WhatsAppConversation::create([
            'instance_id' => $instance->id,
            'wa_id' => $numero,
            'phone_number' => $numero,
            'status' => 'open',
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
