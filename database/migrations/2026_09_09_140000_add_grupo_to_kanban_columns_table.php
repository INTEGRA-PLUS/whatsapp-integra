<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las columnas del tablero pasan a tener grupo.
 *
 * Star NET tenía 43 columnas que no eran un embudo largo, sino **seis tableros
 * distintos puestos en la misma fila**: áreas (COMERCIAL, MESA DE AYUDA,
 * FACTURACION), tipos de falla (Sin servicio, Lentitud, Intermitencia), un
 * embudo comercial, cartera, estado del ticket y quince municipios. Como una
 * tarjeta sólo puede estar en una columna, había que elegir: ¿esta conversación
 * es «FACTURACION», «Reclamo de factura» o «MONTERIA»? Las tres, en realidad.
 *
 * La gente lo resolvía etiquetando por varias dimensiones a la vez —119 de las
 * 396 tarjetas del tablero llevaban más de una etiqueta-columna—, y entonces
 * arrastrar una tarjeta le borraba las otras: `moveCard` desenganchaba **todas**
 * las etiquetas de columna menos la de destino. Una tarjeta con «MESA DE AYUDA
 * + Sin servicio + Falla de zona + TOLU VIEJO» perdía tres al moverla.
 *
 * Con el grupo, cada dimensión es independiente: el tablero pinta las columnas
 * de un grupo y los demás se vuelven filtros, y mover una tarjeta sólo cambia
 * la etiqueta del grupo que se está viendo.
 *
 * `grupo` nulo significa «sin agrupar», que es un grupo como cualquier otro:
 * las 114 columnas existentes se quedan todas ahí, así que el comportamiento no
 * cambia hasta que alguien organice su tablero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kanban_columns', function (Blueprint $table) {
            $table->string('grupo', 60)->nullable()->after('subtitle');
            $table->index(['company_id', 'grupo', 'position'], 'kanban_columns_grupo_index');
        });
    }

    public function down(): void
    {
        Schema::table('kanban_columns', function (Blueprint $table) {
            $table->dropIndex('kanban_columns_grupo_index');
            $table->dropColumn('grupo');
        });
    }
};
