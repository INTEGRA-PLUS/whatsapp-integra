<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El precio a medida de una empresa.
 *
 * El catálogo llega hasta 158 USD al mes —Avanzado con IA Completa— y hay
 * clientes por encima: a Cootramed se le propusieron 250. La diferencia no es un
 * plan más grande, es **lo que no cabe en ningún plan**: desarrollos a medida
 * sobre su ERP y atención personalizada.
 *
 * Por eso esto NO es un cuarto plan en `config/planes.php`. Un plan personalizado
 * no tiene topes que escribir —cada caso es distinto— así que habría que
 * inventarle unos límites falsos, y el candado de funciones dejaría de
 * significar nada. La empresa conserva su plan de CRM y su complemento de IA,
 * que son los que deciden qué puede usar; lo único que cambia es lo que paga.
 *
 * Tamaño y precio, separados: la misma idea que separó el CRM de la IA.
 *
 * El porqué va en `nota_de_cobro`, que ya existe para esto. Sin ella, dentro de
 * un año nadie sabe por qué ese cliente paga distinto y la respuesta acaba en el
 * WhatsApp de alguien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // En USD enteros, como el resto de los precios del sistema: no hay
            // un solo importe con céntimos en el catálogo y añadir decimales
            // aquí obligaría a redondear en cada pantalla que lo enseñe.
            $table->unsignedInteger('precio_personalizado')
                ->nullable()
                ->after('ia');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('precio_personalizado');
        });
    }
};
