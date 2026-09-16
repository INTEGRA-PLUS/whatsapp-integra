<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los documentos con los que una empresa entrena a su IA, y sus trozos.
 *
 * Hasta ahora lo que la IA sabía del negocio cabía en un campo de 4.000
 * caracteres. Estas dos tablas son lo que permite que quepa un reglamento, un
 * tarifario o un manual de atención entero.
 *
 * ## Por qué `company_id` está en las DOS tablas
 *
 * En `ai_fragmentos` se podría deducir saltando por `ai_documento_id`, y aun así
 * está repetido a propósito. Aquí el aislamiento entre empresas es manual —~315
 * `where('company_id', …)` escritos a mano, ni un global scope— y ya hay diez
 * modelos que se aíslan dando saltos por la instancia. La búsqueda de fragmentos
 * corre **en cada mensaje entrante**: es el sitio con más tráfico de todo esto y
 * el que peor se ve si falla, porque un trozo del tarifario de una ISP
 * contestándole al cliente de otra no lanza ningún error. Con la columna aquí,
 * el filtro es un `where` y no un `join` que alguien pueda "simplificar".
 *
 * ## El vector llega vacío a propósito
 *
 * `vector` es nulo en esta primera entrega: aquí sólo se sube, se extrae y se
 * parte. Lo que lo rellena —y la búsqueda que lo usa— es la entrega siguiente.
 * Se crea ya la columna para no migrar dos veces una tabla que para entonces
 * tendrá datos.
 *
 * Ver `docs/plan-adjuntos-ia.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // El nombre tal y como lo subió el admin. Se enseña en la pantalla y
            // viaja en la cita del prompt («según Reglamento de crédito.pdf»),
            // así que es contenido, no metadato.
            $table->string('nombre');
            $table->string('extension', 10);
            $table->unsignedBigInteger('bytes');

            // Dónde está el fichero en el disco privado. No es una URL: estos
            // documentos no se sirven directamente, se descargan por una ruta
            // que comprueba antes la empresa del usuario.
            $table->string('ruta');

            $table->enum('estado', ['procesando', 'listo', 'fallido'])->default('procesando');

            // Por qué falló, en castellano y para el admin: «este PDF es una
            // imagen escaneada y no tiene texto». Un documento en «fallido» sin
            // explicación es un documento que se vuelve a subir tres veces.
            $table->string('motivo')->nullable();

            $table->unsignedInteger('fragmentos')->default(0);
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'estado']);
        });

        Schema::create('ai_fragmentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_documento_id')->constrained('ai_documentos')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // De dónde salió dentro del documento: la página de un PDF, la hoja
            // y la fila de un Excel. Es lo que permite que la IA cite la fuente
            // y que la empresa pueda auditar una respuesta mala.
            $table->string('origen')->nullable();

            $table->unsignedInteger('orden');
            $table->text('texto');
            // Binario y no JSON: un vector de bge-m3 son 1.024 dimensiones,
            // 7,4 KB en JSON contra 4 KB en float32 crudo. La búsqueda los
            // carga en cada mensaje entrante, así que el doble de tamaño es el
            // doble de lectura por cada mensaje de cada cliente. Lo empaqueta
            // `App\Casts\Vector`, y desde el modelo sigue siendo un array.
            $table->binary('vector')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'ai_documento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_fragmentos');
        Schema::dropIfExists('ai_documentos');
    }
};
