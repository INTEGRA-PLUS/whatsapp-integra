<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué empresas llegaron con Integra y ya tienen el CRM dentro de lo que pagan.
 *
 * Es el dato que faltaba para que las cuentas dejaran de mentir. El panel
 * calculaba 2.391 USD/mes de facturación potencial sobre 41 clientes, y esa
 * cifra era ficticia: a casi todos se les vendió Integra **con el CRM dentro**,
 * así que ya pagan. Cobrarles el plan otra vez sería cobrar dos veces lo mismo.
 *
 * ## Por qué `cortesia` no servía
 *
 * Las 55 empresas estaban en `cortesia`, que significa «cliente al que se
 * decidió no cobrarle todavía» — un pendiente comercial que alguien va a
 * revisar. Pero un cliente de Integra no es un pendiente: **es un cliente que
 * paga**, sólo que por otra puerta. Dejarlo en cortesía garantizaba que dentro
 * de unos meses alguien intentara «regularizarlo» y le mandara una factura por
 * algo que ya tiene contratado.
 *
 * De ahí el estado `integra`, que dice la verdad: no se factura aquí, y no hay
 * nada que revisar.
 *
 * ## La siembra es un punto de partida, no la respuesta
 *
 * Se marcan las 16 empresas que tienen configurada la integración con el ERP
 * (`invoice_payments` o `contacts_sync`), porque de ésas hay constancia técnica.
 * Pero **son un suelo, no el total**: un ISP puede tener Integra contratado sin
 * haber conectado nunca la integración en el CRM. Las que falten se marcan a
 * mano desde el panel maestro, que para eso lleva el interruptor.
 *
 * Nada de esto apaga ni limita nada: sólo decide quién aparece en la lista de
 * cobro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('viene_de_integra')->default(false)->after('interna');
        });

        // Las que tienen la integración del ERP configurada. Es la única señal
        // técnica que existe; el resto lo pone una persona.
        $conErp = DB::table('company_integrations')
            ->whereIn('key', ['invoice_payments', 'contacts_sync'])
            ->distinct()
            ->pluck('company_id');

        if ($conErp->isEmpty()) {
            return;
        }

        DB::table('companies')
            ->whereIn('id', $conErp)
            ->where('interna', false)
            ->update([
                'viene_de_integra' => true,
                // El cobro pasa a `integra` sólo si estaba en cortesía: si a
                // alguien ya se le puso `activo` o `suspendido` fue una decisión
                // de una persona, y una migración no la revierte.
                'cobro' => DB::raw("CASE WHEN cobro = 'cortesia' THEN 'integra' ELSE cobro END"),
            ]);
    }

    public function down(): void
    {
        DB::table('companies')->where('cobro', 'integra')->update(['cobro' => 'cortesia']);

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('viene_de_integra');
        });
    }
};
