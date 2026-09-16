<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La empresa de demostración.
 *
 * Se prueba porque una demo que falla delante del cliente cuesta más que un
 * bug: no hay segunda reunión. Y sobre todo se prueba que **no puede enviar
 * nada**, porque una demo que por accidente le escriba a alguien es el
 * incidente que no queremos.
 */
class EmpresaDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_monta_la_empresa_completa(): void
    {
        $this->artisan('demo:montar')->assertSuccessful();

        $company = Company::where('slug', 'cootramed-demo')->first();
        $this->assertNotNull($company);

        // Equipo: una dirección y tres agentes en las sedes.
        $this->assertSame(4, User::where('company_id', $company->id)->count());

        // Socios, conversaciones con su hilo, menús y una campaña con resultados.
        // Diez de las conversaciones de hoy y veinticuatro del historial de dos
        // semanas, que es lo que llena la gráfica de la pantalla de inicio.
        $this->assertSame(34, Contact::where('company_id', $company->id)->count());

        $instancia = Instance::where('company_id', $company->id)->firstOrFail();
        $conversaciones = WhatsAppConversation::where('instance_id', $instancia->id)->get();

        $this->assertCount(32, $conversaciones);

        // Siete abiertas: las de hoy menos la de María Eugenia, que se cerró.
        // El historial de dos semanas va TODO cerrado a propósito — si no, la
        // bandeja pasaría de siete a treinta y una y la demo dejaría de poder
        // enseñar lo que importa: que de un vistazo se sabe a quién atender.
        $this->assertSame(7, $conversaciones->where('status', '!=', 'closed')->count());
        $this->assertGreaterThan(20, WhatsAppMessage::whereIn('conversation_id', $conversaciones->pluck('id'))->count());

        $this->assertSame(2, WhatsAppMenu::where('company_id', $company->id)->count());

        $campana = WhatsAppCampaign::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('completed', $campana->status);
        $this->assertSame(10, $campana->recipients()->count());
        $this->assertSame(1, $campana->recipients()->where('status', 'failed')->count());
    }

    /**
     * La gráfica de la pantalla de inicio no puede salir plana.
     *
     * Dibuja «mensajes por día, últimas dos semanas». Con sólo las
     * conversaciones de hoy salía una línea en cero durante trece días y un
     * pico vertical al final: delante de un cliente eso se lee como «esto lo
     * encendieron hace un rato», que es lo contrario de lo que se va a vender.
     *
     * Se comprueba que haya mensajes repartidos en al menos ocho días
     * distintos, no sólo que existan: mil mensajes con la fecha de ayer
     * llenarían la cuenta y dejarían la curva igual de plana.
     */
    public function test_la_grafica_de_inicio_tiene_dos_semanas_de_actividad(): void
    {
        $this->artisan('demo:montar')->assertSuccessful();

        $company = Company::where('slug', 'cootramed-demo')->firstOrFail();
        $instancia = Instance::where('company_id', $company->id)->firstOrFail();

        $dias = WhatsAppMessage::whereIn(
            'conversation_id',
            WhatsAppConversation::where('instance_id', $instancia->id)->pluck('id')
        )->get()->groupBy(fn (WhatsAppMessage $m) => $m->created_at->toDateString());

        $this->assertGreaterThanOrEqual(8, $dias->count(),
            'Los mensajes tienen que estar repartidos en varios días, no todos hoy.');

        // Y que el pico de hoy siga ahí: es lo que da los «recibidos hoy» de
        // las tarjetas de arriba, que es el número que más se mira.
        $this->assertGreaterThan(10, $dias[now()->toDateString()]->count());
    }

    /**
     * La demo tiene que poder enseñar las funciones que se están vendiendo.
     *
     * Las tres cosas que se comprueban aquí se rompieron de verdad la víspera
     * de una presentación:
     *
     * 1. **Extensiones encendidas.** El catálogo salía lleno de botones de
     *    «Instalar», que es enseñar la promesa en vez de la función.
     * 2. **Hilos de 8 mensajes o más.** El botón «Resumir» se esconde por
     *    debajo de ese número —en un hilo de tres estorba— así que con las
     *    cinco conversaciones cortas originales la función estrella no
     *    aparecía en pantalla.
     * 3. **Semáforo pintado.** La extensión sólo colorea lo que llega después
     *    de encenderse, y en una demo todo llegó antes: sin el repintado, las
     *    caritas salen todas en gris.
     */
    public function test_la_demo_puede_ensenar_las_funciones_que_se_venden(): void
    {
        $this->artisan('demo:montar')->assertSuccessful();

        $company = Company::where('slug', 'cootramed-demo')->firstOrFail();

        $this->assertSame(
            count(config('extensions.available')),
            \App\Models\CompanyExtension::where('company_id', $company->id)->where('enabled', true)->count(),
            'Las extensiones tienen que quedar instaladas Y encendidas.'
        );

        $instancia = Instance::where('company_id', $company->id)->firstOrFail();
        $conversaciones = WhatsAppConversation::where('instance_id', $instancia->id)->get();

        $largas = $conversaciones->filter(
            fn (WhatsAppConversation $c) => WhatsAppMessage::where('conversation_id', $c->id)->count() >= 8
        );

        $this->assertGreaterThanOrEqual(3, $largas->count(), 'Hacen falta hilos donde el botón «Resumir» se vea.');

        $this->assertGreaterThan(0, $conversaciones->whereNotNull('sentiment_level')->count(),
            'El semáforo tiene que quedar pintado, no en gris.');

        // Y no se factura: es una demo, y la lista de cobro del panel se
        // exporta y se la lleva alguien a facturación.
        $this->assertTrue((bool) $company->interna);
    }

    /**
     * Lo más importante del comando: la demo no puede escribirle a nadie.
     */
    public function test_la_demo_no_puede_enviar_mensajes(): void
    {
        $this->artisan('demo:montar')->assertSuccessful();

        $company = Company::where('slug', 'cootramed-demo')->firstOrFail();
        $instancia = Instance::where('company_id', $company->id)->firstOrFail();

        // Seleccionable: si no aparece en el selector de instancias, el chat no
        // se puede abrir y la demo no enseña nada.
        $this->assertTrue((bool) $instancia->active, 'La línea no aparecería en el selector.');

        // Pero incapaz de enviar, que es lo que de verdad importa.
        $this->assertSame('', (string) $instancia->access_token);
        $this->assertFalse($instancia->isMetaConfigured(), 'La línea de la demo tiene credenciales que Meta aceptaría.');
    }

    /**
     * Los hilos tienen que leerse como una conversación de verdad, con horas
     * distintas. Si todos los mensajes nacen con la hora actual, la demo parece
     * un volcado de base de datos.
     */
    public function test_los_mensajes_estan_repartidos_en_el_tiempo(): void
    {
        $this->artisan('demo:montar')->assertSuccessful();

        $company = Company::where('slug', 'cootramed-demo')->firstOrFail();
        $instancia = Instance::where('company_id', $company->id)->firstOrFail();
        $conversacion = WhatsAppConversation::where('instance_id', $instancia->id)
            ->whereNotNull('assigned_to')
            ->firstOrFail();

        $fechas = $conversacion->messages()->orderBy('created_at')->pluck('created_at');

        $this->assertGreaterThan(1, $fechas->unique()->count(), 'Todos los mensajes tienen la misma hora.');
        $this->assertTrue($fechas->first()->lessThan($fechas->last()));
    }

    public function test_rehacerla_no_deja_nada_a_medias(): void
    {
        $this->artisan('demo:montar')->assertSuccessful();

        // Sin --rehacer se niega, para no duplicar por descuido.
        $this->artisan('demo:montar')->assertFailed();

        $this->artisan('demo:montar --rehacer')->assertSuccessful();

        $this->assertSame(1, Company::where('slug', 'cootramed-demo')->count());
        $this->assertSame(34, Contact::whereIn(
            'company_id',
            Company::where('slug', 'cootramed-demo')->pluck('id')
        )->count());

        // Y que no quede nada apuntando a la empresa anterior.
        //
        // Esto no lo miraba nadie, y el borrado llevaba dos fallos: la pivote
        // de etiquetas se borraba por `conversation_id` cuando la columna se
        // llama `whatsapp_conversation_id` —así que `--rehacer` reventaba en
        // producción en cuanto había una conversación etiquetada— y las filas
        // de extensiones no se borraban en absoluto, porque cuando se escribió
        // el borrado la demo todavía no instalaba ninguna.
        //
        // Contar filas de la empresa nueva no habría detectado ninguno de los
        // dos: hay que contar las que quedaron de la vieja.
        $viva = Company::where('slug', 'cootramed-demo')->value('id');

        $this->assertSame(
            0,
            \App\Models\CompanyExtension::where('company_id', '!=', $viva)->count(),
            'Quedaron extensiones de la empresa borrada.'
        );

        $conversacionesVivas = WhatsAppConversation::whereIn(
            'instance_id',
            Instance::where('company_id', $viva)->pluck('id')
        )->pluck('id');

        $this->assertSame(
            0,
            \Illuminate\Support\Facades\DB::table('whatsapp_conversation_tag')
                ->whereNotIn('whatsapp_conversation_id', $conversacionesVivas)
                ->count(),
            'Quedaron etiquetas colgando de conversaciones borradas.'
        );
    }
}
