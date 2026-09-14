<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Support\Sentimiento\Lectura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Extensión «Semáforo de emociones», probada contra el webhook real.
 *
 * Lo que se protege aquí NO es que el análisis acierte —ningún método acierta
 * siempre, y el léxico menos que ninguno—. Es la **calibración**, que es lo que
 * decide si la herramienta se usa o se ignora:
 *
 * - Que tener un problema no ponga a nadie en rojo. Todo el que escribe a
 *   soporte tiene uno; si eso bastara, la bandeja entera sería roja el primer
 *   día y a la semana nadie la miraría. Es el modo de fallo documentado de estas
 *   herramientas y el que más tests tiene aquí.
 * - Que no se escape un cliente furioso. El falso negativo cuesta mucho más que
 *   el falso positivo.
 * - Que un "gracias" no borre tres mensajes de enfado.
 * - Que sin la extensión instalada no pase absolutamente nada.
 */
class ExtensionSentimientoTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Instance $instance;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Http::fake(fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.Str::random(6)]]], 200));

        $this->company = Company::create(['name' => 'Fibra Sur', 'slug' => 'fibra-sur', 'active' => true]);

        $this->admin = User::create([
            'company_id' => $this->company->id,
            'name' => 'Admin',
            'email' => 'admin@fibra.test',
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

    /** @param array<string, mixed> $settings */
    private function instalar(array $settings = []): CompanyExtension
    {
        return CompanyExtension::create([
            'company_id' => $this->company->id,
            'slug' => 'sentiment_traffic_light',
            'enabled' => true,
            'settings' => array_merge([
                'sensibilidad' => 'medio',
                'ventana_mensajes' => 8,
                'palabras_rojas' => '',
            ], $settings),
            'installed_by' => $this->admin->id,
            'installed_at' => now(),
        ]);
    }

    private function recibir(string $texto, ?Instance $instance = null, string $de = '573007852081'): void
    {
        $instance ??= $this->instance;

        $this->postSignedWebhook([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $instance->waba_id,
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '573001234567',
                            'phone_number_id' => $instance->phone_number_id,
                        ],
                        'contacts' => [[
                            'profile' => ['name' => 'Cliente'],
                            'wa_id' => $de,
                        ]],
                        'messages' => [[
                            'from' => $de,
                            'id' => 'wamid.IN'.Str::random(8),
                            'timestamp' => (string) now()->timestamp,
                            'type' => 'text',
                            'text' => ['body' => $texto],
                        ]],
                    ],
                ]],
            ]],
        ])->assertOk();
    }

    private function conversacion(): WhatsAppConversation
    {
        return WhatsAppConversation::where('instance_id', $this->instance->id)->firstOrFail();
    }

    // ── Sin la extensión, nada cambia ───────────────────────────────────────

    public function test_sin_instalar_no_se_marca_nada(): void
    {
        $this->recibir('son unos ladrones, esto es una estafa');

        $this->assertNull($this->conversacion()->sentiment_level);
    }

    public function test_instalada_pero_apagada_tampoco_marca(): void
    {
        $this->instalar()->update(['enabled' => false]);

        $this->recibir('son unos ladrones, esto es una estafa');

        $this->assertNull($this->conversacion()->sentiment_level);
    }

    // ── La calibración: tener un problema no es estar enfadado ──────────────

    public function test_quien_reporta_una_averia_con_calma_sigue_en_verde(): void
    {
        $this->instalar();

        $this->recibir('Buenos días, no tengo internet desde anoche');

        $this->assertSame(Lectura::VERDE, $this->conversacion()->sentiment_level);
    }

    public function test_una_consulta_normal_no_enciende_nada(): void
    {
        $this->instalar();

        $this->recibir('quiero saber el valor de mi factura de este mes');

        $this->assertSame(Lectura::VERDE, $this->conversacion()->sentiment_level);
    }

    /**
     * Mucha gente mayor escribe entera en mayúsculas por costumbre, y en
     * Colombia son una parte nada pequeña de quien escribe a un ISP. Tratarlo
     * como enfado los pintaría de rojo a todos por escribir como siempre.
     */
    public function test_escribir_en_mayusculas_no_es_estar_enfadado(): void
    {
        $this->instalar();

        $this->recibir('BUENOS DIAS SENORES POR FAVOR NECESITO AYUDA CON MI SERVICIO');

        $this->assertSame(Lectura::VERDE, $this->conversacion()->sentiment_level);
    }

    // ── Lo que sí tiene que encender ────────────────────────────────────────

    public function test_el_insulto_pone_rojo(): void
    {
        $this->instalar();

        $this->recibir('son unos ladrones, esto es una estafa');

        $this->assertSame(Lectura::ROJO, $this->conversacion()->sentiment_level);
    }

    /**
     * Quien nombra la SIC no se está desahogando: está avisando de lo que va a
     * hacer. Es la señal más fuerte del léxico y la que menos se puede perder.
     */
    public function test_mencionar_una_via_legal_pone_rojo(): void
    {
        $this->instalar();

        $this->recibir('voy a poner una tutela y una queja en la SIC');

        $conversacion = $this->conversacion();

        $this->assertSame(Lectura::ROJO, $conversacion->sentiment_level);
        $this->assertStringContainsString('legal', $conversacion->sentiment_reason);
    }

    public function test_hablar_de_cancelar_pone_rojo(): void
    {
        $this->instalar();

        $this->recibir('quiero cancelar el servicio ya mismo');

        $this->assertSame(Lectura::ROJO, $this->conversacion()->sentiment_level);
    }

    /**
     * La señal que justifica que esto no sea sólo análisis de sentimiento: está
     * escrita con toda educación y es de las más fiables que hay.
     */
    public function test_insistir_sube_a_amarillo_sin_una_sola_palabra_fea(): void
    {
        $this->instalar();

        $this->recibir('Es la tercera vez que escribo y nadie me responde');

        $conversacion = $this->conversacion();

        $this->assertContains($conversacion->sentiment_level, [Lectura::AMARILLO, Lectura::ROJO]);
        $this->assertNotSame(Lectura::VERDE, $conversacion->sentiment_level);
    }

    /**
     * Ni una palabra del léxico: sólo tres mensajes seguidos sin que nadie
     * conteste. Esto se cuenta, no se lee.
     */
    public function test_varios_mensajes_seguidos_sin_respuesta_encienden_el_semaforo(): void
    {
        $this->instalar();

        $this->recibir('hola, buenos dias');
        $this->recibir('me pueden ayudar con una consulta');
        $this->recibir('alguien ahi');

        $this->assertNotSame(Lectura::VERDE, $this->conversacion()->sentiment_level);
    }

    // ── Español: la negación ────────────────────────────────────────────────

    public function test_la_negacion_invierte_la_polaridad(): void
    {
        $this->instalar();

        $this->recibir('no me sirvió para nada lo que me dijeron');

        $this->assertLessThan(0, $this->conversacion()->sentiment_score);
    }

    /**
     * El caso que más se le atraganta a un léxico ingenuo: el `ninguna` niega a
     * `queja`, no a `excelente`. Están en frases distintas.
     */
    public function test_la_negacion_no_cruza_la_coma(): void
    {
        $this->instalar();

        $this->recibir('no tengo ninguna queja, todo excelente');

        $conversacion = $this->conversacion();

        $this->assertSame(Lectura::VERDE, $conversacion->sentiment_level);
        $this->assertGreaterThan(0, $conversacion->sentiment_score);
    }

    // ── La trayectoria ──────────────────────────────────────────────────────

    /**
     * Se enfada rápido y se calma despacio. Sin esta asimetría, un cliente
     * furioso al que le dicen "ya lo reviso" y responde "gracias" volvería a
     * verde de golpe, y quien tomara el chat después entraría a ciegas.
     */
    public function test_un_gracias_no_borra_el_enfado_de_golpe(): void
    {
        $this->instalar();

        $this->recibir('son unos ladrones, esto es una estafa');
        $this->assertSame(Lectura::ROJO, $this->conversacion()->sentiment_level);

        $this->recibir('gracias');

        $this->assertNotSame(Lectura::VERDE, $this->conversacion()->sentiment_level);
    }

    // ── El freno del agente ─────────────────────────────────────────────────

    public function test_la_correccion_a_mano_no_se_pisa(): void
    {
        $this->instalar();

        $this->recibir('hola');

        $this->conversacion()->update([
            'sentiment_level' => Lectura::ROJO,
            'sentiment_source' => Lectura::ORIGEN_MANUAL,
            'sentiment_locked_by' => $this->admin->id,
        ]);

        $this->recibir('muchas gracias, quedó perfecto');

        $conversacion = $this->conversacion();

        $this->assertSame(Lectura::ROJO, $conversacion->sentiment_level);
        $this->assertSame(Lectura::ORIGEN_MANUAL, $conversacion->sentiment_source);
    }

    // ── Ajustes ─────────────────────────────────────────────────────────────

    public function test_las_palabras_de_la_empresa_cuentan(): void
    {
        $this->instalar(['palabras_rojas' => "router zombie\nplan corporativo"]);

        $this->recibir('otra vez el router zombie');

        $this->assertSame(Lectura::ROJO, $this->conversacion()->sentiment_level);
    }

    public function test_los_ajustes_fuera_de_rango_se_recortan(): void
    {
        $extension = app(\App\Extensions\ExtensionRegistry::class)->find('sentiment_traffic_light');

        $limpios = $extension->sanitizeSettings([
            'sensibilidad' => 'altisimo',
            'ventana_mensajes' => 500,
            'palabras_rojas' => str_repeat('a', 5000),
            'campo_inventado' => 'x',
        ], $this->company->id);

        $this->assertSame('medio', $limpios['sensibilidad']);
        $this->assertSame(20, $limpios['ventana_mensajes']);
        $this->assertSame(2000, mb_strlen($limpios['palabras_rojas']));
        $this->assertArrayNotHasKey('campo_inventado', $limpios);
    }

    // ── Aislamiento ─────────────────────────────────────────────────────────

    public function test_no_toca_las_conversaciones_de_otra_empresa(): void
    {
        $this->instalar();

        $otra = Company::create(['name' => 'Otra', 'slug' => 'otra', 'active' => true]);

        $suya = Instance::create([
            'company_id' => $otra->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Suya',
            'phone_number_id' => '9999999999',
            'waba_id' => '8888888888',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-otra',
        ]);

        $this->recibir('son unos ladrones, esto es una estafa', $suya, '573001111111');

        $ajena = WhatsAppConversation::where('instance_id', $suya->id)->firstOrFail();

        $this->assertNull($ajena->sentiment_level);
    }
}
