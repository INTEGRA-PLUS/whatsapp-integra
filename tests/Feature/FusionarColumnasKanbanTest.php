<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\KanbanColumn;
use App\Models\Tag;
use App\Models\WhatsAppConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Juntar las columnas que se llaman igual.
 *
 * El 9-sep-2026 había 22 columnas repetidas en la flota. En Star NET,
 * «COVEÑAS» existía tres veces: una con 18 conversaciones y otra con 11, dos
 * listas separadas que nadie veía juntas, porque cada columna es una etiqueta
 * distinta.
 *
 * Esto borra columnas y etiquetas, y no hay SoftDeletes en el proyecto: por eso
 * no escribe nada sin `--aplicar`.
 */
class FusionarColumnasKanbanTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_aplicar_no_borra_nada(): void
    {
        [$empresa] = $this->empresaConGemelas();

        $this->artisan("kanban:fusionar-columnas {$empresa->id}")
            ->expectsOutputToContain('sólo la vista previa')
            ->assertSuccessful();

        $this->assertSame(2, KanbanColumn::where('name', 'COVEÑAS')->count());
    }

    public function test_se_queda_la_que_mas_tarjetas_tiene(): void
    {
        [$empresa, $grande, $pequena] = $this->empresaConGemelas();

        $this->artisan("kanban:fusionar-columnas {$empresa->id} --aplicar")->assertSuccessful();

        $this->assertNotNull(KanbanColumn::find($grande->id));
        $this->assertNull(KanbanColumn::find($pequena->id));
        $this->assertSame(1, KanbanColumn::where('company_id', $empresa->id)->count());
    }

    /** Y las conversaciones de la absorbida se pasan a la que queda. */
    public function test_las_conversaciones_no_se_pierden(): void
    {
        [$empresa, $grande, $pequena, $conversaciones] = $this->empresaConGemelas();

        $this->artisan("kanban:fusionar-columnas {$empresa->id} --aplicar")->assertSuccessful();

        foreach ($conversaciones as $conv) {
            $conv->refresh();
            $this->assertTrue(
                $conv->tags->pluck('id')->contains($grande->tag_id),
                "La conversación {$conv->id} perdió la etiqueta"
            );
            $this->assertSame($grande->id, $conv->kanban_column_id);
        }
    }

    /**
     * La clave foránea de `kanban_column_id` es ON DELETE SET NULL: si se
     * borrara la columna antes de repuntar las conversaciones, se quedarían
     * sin ninguna y desaparecerían del tablero.
     */
    public function test_ninguna_conversacion_se_queda_sin_columna(): void
    {
        [$empresa] = $this->empresaConGemelas();

        $this->artisan("kanban:fusionar-columnas {$empresa->id} --aplicar")->assertSuccessful();

        $this->assertSame(0, WhatsAppConversation::whereNull('kanban_column_id')->count());
    }

    /** Una conversación que llevaba las dos etiquetas gemelas no rompe el pivote. */
    public function test_una_conversacion_con_las_dos_etiquetas_no_rompe_nada(): void
    {
        [$empresa, $grande, $pequena] = $this->empresaConGemelas();

        $conv = WhatsAppConversation::whereHas('tags', fn ($t) => $t->where('tags.id', $pequena->tag_id))->first();
        $conv->tags()->syncWithoutDetaching([$grande->tag_id]);

        $this->artisan("kanban:fusionar-columnas {$empresa->id} --aplicar")->assertSuccessful();

        $this->assertSame(1, $conv->fresh()->tags->where('id', $grande->tag_id)->count());
    }

    public function test_sin_repetidas_no_hace_nada(): void
    {
        $empresa = Company::create(['name' => 'Limpia', 'slug' => 'limpia', 'active' => true]);
        Tag::create(['company_id' => $empresa->id, 'name' => 'Soporte', 'color' => '#64748b']);

        $this->artisan("kanban:fusionar-columnas {$empresa->id} --aplicar")
            ->expectsOutputToContain('No hay ninguna columna repetida')
            ->assertSuccessful();
    }

    /** «COVEÑAS» y «coveñas» son la misma columna repetida. */
    public function test_las_detecta_sin_mirar_mayusculas_ni_tildes(): void
    {
        $empresa = Company::create(['name' => 'Star NET', 'slug' => 'star-net', 'active' => true]);
        Tag::create(['company_id' => $empresa->id, 'name' => 'COVEÑAS', 'color' => '#64748b']);
        Tag::create(['company_id' => $empresa->id, 'name' => ' coveñas ', 'color' => '#64748b']);

        $this->artisan("kanban:fusionar-columnas {$empresa->id} --aplicar")->assertSuccessful();

        $this->assertSame(1, KanbanColumn::where('company_id', $empresa->id)->count());
    }

    /** Y no toca las de otra empresa, aunque se llamen igual. */
    public function test_no_toca_las_columnas_de_otra_empresa(): void
    {
        [$empresa] = $this->empresaConGemelas();

        $otra = Company::create(['name' => 'Vecina', 'slug' => 'vecina', 'active' => true]);
        Tag::create(['company_id' => $otra->id, 'name' => 'COVEÑAS', 'color' => '#64748b']);

        $this->artisan("kanban:fusionar-columnas {$empresa->id} --aplicar")->assertSuccessful();

        $this->assertSame(1, KanbanColumn::where('company_id', $otra->id)->count());
    }

    /**
     * @return array{0: Company, 1: KanbanColumn, 2: KanbanColumn, 3: \Illuminate\Support\Collection}
     */
    private function empresaConGemelas(): array
    {
        $empresa = Company::create(['name' => 'Star NET', 'slug' => 'star-net', 'active' => true]);

        $instance = Instance::create([
            'company_id' => $empresa->id, 'uuid' => (string) Str::uuid(), 'name' => 'Línea',
            'phone_number_id' => 'pnid', 'waba_id' => 'waba', 'type' => 'meta',
            'status' => 'active', 'active' => true,
        ]);

        $grande  = $this->columna($empresa->id, 'COVEÑAS');
        $pequena = $this->columna($empresa->id, 'COVEÑAS');

        $conversaciones = collect();

        // Tres en la que se queda, una en la que se absorbe.
        foreach ([[$grande, 3], [$pequena, 1]] as [$columna, $cuantas]) {
            for ($i = 0; $i < $cuantas; $i++) {
                $conv = WhatsAppConversation::create([
                    'instance_id'      => $instance->id,
                    'wa_id'            => '5730001122'.$columna->id.$i,
                    'phone_number'     => '5730001122'.$columna->id.$i,
                    'status'           => 'open',
                    'kanban_column_id' => $columna->id,
                ]);
                $conv->tags()->attach([$columna->tag_id]);

                if ($columna->is($pequena)) {
                    $conversaciones->push($conv);
                }
            }
        }

        return [$empresa, $grande, $pequena, $conversaciones];
    }

    private function columna(int $companyId, string $nombre): KanbanColumn
    {
        $tag = Tag::create(['company_id' => $companyId, 'name' => $nombre, 'color' => '#64748b']);

        return KanbanColumn::where('company_id', $companyId)->where('tag_id', $tag->id)->firstOrFail();
    }
}
