<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El plan de CRM y el complemento de IA dejan de ser lo mismo.
 *
 * Hasta hoy eran tres planes —`esencial`, `automatizacion`, `inteligente`— con
 * la IA metida dentro del más caro. El modelo se rompió al mirar la base real:
 * casi todos los clientes llegaron con Integra y **ya pagan el CRM dentro del
 * ERP**, así que lo que se les puede vender no es el plan, es la IA. Y con la
 * IA dentro del plan grande, venderla obligaba a subirles de plan entero.
 *
 * Ahora son dos columnas: `plan` (el tamaño, con precio fijo) e `ia` (qué
 * funciones con modelo están contratadas).
 *
 * ## Cómo se traduce lo que había
 *
 * **El plan sale del tamaño real, no del nombre viejo.** Los tres planes
 * antiguos no significaban un tamaño —sólo qué extensiones podía instalar— así
 * que traducirlos por nombre habría puesto a un cliente de 9.000 contactos en el
 * plan de 3.000. Se mira `contactos_contratados`, que sí era el tramo.
 *
 * **La IA se apaga para todos menos para quien la está usando.** Es lo que pidió
 * Alejandro: los clientes de Integra quedan «sin IA» para poder vendérsela. Pero
 * apagársela a quien hoy la tiene funcionando sería una regresión que notaría al
 * día siguiente, así que quien tenga el resumen instalado o el semáforo con
 * `usar_ia` encendido se queda con `completa`. Son dos: Megastore, que lo usa de
 * verdad, y la empresa de demostración, que tiene que poder enseñarlo.
 *
 * Las empresas internas también se quedan con `completa`: `Meta App Review`
 * necesita todo encendido para las revisiones de Meta, y `PRUEBAS` existe justo
 * para probar lo que todavía no se vende.
 *
 * ## Lo que NO hace
 *
 * No desinstala ninguna extensión ni apaga ninguna función. El candado de
 * `PlanDeLaEmpresa` decide qué se puede *instalar*; lo ya instalado sigue donde
 * está. Degradar a alguien en una migración es cómo se le quita a un cliente
 * algo que usa sin que nadie se lo haya dicho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Texto y no enum, por lo mismo que `plan`: cambiar un enum en MySQL
            // reescribe la tabla, y los paquetes comerciales cambian mucho más
            // que el esquema.
            $table->string('ia', 20)->default('ninguno')->after('plan');
        });

        // ── Quién se queda con la IA ────────────────────────────────────────
        //
        // Quien la está usando de verdad: tiene el resumen instalado o el
        // semáforo afinado con IA. Se mira lo ENCENDIDO, no lo instalado: una
        // extensión instalada y apagada es alguien que la probó y no la quiso.
        $conIa = DB::table('company_extensions')
            ->where('enabled', true)
            ->where(function ($q) {
                $q->where('slug', 'conversation_summary')
                    ->orWhere(function ($q) {
                        $q->where('slug', 'sentiment_traffic_light')
                            ->where('settings', 'like', '%"usar_ia":true%');
                    });
            })
            ->distinct()
            ->pluck('company_id')
            ->all();

        $internas = DB::table('companies')->where('interna', true)->pluck('id')->all();

        $quedanConIa = array_unique(array_merge($conIa, $internas));

        if ($quedanConIa !== []) {
            DB::table('companies')->whereIn('id', $quedanConIa)->update(['ia' => 'completa']);
        }

        // ── El plan de CRM, por el tamaño contratado ────────────────────────
        //
        // De mayor a menor, y el último gana: así una empresa de 9.000 pasa por
        // `avanzado` y no se queda en `basico` por el primer `where` que cuadre.
        foreach (array_reverse(config('planes.crm', []), true) as $slug => $datos) {
            DB::table('companies')
                ->where(function ($q) use ($datos) {
                    $q->where('contactos_contratados', '<=', $datos['contactos'])
                        ->orWhereNull('contactos_contratados');
                })
                ->update(['plan' => $slug]);
        }

        // Por encima del catálogo se queda el mayor: el precio es «a cotizar» y
        // lo pone una persona, pero dejarla en el plan pequeño le apagaría cosas.
        $mayor = array_key_last(config('planes.crm', []));
        $tope = (int) config("planes.crm.{$mayor}.contactos", 0);

        if ($mayor && $tope) {
            DB::table('companies')
                ->where('contactos_contratados', '>', $tope)
                ->update(['plan' => $mayor]);
        }
    }

    public function down(): void
    {
        // Los nombres viejos, con la equivalencia más honesta que hay: quien
        // tenga IA vuelve a `inteligente`, que es donde estaba metida.
        DB::table('companies')->where('ia', '!=', 'ninguno')->update(['plan' => 'inteligente']);
        DB::table('companies')->where('ia', 'ninguno')->update(['plan' => 'esencial']);

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('ia');
        });
    }
};
