<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Extensión «Texto predictivo».
 *
 * Lo que se protege aquí, por orden de gravedad:
 *
 * - **Que una cifra que nadie dijo no llegue nunca al campo de texto.** Es la
 *   más importante con diferencia. Lo que sale de aquí lo envía una persona con
 *   prisa, y una persona con prisa pulsa y manda: un precio que se inventó el
 *   modelo se convierte en un precio que la empresa dio por escrito. El flujo de
 *   n8n ya lo comprueba; esto prueba que el lado PHP también, porque el flujo se
 *   importa a mano y se le puede desenganchar un nodo sin querer.
 * - **Que no se sugiera sobre la conversación de otra empresa.**
 *   `whatsapp_conversations` no tiene `company_id`: el aislamiento es un
 *   `whereIn` escrito a mano, y aquí olvidarlo no devuelve datos de más en una
 *   lista — manda la conversación entera a un servicio externo y devuelve una
 *   paráfrasis de lo que decía.
 * - Que no se gaste una inferencia dos veces por la misma pregunta.
 * - Que sin la extensión, sin plan o con el flujo caído no pase nada malo.
 */
class ExtensionTextoPredictivoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    private User $asesor;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.texto_predictivo.webhook_url', 'https://n8n.test/predictivo');
        config()->set('services.texto_predictivo.api_key', 'clave');

        // El caché guarda las tandas y el candado entre inferencias. Sin
        // limpiarlo, el segundo test que use la misma conversación recibe la
        // respuesta del primero y pasa sin llamar a nada.
        Cache::flush();

        $this->company = Company::create([
            'name' => 'Fibra Sur', 'slug' => 'fibra-sur', 'active' => true, 'ia' => 'completa',
        ]);

        $this->asesor = User::create([
            'company_id' => $this->company->id,
            'name' => 'Asesora',
            'email' => 'asesora@fibra.test',
            'password' => 'secret',
            'active' => true,
            'role' => 'admin',
        ]);

        $this->instance = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-meta',
        ]);
    }

    private function instalar(array $settings = []): CompanyExtension
    {
        return CompanyExtension::create([
            'company_id' => $this->company->id,
            'slug' => 'predictive_text',
            'enabled' => true,
            'settings' => array_merge(
                ['automatico' => true, 'cuantas' => 3, 'mensajes' => 16, 'instrucciones' => ''],
                $settings
            ),
            'installed_by' => $this->asesor->id,
            'installed_at' => now(),
        ]);
    }

    /**
     * Una conversación con los textos que se le den, alternando cliente/agente y
     * terminando SIEMPRE en el cliente: si el último entrante no es reciente, la
     * ventana de 24 h está cerrada y el controlador ni pregunta.
     *
     * @param  list<string>  $textos
     */
    private function conversacion(array $textos, ?Instance $instance = null): WhatsAppConversation
    {
        $instance ??= $this->instance;

        $conv = WhatsAppConversation::create([
            'instance_id' => $instance->id,
            'wa_id' => '57300'.random_int(1000000, 9999999),
            'name' => 'Ana',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $total = count($textos);

        foreach (array_values($textos) as $i => $texto) {
            WhatsAppMessage::create([
                'conversation_id' => $conv->id,
                'wamid' => 'wamid.'.Str::random(12),
                'type' => 'text',
                'content' => $texto,
                // El último es del cliente; hacia atrás se alterna.
                'direction' => ($total - 1 - $i) % 2 === 0 ? 'inbound' : 'outbound',
                'status' => 'delivered',
                'sent_at' => now()->subMinutes($total - $i),
            ]);
        }

        return $conv->refresh();
    }

    /** @param  list<array{etiqueta: string, texto: string}>  $sugerencias */
    private function flujoDevuelve(array $sugerencias, int $estado = 200): void
    {
        Http::fake(['n8n.test/*' => Http::response(['sugerencias' => $sugerencias], $estado)]);
    }

    /** El cuerpo que se le mandó al flujo. */
    private function loEnviado(): array
    {
        $enviadas = Http::recorded();

        $this->assertNotEmpty($enviadas, 'No se llamó al flujo.');

        return json_decode($enviadas[0][0]->body(), true);
    }

    // =======================================================================
    //  La regla que lo sostiene todo: el modelo redacta, el código afirma
    // =======================================================================

    /**
     * Una cifra que no está en la conversación tira la sugerencia ENTERA.
     *
     * No se recorta el número ni se sustituye por un hueco: a una frase a la que
     * le quitas la cifra deja de querer decir lo que decía, y lo que queda es
     * una promesa a medias que alguien va a enviar igual.
     */
    public function test_descarta_la_sugerencia_con_una_cifra_que_nadie_dijo(): void
    {
        $this->instalar();
        $this->flujoDevuelve([
            ['etiqueta' => 'plazo', 'texto' => 'La instalación se hace en 3 días hábiles.'],
            ['etiqueta' => 'pedir dato', 'texto' => 'Claro, ¿me comparte la dirección?'],
        ]);

        $conv = $this->conversacion([
            'buenas, cuanto vale el plan de fibra',
            'El plan queda en 120.000 al mes',
            'y cuando me lo instalan?',
        ]);

        $respuesta = $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertOk();

        $respuesta->assertJsonCount(1, 'sugerencias');
        $respuesta->assertJsonPath('sugerencias.0.texto', 'Claro, ¿me comparte la dirección?');
    }

    /**
     * Repetir una cifra que SÍ se dijo no es inventársela, aunque venga con otro
     * formato: el cliente escribe «120000» y el modelo contesta «$120.000».
     * Descartar eso dejaría la extensión sin poder confirmar un precio jamás.
     */
    public function test_una_cifra_reformateada_no_se_descarta(): void
    {
        $this->instalar();
        $this->flujoDevuelve([
            ['etiqueta' => 'confirmar', 'texto' => 'Correcto, son $120.000 mensuales. ¿Se lo agendo?'],
        ]);

        $conv = $this->conversacion([
            'el plan de 120000 incluye instalacion?',
        ]);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertOk()
            ->assertJsonCount(1, 'sugerencias');
    }

    // =======================================================================
    //  Aislamiento entre empresas
    // =======================================================================

    public function test_no_sugiere_sobre_la_conversacion_de_otra_empresa(): void
    {
        $this->instalar();
        $this->flujoDevuelve([['etiqueta' => 'x', 'texto' => 'Con gusto.']]);

        $otra = Company::create(['name' => 'Otra', 'slug' => 'otra', 'active' => true, 'ia' => 'completa']);
        $suya = Instance::create([
            'company_id' => $otra->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Suya',
            'phone_number_id' => '999',
            'waba_id' => '888',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token',
        ]);

        $ajena = $this->conversacion(['esto es de otra empresa'], $suya);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$ajena->id}/sugerencias")
            ->assertNotFound();

        Http::assertNothingSent();
    }

    // =======================================================================
    //  Cuándo NO se gasta una inferencia
    // =======================================================================

    public function test_sin_la_extension_encendida_no_se_llama_al_modelo(): void
    {
        $this->flujoDevuelve([['etiqueta' => 'x', 'texto' => 'Con gusto.']]);

        $conv = $this->conversacion(['hola']);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertStatus(409);

        Http::assertNothingSent();
    }

    /**
     * Sin complemento de IA, 402 y no 403: no es un problema de permisos sino de
     * plan, y el frontend tiene que poder decir «contrata el complemento» en vez
     * de «no tienes acceso».
     */
    public function test_sin_plan_de_ia_responde_402(): void
    {
        $this->instalar();
        $this->flujoDevuelve([['etiqueta' => 'x', 'texto' => 'Con gusto.']]);

        $this->company->update(['ia' => 'ninguno']);

        $conv = $this->conversacion(['hola']);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertStatus(402);

        Http::assertNothingSent();
    }

    /**
     * Con la ventana vencida sólo sale una plantilla aprobada. Sugerir texto
     * libre ahí es pagar una inferencia por tres frases que Meta va a rechazar.
     */
    public function test_con_la_ventana_cerrada_no_se_gasta_una_inferencia(): void
    {
        $this->instalar();
        $this->flujoDevuelve([['etiqueta' => 'x', 'texto' => 'Con gusto.']]);

        $conv = $this->conversacion(['hace mucho de esto']);
        WhatsAppMessage::where('conversation_id', $conv->id)
            ->update(['sent_at' => now()->subDays(3), 'created_at' => now()->subDays(3)]);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertStatus(409)
            ->assertJsonPath('code', 'window_closed');

        Http::assertNothingSent();
    }

    /**
     * La misma pregunta no se paga dos veces. La clave del caché lleva el último
     * mensaje: cerrar el chat y volver a abrirlo es exactamente la misma
     * pregunta, y es lo que hace el asesor todo el día.
     */
    public function test_la_segunda_peticion_identica_sale_del_cache(): void
    {
        $this->instalar();
        $this->flujoDevuelve([['etiqueta' => 'saludo', 'texto' => 'Con gusto le ayudo.']]);

        $conv = $this->conversacion(['hola, una consulta']);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertOk()
            ->assertJsonPath('cacheado', false);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertOk()
            ->assertJsonPath('cacheado', true)
            ->assertJsonPath('sugerencias.0.texto', 'Con gusto le ayudo.');

        Http::assertSentCount(1);
    }

    /**
     * Y la tanda VACÍA también se guarda. Si el modelo no ve nada que sugerir
     * para este mensaje, no lo va a ver mejor treinta segundos después: sin
     * esto, un chat sin nada que sugerir pide una inferencia cada vez que
     * alguien lo abre.
     */
    public function test_una_tanda_vacia_tambien_se_guarda(): void
    {
        $this->instalar();
        $this->flujoDevuelve([]);

        $conv = $this->conversacion(['👍']);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertOk()
            ->assertJsonCount(0, 'sugerencias');

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertOk()
            ->assertJsonPath('cacheado', true);

        Http::assertSentCount(1);
    }

    // =======================================================================
    //  Lo que viaja al flujo
    // =======================================================================

    public function test_el_borrador_del_asesor_viaja_al_flujo(): void
    {
        $this->instalar();
        $this->flujoDevuelve([['etiqueta' => 'x', 'texto' => 'Con gusto.']]);

        $conv = $this->conversacion(['necesito ayuda']);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias", [
                'borrador' => 'Claro, permítame revi',
            ])
            ->assertOk();

        $this->assertSame('Claro, permítame revi', $this->loEnviado()['borrador']);
    }

    public function test_las_instrucciones_de_la_empresa_viajan_al_flujo(): void
    {
        $this->instalar(['instrucciones' => 'Tratamos de usted. No damos descuentos por WhatsApp.']);
        $this->flujoDevuelve([['etiqueta' => 'x', 'texto' => 'Con gusto.']]);

        $conv = $this->conversacion(['me hacen descuento?']);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertOk();

        $this->assertStringContainsString(
            'No damos descuentos',
            $this->loEnviado()['asistente']['instrucciones']
        );
    }

    /**
     * Los avisos de sistema —«conversación cerrada por Yohan»— son `internal`, y
     * el modelo los leía como parte de la charla y sugería responderles.
     */
    public function test_las_notas_internas_no_se_le_mandan_al_modelo(): void
    {
        $this->instalar();
        $this->flujoDevuelve([['etiqueta' => 'x', 'texto' => 'Con gusto.']]);

        $conv = $this->conversacion(['buenos días']);

        WhatsAppMessage::create([
            'conversation_id' => $conv->id,
            'wamid' => 'wamid.'.Str::random(12),
            'type' => 'text',
            'content' => 'OJO: este cliente es moroso',
            'direction' => 'internal',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertOk();

        $this->assertStringNotContainsString('moroso', json_encode($this->loEnviado()['mensajes']));
    }

    // =======================================================================
    //  Degradación
    // =======================================================================

    /**
     * Si el flujo se cae, el asesor escribe como escribía ayer. 200 con lista
     * vacía y no un 502: para el cuadro de redacción «no se me ocurre nada» y
     * «fallé» son lo mismo, y un error rojo sobre el campo de texto por algo que
     * nadie pidió es peor que no tener sugerencias.
     */
    public function test_si_el_flujo_se_cae_no_pasa_nada(): void
    {
        $this->instalar();
        Http::fake(['n8n.test/*' => Http::response('', 500)]);

        $conv = $this->conversacion(['hola']);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertOk()
            ->assertJsonCount(0, 'sugerencias');
    }

    public function test_sin_el_flujo_configurado_lo_dice(): void
    {
        $this->instalar();
        config()->set('services.texto_predictivo.webhook_url', null);

        $conv = $this->conversacion(['hola']);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertStatus(503);
    }

    /** Nunca más sugerencias de las que caben sobre el cuadro de redacción. */
    public function test_no_devuelve_mas_de_las_configuradas(): void
    {
        $this->instalar(['cuantas' => 2]);
        $this->flujoDevuelve([
            ['etiqueta' => 'a', 'texto' => 'Con gusto le ayudo.'],
            ['etiqueta' => 'b', 'texto' => '¿Me confirma la dirección?'],
            ['etiqueta' => 'c', 'texto' => 'Ya lo estoy revisando.'],
        ]);

        $conv = $this->conversacion(['hola']);

        $this->actingAs($this->asesor)
            ->postJson("/api/chat/conversations/{$conv->id}/sugerencias")
            ->assertOk()
            ->assertJsonCount(2, 'sugerencias');
    }
}
