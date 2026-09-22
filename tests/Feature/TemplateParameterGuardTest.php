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

    /**
     * Y al revés: la plantilla no lleva archivo y el envío le adjunta uno.
     *
     * Meta lo rechaza con un 132018 —«Template does not contain title
     * component»— y eso llega al operador como «no se pudo enviar la factura»,
     * sin decirle qué mirar. Le pasó a Enternet el 22-sep-2026 con su plantilla
     * de tirillas: marcada en Integra como «con documento» y aprobada en Meta
     * sin encabezado. Son dos sistemas y sólo uno de los dos lo sabía.
     *
     * @test
     */
    public function un_archivo_en_una_plantilla_sin_encabezado_no_llega_a_meta(): void
    {
        // Una plantilla sin componente HEADER: sólo cuerpo.
        $this->fakeGraph([[
            'id' => 'tpl-2',
            'name' => 'tirillas',
            'language' => 'es_CO',
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Gracias por tu pago.']],
        ]]);

        $resultado = $this->guard()->check($this->instancia(), 'tirillas', 'es_CO', [
            ['type' => 'header', 'parameters' => [[
                'type' => 'document',
                'document' => ['link' => 'https://s3.test/Recibo_1.pdf', 'filename' => 'Recibo_1.pdf'],
            ]]],
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('template_header_not_expected', $resultado['code']);
        $this->assertStringContainsString('no tiene encabezado', $resultado['error']);
        $this->assertStringContainsString('un documento', $resultado['error']);
    }

    /**
     * Pero un encabezado de TEXTO no se toca.
     *
     * Ahí no hay archivo de más: el desajuste de variables de texto lo cubre
     * Meta con un 132000 y bloquear aquí sería frenar envíos que sí salen.
     *
     * @test
     */
    public function un_encabezado_de_texto_sigue_pasando(): void
    {
        $this->fakeGraph($this->catalogo('TEXT'));

        $resultado = $this->guard()->check($this->instancia(), 'aviso_pago', 'es', [
            ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'Septiembre']]],
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Daniela']]],
        ]);

        $this->assertTrue($resultado['ok']);
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

    /**
     * El cuerpo compuesto: lo que el cliente va a leer en su teléfono.
     *
     * Sin esto, los envíos que entran por la API —los del ERP— se guardaban como
     * «[Plantilla: aviso_pago]». En Conecta Comunicaciones el ERP llevaba días
     * mandando los parámetros descolocados, así que a los clientes les llegaba
     * «tu factura ha sido generada bajo el número **y la fecha de vencimiento
     * es 2026-09-27**», y en el CRM no se veía porque la burbuja sólo decía el
     * nombre de la plantilla. Con el texto compuesto se ve el primer día.
     */
    public function test_compone_el_cuerpo_con_los_parametros(): void
    {
        $this->fakeGraph($this->catalogo());

        $texto = $this->guard()->preview($this->instancia(), 'aviso_pago', 'es', [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'María']]],
        ]);

        $this->assertSame('Hola María, tu factura está lista.', $texto);
    }

    /**
     * Un hueco sin valor se deja a la vista.
     *
     * Si el ERP manda un parámetro de menos, el `{{2}}` en el texto es la señal
     * de que falta: sustituirlo por vacío dejaría una frase que se lee bien y
     * esconde el error, que es justo cómo se pierden semanas.
     */
    public function test_un_parametro_que_falta_se_nota(): void
    {
        $this->fakeGraph([[
            'id' => 'tpl-2',
            'name' => 'dos_huecos',
            'language' => 'es',
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, tu factura {{2}} está lista.']],
        ]]);

        $texto = $this->guard()->preview($this->instancia(), 'dos_huecos', 'es', [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'María']]],
        ]);

        $this->assertSame('Hola María, tu factura {{2}} está lista.', $texto);
    }

    /**
     * Sin catálogo no se inventa un texto.
     *
     * Quien llama decide el respaldo —el nombre de la plantilla— y así una caída
     * de Graph no deja media frase guardada como si fuera lo que se envió.
     */
    public function test_sin_catalogo_no_hay_preview(): void
    {
        $this->fakeGraph([]);

        $this->assertNull($this->guard()->preview($this->instancia(), 'no_existe', 'es', []));
    }
}
