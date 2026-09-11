<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\KanbanColumn;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El comando que reparte las columnas de un tablero en grupos.
 *
 * Star NET tenía 43 columnas que eran seis preguntas distintas sobre la misma
 * conversación. Repartirlas desde la interfaz son 43 clics sin forma de
 * revisarlo antes ni de deshacerlo, así que el reparto va en un archivo que el
 * cliente puede leer, y el comando no escribe nada sin `--aplicar`.
 */
class AgruparColumnasKanbanTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_aplicar_no_toca_nada(): void
    {
        $empresa = $this->empresaCon(['Nuevo', 'CERETE']);
        $archivo = $this->archivo(['Estado' => ['bandeja' => 'Nuevo', 'columnas' => ['Nuevo']]]);

        $this->artisan("kanban:agrupar-columnas {$empresa->id} --archivo={$archivo}")
            ->expectsOutputToContain('sólo la vista previa')
            ->assertSuccessful();

        $this->assertNull(KanbanColumn::where('name', 'Nuevo')->first()->grupo);
    }

    public function test_con_aplicar_reparte_las_columnas(): void
    {
        $empresa = $this->empresaCon(['Nuevo', 'Resuelto', 'CERETE']);
        $archivo = $this->archivo([
            'Estado' => ['bandeja' => 'Nuevo', 'columnas' => ['Nuevo', 'Resuelto']],
            'Zona'   => ['columnas' => ['CERETE']],
        ]);

        $this->artisan("kanban:agrupar-columnas {$empresa->id} --archivo={$archivo} --aplicar")
            ->assertSuccessful();

        $nuevo = KanbanColumn::where('name', 'Nuevo')->first();
        $this->assertSame('Estado', $nuevo->grupo);
        $this->assertTrue($nuevo->es_bandeja);

        $this->assertSame('Estado', KanbanColumn::where('name', 'Resuelto')->first()->grupo);

        // «Zona» no lleva bandeja: lo que no tiene municipio no es de Cereté.
        $cerete = KanbanColumn::where('name', 'CERETE')->first();
        $this->assertSame('Zona', $cerete->grupo);
        $this->assertFalse($cerete->es_bandeja);
    }

    /** Los nombres se comparan sin tildes ni mayúsculas: «COVEÑAS» = «coveñas». */
    public function test_encuentra_las_columnas_pese_a_tildes_y_mayusculas(): void
    {
        $empresa = $this->empresaCon(['COVEÑAS', 'Cotización enviada']);
        $archivo = $this->archivo([
            'Zona'      => ['columnas' => ['  coveñas  ']],
            'Comercial' => ['columnas' => ['COTIZACION ENVIADA']],
        ]);

        $this->artisan("kanban:agrupar-columnas {$empresa->id} --archivo={$archivo} --aplicar")
            ->assertSuccessful();

        $this->assertSame('Zona', KanbanColumn::where('name', 'COVEÑAS')->first()->grupo);
        $this->assertSame('Comercial', KanbanColumn::where('name', 'Cotización enviada')->first()->grupo);
    }

    /** Lo que no se menciona se queda sin grupo, y el comando lo dice. */
    public function test_avisa_de_las_columnas_que_se_quedan_fuera(): void
    {
        $empresa = $this->empresaCon(['Nuevo', 'EMPRESAS']);
        $archivo = $this->archivo(['Estado' => ['columnas' => ['Nuevo']]]);

        $this->artisan("kanban:agrupar-columnas {$empresa->id} --archivo={$archivo}")
            ->expectsOutputToContain('EMPRESAS')
            ->assertSuccessful();
    }

    /** Y de los nombres del archivo que no existen, que suelen ser erratas. */
    public function test_avisa_de_los_nombres_que_no_existen(): void
    {
        $empresa = $this->empresaCon(['Nuevo']);
        $archivo = $this->archivo(['Estado' => ['columnas' => ['Nuevo', 'Inventada']]]);

        $this->artisan("kanban:agrupar-columnas {$empresa->id} --archivo={$archivo}")
            ->expectsOutputToContain('Inventada')
            ->assertSuccessful();
    }

    public function test_deshacer_devuelve_la_empresa_a_un_solo_tablero(): void
    {
        $empresa = $this->empresaCon(['Nuevo', 'CERETE']);
        $archivo = $this->archivo([
            'Estado' => ['bandeja' => 'Nuevo', 'columnas' => ['Nuevo']],
            'Zona'   => ['columnas' => ['CERETE']],
        ]);

        $this->artisan("kanban:agrupar-columnas {$empresa->id} --archivo={$archivo} --aplicar")->assertSuccessful();
        $this->artisan("kanban:agrupar-columnas {$empresa->id} --deshacer --aplicar")->assertSuccessful();

        $this->assertSame(0, KanbanColumn::whereNotNull('grupo')->count());

        // Y la bandeja vuelve a ser la primera, como estaba antes de agrupar.
        $this->assertSame(1, KanbanColumn::where('es_bandeja', true)->count());
    }

    /**
     * Con dos columnas del mismo nombre no hay forma de saber cuál quiere el
     * cliente, y elegir una en silencio parte los datos: en Star NET el comando
     * agrupaba el «COVEÑAS» de 11 tarjetas y dejaba fuera el de 18, que pasaban
     * a vivir en un grupo distinto sin que nadie lo notara.
     */
    public function test_se_planta_ante_nombres_repetidos_en_vez_de_elegir_uno(): void
    {
        $empresa = $this->empresaCon(['COVEÑAS', 'COVEÑAS', 'Nuevo']);
        $archivo = $this->archivo(['Zona' => ['columnas' => ['COVEÑAS']]]);

        $this->artisan("kanban:agrupar-columnas {$empresa->id} --archivo={$archivo} --aplicar")
            ->expectsOutputToContain('nombres repetidos')
            ->assertFailed();

        $this->assertSame(0, KanbanColumn::whereNotNull('grupo')->count());
    }

    /** Y enseña cuál es cuál, para poder decidir. */
    public function test_dice_cuantas_tarjetas_tiene_cada_repetida(): void
    {
        $empresa = $this->empresaCon(['COVEÑAS', 'COVEÑAS']);
        $archivo = $this->archivo(['Zona' => ['columnas' => ['COVEÑAS']]]);

        $this->artisan("kanban:agrupar-columnas {$empresa->id} --archivo={$archivo}")
            ->expectsOutputToContain('tarjetas')
            ->assertFailed();
    }

    public function test_una_empresa_que_no_existe_falla_sin_romper_nada(): void
    {
        $this->artisan('kanban:agrupar-columnas "Empresa Fantasma"')
            ->expectsOutputToContain('No encuentro esa empresa')
            ->assertFailed();
    }

    /** El comando de una empresa no toca las columnas de otra. */
    public function test_no_toca_las_columnas_de_otra_empresa(): void
    {
        $una  = $this->empresaCon(['Nuevo'], 'Una');
        $otra = $this->empresaCon(['Nuevo'], 'Otra');

        $archivo = $this->archivo(['Estado' => ['columnas' => ['Nuevo']]]);

        $this->artisan("kanban:agrupar-columnas {$una->id} --archivo={$archivo} --aplicar")->assertSuccessful();

        $this->assertSame('Estado', KanbanColumn::where('company_id', $una->id)->first()->grupo);
        $this->assertNull(KanbanColumn::where('company_id', $otra->id)->first()->grupo);
    }

    private function empresaCon(array $nombres, string $empresa = 'Star NET'): Company
    {
        $company = Company::create([
            'name'   => $empresa,
            'slug'   => \Illuminate\Support\Str::slug($empresa),
            'active' => true,
        ]);

        foreach ($nombres as $nombre) {
            Tag::create(['company_id' => $company->id, 'name' => $nombre, 'color' => '#64748b']);
        }

        return $company;
    }

    private function archivo(array $grupos): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'grupos').'.json';
        file_put_contents($ruta, json_encode($grupos, JSON_UNESCAPED_UNICODE));

        return $ruta;
    }
}
