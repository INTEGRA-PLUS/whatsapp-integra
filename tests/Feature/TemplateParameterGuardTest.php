<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Services\TemplateParameterGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El encabezado de una plantilla, revisado antes de llegar a Meta.
 *
 * El caso real: el CRM envía una plantilla con encabezado de imagen, Meta
 * responde 200 con wamid y minutos después manda por webhook un
 * "132012 header: Format mismatch, expected IMAGE, received UNKNOWN". Para
 * quien lanzó el aviso fue un éxito; para el cliente, nada. Aquí se comprueba
 * que ese envío ya no sale, que el motivo se dice en el momento, y que los
 * errores de forma que sí se pueden arreglar solos se arreglan.
 */
class TemplateParameterGuardTest extends TestCase
{
    use RefreshDatabase;

    private function instancia(): Instance
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet-' . Str::random(4), 'active' => true]);

        return Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => '1177962515404155',
            'waba_id' => 'waba-1',
            'type' => 'meta',
            'active' => true,
            'access_token' => 'token-waba-1',
        ]);
    }

    /** Catálogo con una plantilla de encabezado IMAGE y una variable en el cuerpo. */
    private function catalogo(string $headerFormat = 'IMAGE'): array
    {
        return [[
            'id' => 'tpl-1',
            'name' => 'aviso_pago',
            'language' => 'es',
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'components' => [
                ['type' => 'HEADER', 'format' => $headerFormat],
                ['type' => 'BODY', 'text' => 'Hola {{1}}, tu factura está lista.'],
            ],
        ]];
    }

    private function fakeGraph(array $catalogo, array $extra = []): void
    {
        Http::fake(array_merge([
            '*/message_templates*' => Http::response(['data' => $catalogo], 200),
            '*/media' => Http::response(['id' => '998877665544'], 200),
            // Ficha del media del encabezado: existe y es un png.
            '*/123456789' => Http::response(['mime_type' => 'image/png', 'file_size' => 2048, 'url' => 'https://lookaside.fb/x'], 200),
            'https://graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.X']]], 200),
        ], $extra));
    }

    private function guard(): TemplateParameterGuard
    {
        return app(TemplateParameterGuard::class);
    }

    public function test_un_encabezado_de_imagen_ausente_no_llega_a_meta(): void
    {
        $this->fakeGraph($this->catalogo());

        $resultado = $this->guard()->check($this->instancia(), 'aviso_pago', 'es', [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Daniela']]],
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('template_header_missing', $resultado['code']);
        $this->assertStringContainsString('encabezado', $resultado['error']);
    }

    public function test_un_texto_donde_se_espera_imagen_se_explica_en_castellano(): void
    {
        $this->fakeGraph($this->catalogo());

        $resultado = $this->guard()->check($this->instancia(), 'aviso_pago', 'es', [
            ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'Septiembre']]],
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Daniela']]],
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('template_header_type_mismatch', $resultado['code']);
        $this->assertStringContainsString('TEXT', $resultado['error']);
    }

    /**
     * El error de forma más común, y el que Meta reporta como "received UNKNOWN":
     * el tipo en mayúscula. No hay nada que decidir, se corrige y se envía.
     */
    public function test_el_tipo_en_mayuscula_se_corrige_solo(): void
    {
        $this->fakeGraph($this->catalogo());

        $resultado = $this->guard()->check($this->instancia(), 'aviso_pago', 'es', [
            ['type' => 'HEADER', 'parameters' => [['type' => 'IMAGE', 'image' => ['id' => '123456789']]]],
            ['type' => 'BODY', 'parameters' => [['type' => 'TEXT', 'text' => 'Daniela']]],
        ]);

        $this->assertTrue($resultado['ok'], $resultado['error'] ?? '');
        $this->assertSame('header', $resultado['components'][0]['type']);
        $this->assertSame('image', $resultado['components'][0]['parameters'][0]['type']);
    }

    public function test_el_handle_de_creacion_no_vale_como_media_id(): void
    {
        $this->fakeGraph($this->catalogo());

        $resultado = $this->guard()->check($this->instancia(), 'aviso_pago', 'es', [
            ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['id' => 'h:ARZ0k9dfLKJ23']]]],
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Daniela']]],
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('template_header_handle', $resultado['code']);
    }

    public function test_un_media_id_borrado_por_meta_se_avisa_antes_de_enviar(): void
    {
        $this->fakeGraph($this->catalogo(), [
            '*/123456789' => Http::response(['error' => ['message' => 'not found']], 404),
        ]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso_pago', 'es', [
            ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['id' => '123456789']]]],
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Daniela']]],
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('template_header_media_gone', $resultado['code']);
    }

    public function test_una_url_que_devuelve_html_no_es_una_imagen(): void
    {
        $this->fakeGraph($this->catalogo(), [
            'https://erp.example.com/*' => Http::response('<html><body>Sesión expirada</body></html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso_pago', 'es', [
            ['type' => 'header', 'parameters' => [
                ['type' => 'image', 'image' => ['link' => 'https://erp.example.com/factura.png']],
            ]],
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Daniela']]],
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('template_header_link_wrong_type', $resultado['code']);
    }

    /**
     * Un link bueno deja de ser un link: se sube a Meta y el envío pasa a
     * referenciar el media id, sin depender de que Graph alcance nuestra URL.
     */
    public function test_una_imagen_accesible_se_sube_y_se_envia_por_id(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->fakeGraph($this->catalogo(), [
            'https://cdn.example.com/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso_pago', 'es', [
            ['type' => 'header', 'parameters' => [
                ['type' => 'image', 'image' => ['link' => 'https://cdn.example.com/factura.png']],
            ]],
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Daniela']]],
        ]);

        $this->assertTrue($resultado['ok'], $resultado['error'] ?? '');
        $this->assertSame(['id' => '998877665544'], $resultado['components'][0]['parameters'][0]['image']);
    }

    public function test_faltan_datos_en_el_cuerpo(): void
    {
        $this->fakeGraph($this->catalogo('NONE'));

        $resultado = $this->guard()->check($this->instancia(), 'aviso_pago', 'es', []);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('template_body_parameters', $resultado['code']);
        $this->assertStringContainsString('1 dato', $resultado['error']);
    }

    /**
     * Un guardarraíl que bloquea envíos buenos porque Graph tuvo un mal minuto
     * es peor que el problema que resuelve.
     */
    public function test_si_meta_no_contesta_el_envio_sigue_su_curso(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'try again']], 500)]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso_pago', 'es', [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Daniela']]],
        ]);

        $this->assertTrue($resultado['ok']);
    }

    public function test_una_plantilla_que_no_esta_en_el_catalogo_no_se_bloquea(): void
    {
        $this->fakeGraph([]);

        $resultado = $this->guard()->check($this->instancia(), 'otra_plantilla', 'es', []);

        $this->assertTrue($resultado['ok']);
    }
}
