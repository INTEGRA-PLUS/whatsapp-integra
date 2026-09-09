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
        $this->assertSame(10, Contact::where('company_id', $company->id)->count());

        $instancia = Instance::where('company_id', $company->id)->firstOrFail();
        $conversaciones = WhatsAppConversation::where('instance_id', $instancia->id)->get();

        $this->assertCount(5, $conversaciones);
        $this->assertGreaterThan(20, WhatsAppMessage::whereIn('conversation_id', $conversaciones->pluck('id'))->count());

        $this->assertSame(2, WhatsAppMenu::where('company_id', $company->id)->count());

        $campana = WhatsAppCampaign::where('company_id', $company->id)->firstOrFail();
        $this->assertSame('completed', $campana->status);
        $this->assertSame(10, $campana->recipients()->count());
        $this->assertSame(1, $campana->recipients()->where('status', 'failed')->count());
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
        $this->assertSame(10, Contact::whereIn(
            'company_id',
            Company::where('slug', 'cootramed-demo')->pluck('id')
        )->count());
    }
}
