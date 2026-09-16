<?php

namespace Tests\Feature;

use App\Jobs\VectorizarDocumentoDeIa;
use App\Models\AiDocumento;
use App\Models\AiFragmento;
use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Services\WhatsAppChatAiClient;
use App\Support\Documentos\BuscarFragmentos;
use App\Support\Documentos\ConocimientoParaLaPregunta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Buscar dentro de los documentos y meterlo en el prompt.
 *
 * Es la parte que hace que subir documentos sirva de algo. Lo que se prueba
 * aquí, y en este orden de importancia:
 *
 * 1. Que **no se cuelan fragmentos de otra empresa**. Es el peor fallo posible
 *    de esta función y no lanzaría ningún error.
 * 2. Que si el modelo de vectores no está o se cae, **se sigue contestando** por
 *    palabras en vez de dejar al cliente sin respuesta.
 * 3. Que lo encontrado llega de verdad hasta n8n, con su cita delante.
 */
class BusquedaEnDocumentosTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Fibra XYZ', 'slug' => 'fibra-xyz',
            'active' => true, 'plan' => 'basico', 'ia' => 'completa',
        ]);
    }

    // ─── Con vectores ────────────────────────────────────────────────────────

    /** @test */
    public function devuelve_el_fragmento_mas_parecido(): void
    {
        $this->conVectores([
            'crédito' => [1.0, 0.0, 0.0],
            'internet' => [0.0, 1.0, 0.0],
            'sedes' => [0.0, 0.0, 1.0],
        ]);

        // La pregunta apunta al mismo sitio que «crédito».
        $this->modeloDevuelve([0.95, 0.1, 0.0]);

        $encontrados = BuscarFragmentos::para($this->company->id, '¿a qué tasa está el crédito?');

        $this->assertCount(1, $encontrados);
        $this->assertStringContainsString('crédito', $encontrados[0]['texto']);
    }

    /**
     * Una pregunta que no tiene que ver con nada no devuelve «los menos malos».
     *
     * Sin un mínimo de parecido, un «hola» arrastra los cinco fragmentos que
     * menos mal casen y el modelo acaba contestando con el reglamento a quien
     * sólo saludaba.
     *
     * @test
     */
    public function una_pregunta_que_no_viene_a_cuento_no_devuelve_nada(): void
    {
        $this->conVectores([
            'crédito' => [1.0, 0.0, 0.0],
            'internet' => [0.0, 1.0, 0.0],
        ]);

        // Perpendicular a todo lo guardado: parecido 0.
        $this->modeloDevuelve([0.0, 0.0, 1.0]);

        $this->assertSame([], BuscarFragmentos::para($this->company->id, 'hola buenas'));
    }

    /**
     * Los documentos de otra empresa no aparecen jamás.
     *
     * Aquí el aislamiento es manual y esto corre en cada mensaje entrante: un
     * trozo del tarifario de una ISP contestándole al cliente de otra es el peor
     * fallo posible de todo esto.
     *
     * @test
     */
    public function nunca_devuelve_fragmentos_de_otra_empresa(): void
    {
        $otra = Company::create([
            'name' => 'Otra ISP', 'slug' => 'otra-isp',
            'active' => true, 'plan' => 'basico', 'ia' => 'completa',
        ]);

        $documento = $this->documento($otra);

        AiFragmento::create([
            'ai_documento_id' => $documento->id,
            'company_id' => $otra->id,
            'origen' => 'secreto.pdf',
            'orden' => 0,
            'texto' => 'Las tarifas confidenciales de la otra ISP.',
            'vector' => [1.0, 0.0, 0.0],
        ]);

        $this->modeloDevuelve([1.0, 0.0, 0.0]);

        $this->assertSame([], BuscarFragmentos::para($this->company->id, 'tarifas confidenciales'));
    }

    // ─── Sin vectores ────────────────────────────────────────────────────────

    /**
     * Sin modelo configurado se busca por palabras, y se contesta igual.
     *
     * Peor —no sabe que «préstamo» y «crédito» son lo mismo— pero contesta, que
     * es mejor que no contestar.
     *
     * @test
     */
    public function sin_modelo_configurado_busca_por_palabras(): void
    {
        config(['services.embeddings.url' => null]);

        $documento = $this->documento($this->company);

        foreach ([
            'El crédito de libre inversión está al 1,2% mensual.',
            'El internet de 300 megas cuesta 89.900 pesos.',
        ] as $orden => $texto) {
            AiFragmento::create([
                'ai_documento_id' => $documento->id,
                'company_id' => $this->company->id,
                'origen' => 'tarifario.pdf',
                'orden' => $orden,
                'texto' => $texto,
                'vector' => null,
            ]);
        }

        $encontrados = BuscarFragmentos::para($this->company->id, '¿cuánto vale el internet de 300 megas?');

        $this->assertNotEmpty($encontrados);
        $this->assertStringContainsString('300 megas', $encontrados[0]['texto']);
    }

    /**
     * Si el modelo se cae, la búsqueda no se cae con él.
     *
     * Una excepción aquí tumbaría la respuesta al cliente por no poder hacer una
     * búsqueda que es una mejora, no un requisito.
     *
     * @test
     */
    public function si_el_modelo_se_cae_se_sigue_buscando_por_palabras(): void
    {
        config(['services.embeddings.url' => 'http://ollama.test']);
        Http::fake(['*' => Http::response('boom', 500)]);

        $documento = $this->documento($this->company);

        AiFragmento::create([
            'ai_documento_id' => $documento->id,
            'company_id' => $this->company->id,
            'origen' => 'tarifario.pdf',
            'orden' => 0,
            'texto' => 'El internet de 300 megas cuesta 89.900 pesos.',
            'vector' => null,
        ]);

        $encontrados = BuscarFragmentos::para($this->company->id, 'precio del internet de 300 megas');

        $this->assertNotEmpty($encontrados);
    }

    // ─── Lo que acaba en el prompt ───────────────────────────────────────────

    /**
     * El fragmento llega con su cita delante y sin tapar lo escrito a mano.
     *
     * La cita no es decoración: es lo que permite que la IA diga de dónde lo
     * sacó, y lo que deja a la empresa auditar una respuesta mala.
     *
     * @test
     */
    public function el_conocimiento_lleva_lo_escrito_a_mano_y_la_cita(): void
    {
        config(['services.embeddings.url' => null]);

        $documento = $this->documento($this->company);

        AiFragmento::create([
            'ai_documento_id' => $documento->id,
            'company_id' => $this->company->id,
            'origen' => 'Tarifario 2026.xlsx · hoja «Planes hogar» · fila 4',
            'orden' => 0,
            'texto' => 'Plan: 300 megas · Precio mensual: $89.900',
            'vector' => null,
        ]);

        $conocimiento = ConocimientoParaLaPregunta::para(
            $this->company->id,
            '¿cuánto cuesta el plan de 300 megas?',
            'Atendemos de lunes a viernes.'
        );

        $this->assertStringContainsString('Atendemos de lunes a viernes.', $conocimiento);
        $this->assertStringContainsString('[Tarifario 2026.xlsx · hoja «Planes hogar» · fila 4]', $conocimiento);
        $this->assertStringContainsString('Precio mensual: $89.900', $conocimiento);
    }

    /**
     * Y sale de verdad por HTTP hacia n8n, en el campo que ya viaja.
     *
     * Este es el test que habría cazado el fallo del 16-sep: que el dato salga
     * bien de una clase no significa que llegue al modelo.
     *
     * @test
     */
    public function el_fragmento_sale_por_http_hacia_n8n(): void
    {
        config([
            'services.embeddings.url' => null,
            'services.ai_chat.webhook_url' => 'https://n8n.example.test/webhook/chat',
            'services.ai_chat.api_key' => 'n8n_llave',
        ]);

        [$instancia, $conversacion] = $this->lineaYConversacion();

        CompanyIntegration::create([
            'company_id' => $this->company->id,
            'key' => CompanyIntegration::KEY_AI_CHAT,
            'enabled' => true,
        ]);

        $documento = $this->documento($this->company);

        AiFragmento::create([
            'ai_documento_id' => $documento->id,
            'company_id' => $this->company->id,
            'origen' => 'Reglamento.pdf · pág. 12',
            'orden' => 0,
            'texto' => 'La permanencia mínima del servicio es de doce meses.',
            'vector' => null,
        ]);

        Http::fake(['*' => Http::response(['status' => 'done', 'answer' => 'Son doce meses.'], 200)]);

        (new WhatsAppChatAiClient)->ask(
            $instancia, $conversacion, '¿cuál es la permanencia mínima?', 'wamid.IN1'
        );

        Http::assertSent(function ($request) {
            $conocimiento = $request->data()['asistente']['conocimiento'] ?? '';

            return str_contains($conocimiento, 'permanencia mínima del servicio')
                && str_contains($conocimiento, '[Reglamento.pdf · pág. 12]');
        });
    }

    // ─── El job que calcula los vectores ─────────────────────────────────────

    /** @test */
    public function el_job_vectoriza_y_deja_el_documento_listo(): void
    {
        config(['services.embeddings.url' => 'http://ollama.test']);

        $documento = $this->documento($this->company, 'procesando');

        foreach (range(0, 2) as $orden) {
            AiFragmento::create([
                'ai_documento_id' => $documento->id,
                'company_id' => $this->company->id,
                'orden' => $orden,
                'texto' => "Fragmento {$orden} del reglamento.",
                'vector' => null,
            ]);
        }

        Http::fake(['*' => Http::response(['embeddings' => array_fill(0, 3, [0.1, 0.2, 0.3])], 200)]);

        (new VectorizarDocumentoDeIa($documento->id))->handle();

        $this->assertSame(0, AiFragmento::where('ai_documento_id', $documento->id)->whereNull('vector')->count());

        // Y se guardó de forma que al leerlo vuelve a ser el mismo vector. El
        // job escribe con `update()`, que se salta el cast: si se le olvida
        // empaquetar, esto se guarda igual y vuelve como ruido, sin fallar y
        // sin un solo error en el log.
        $vector = AiFragmento::where('ai_documento_id', $documento->id)->first()->vector;

        $this->assertCount(3, $vector);
        $this->assertEqualsWithDelta(0.1, $vector[0], 0.0001);
        $this->assertEqualsWithDelta(0.3, $vector[2], 0.0001);

        // La primera tanda no cierra: es la segunda, la que ya no encuentra
        // pendientes, la que marca el documento como listo.
        (new VectorizarDocumentoDeIa($documento->id))->handle();

        $this->assertSame('listo', $documento->refresh()->estado);
    }

    /**
     * Si el modelo no da un solo vector, el documento queda listo igualmente.
     *
     * Sin vectores se busca por palabras. Dejarlo en «fallido» escondería un
     * documento que sí sirve, y reintentar en bucle sólo machaca un modelo que
     * ya está caído.
     *
     * @test
     */
    public function un_documento_sin_vectores_queda_utilizable(): void
    {
        config(['services.embeddings.url' => 'http://ollama.test']);
        Http::fake(['*' => Http::response('boom', 500)]);

        $documento = $this->documento($this->company, 'procesando');

        AiFragmento::create([
            'ai_documento_id' => $documento->id,
            'company_id' => $this->company->id,
            'orden' => 0,
            'texto' => 'Algo que no se pudo vectorizar.',
            'vector' => null,
        ]);

        (new VectorizarDocumentoDeIa($documento->id))->handle();

        $this->assertSame('listo', $documento->refresh()->estado);
    }

    // ─── Saber si esto sirve ─────────────────────────────────────────────────

    /**
     * Contestar cuenta como uso del documento.
     *
     * Es el único número que distingue un documento que trabaja de uno que
     * nadie consulta — y sin él, los dos se ven igual en la pantalla.
     *
     * @test
     */
    public function contestar_cuenta_como_uso_del_documento(): void
    {
        config(['services.embeddings.url' => null]);

        $documento = $this->documento($this->company);

        AiFragmento::create([
            'ai_documento_id' => $documento->id,
            'company_id' => $this->company->id,
            'origen' => 'tarifario.pdf',
            'orden' => 0,
            'texto' => 'El internet de 300 megas cuesta 89.900 pesos.',
            'vector' => null,
        ]);

        // `refresh()` porque `create()` devuelve el modelo con lo que se le
        // pasó, y `usos` lo pone el valor por defecto de la columna.
        $this->assertSame(0, $documento->refresh()->usos);

        BuscarFragmentos::para($this->company->id, 'precio del internet de 300 megas');

        $this->assertSame(1, $documento->refresh()->usos);
        $this->assertNotNull($documento->ultimo_uso_at);
    }

    /**
     * Probar desde la pantalla NO cuenta como uso.
     *
     * Si contara, el admin infla con sus propias pruebas justo el número al que
     * luego mira para decidir si un documento sirve.
     *
     * @test
     */
    public function probar_desde_la_pantalla_no_infla_el_contador(): void
    {
        config(['services.embeddings.url' => null]);

        $documento = $this->documento($this->company);

        AiFragmento::create([
            'ai_documento_id' => $documento->id,
            'company_id' => $this->company->id,
            'origen' => 'tarifario.pdf',
            'orden' => 0,
            'texto' => 'El internet de 300 megas cuesta 89.900 pesos.',
            'vector' => null,
        ]);

        BuscarFragmentos::para($this->company->id, 'precio del internet', 5, apuntar: false);

        $this->assertSame(0, $documento->refresh()->usos);
        $this->assertNull($documento->ultimo_uso_at);
    }

    /** Un documento de hace más de medio año pide revisión. */
    public function test_un_documento_viejo_se_marca_para_revisar(): void
    {
        $reciente = $this->documento($this->company);
        $viejo = $this->documento($this->company);
        $viejo->forceFill(['created_at' => now()->subMonths(8)])->save();

        $this->assertFalse($reciente->esAntiguo());
        $this->assertTrue($viejo->refresh()->esAntiguo(), 'Un tarifario de hace ocho meses cita precios que ya no existen.');
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    /** Guarda un fragmento por cada término, con el vector que se le pase. */
    private function conVectores(array $porTexto): void
    {
        config(['services.embeddings.url' => 'http://ollama.test']);

        $documento = $this->documento($this->company);
        $orden = 0;

        foreach ($porTexto as $palabra => $vector) {
            AiFragmento::create([
                'ai_documento_id' => $documento->id,
                'company_id' => $this->company->id,
                'origen' => 'manual.pdf',
                'orden' => $orden++,
                'texto' => "Todo lo que hay que saber sobre {$palabra}.",
                'vector' => $vector,
            ]);
        }
    }

    private function modeloDevuelve(array $vector): void
    {
        Http::fake(['*' => Http::response(['embeddings' => [$vector]], 200)]);
    }

    private function documento(Company $company, string $estado = 'listo'): AiDocumento
    {
        return AiDocumento::create([
            'company_id' => $company->id,
            'nombre' => 'manual.pdf',
            'extension' => 'pdf',
            'bytes' => 1024,
            'ruta' => "empresa-{$company->id}/manual.pdf",
            'estado' => $estado,
            'fragmentos' => 0,
        ]);
    }

    /** @return array{0: Instance, 1: WhatsAppConversation} */
    private function lineaYConversacion(): array
    {
        $instancia = Instance::create([
            'company_id' => $this->company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'access_token' => 'token-meta',
            'active' => true,
        ]);

        $conversacion = WhatsAppConversation::create([
            'instance_id' => $instancia->id,
            'wa_id' => '573007852081',
            'phone_number' => '573007852081',
            'name' => 'Katherine',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        return [$instancia, $conversacion];
    }
}
