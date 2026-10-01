<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Services\TemplateParameterGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lo que el guardarraíl de plantillas no miraba y Meta sí: datos con saltos de
 * línea o vacíos, parámetros con nombre, encabezados de texto con variable,
 * botones dinámicos, plantillas pausadas, el idioma pedido y los catálogos de
 * más de una página. Todos ellos salían con 200 y fallaban después, o fallaban
 * sin que el chat dejara siquiera rellenar el dato.
 */
class PlantillasConParametrosDeMetaTest extends TestCase
{
    use RefreshDatabase;

    private function instancia(string $waba = 'waba-1', string $token = 'token-1'): Instance
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet-'.Str::random(6), 'active' => true]);

        return Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => (string) random_int(100000000, 999999999),
            'waba_id' => $waba,
            'type' => 'meta',
            'active' => true,
            'access_token' => $token,
        ]);
    }

    private function plantilla(array $components, array $extra = []): array
    {
        return array_merge([
            'id' => 'tpl-'.Str::random(4),
            'name' => 'aviso',
            'language' => 'es',
            'status' => 'APPROVED',
            'category' => 'UTILITY',
            'components' => $components,
        ], $extra);
    }

    /** Un catálogo fijo para todo el test, con una sola respuesta de Graph. */
    private function catalogo(array $plantillas): void
    {
        Http::fake(fn () => Http::response(['data' => $plantillas], 200));
    }

    private function guard(): TemplateParameterGuard
    {
        return app(TemplateParameterGuard::class);
    }

    // ── Saneo ───────────────────────────────────────────────────────────────

    /**
     * Meta rechaza (132018) un dato con saltos de línea, tabuladores o más de
     * cuatro espacios seguidos. Lo normal es una dirección pegada en dos
     * líneas: se aplana para todos los caminos, no sólo para las campañas.
     */
    public function test_los_saltos_de_linea_y_los_espacios_de_mas_se_aplanan(): void
    {
        $this->catalogo([]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso', 'es', [
            ['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => "Calle 10\r\n# 20-30\tPiso 2      Medellín  "],
            ]],
        ]);

        $this->assertTrue($resultado['ok']);
        $this->assertSame('Calle 10 # 20-30 Piso 2 Medellín', $resultado['components'][0]['parameters'][0]['text']);
    }

    public function test_un_dato_vacio_se_dice_antes_de_enviar(): void
    {
        $this->catalogo([]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso', 'es', [
            ['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => 'Marta'],
                ['type' => 'text', 'text' => " \n "],
            ]],
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('template_parameter_empty', $resultado['code']);
        $this->assertStringContainsString('{{2}}', $resultado['error']);
        $this->assertStringContainsString('del cuerpo', $resultado['error']);
    }

    // ── Caché del media ─────────────────────────────────────────────────────

    /**
     * Un mal minuto de Graph no es un archivo borrado. Antes un 500 al pedir la
     * ficha del media se guardaba una hora como «ya no existe» y bloqueaba todos
     * los envíos con ese archivo.
     */
    public function test_un_fallo_pasajero_al_mirar_el_media_no_bloquea_ni_se_recuerda(): void
    {
        $definicion = $this->plantilla([
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Hola'],
        ]);
        $respuestasDelMedia = [500, 404];

        Http::fake(function (Request $request) use ($definicion, &$respuestasDelMedia) {
            if (str_contains($request->url(), 'message_templates')) {
                return Http::response(['data' => [$definicion]], 200);
            }

            $estado = array_shift($respuestasDelMedia) ?? 404;

            return Http::response(['error' => ['message' => 'x', 'code' => $estado === 404 ? 100 : 1]], $estado);
        });

        $instancia = $this->instancia();
        $componentes = [['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['id' => '123456789']]]]];

        $primero = $this->guard()->check($instancia, 'aviso', 'es', $componentes);
        $this->assertTrue($primero['ok'], 'Ante la duda, dejar pasar.');

        // La segunda vez Graph contesta claro (404): si el 500 se hubiera
        // cacheado, ni siquiera se habría vuelto a preguntar.
        $segundo = $this->guard()->check($instancia, 'aviso', 'es', $componentes);
        $this->assertFalse($segundo['ok']);
        $this->assertSame('template_header_media_gone', $segundo['code']);
    }

    /**
     * Un token caducado llega como 400 con código 190: tampoco es «borrado».
     */
    public function test_un_token_caducado_no_se_toma_por_un_media_borrado(): void
    {
        $definicion = $this->plantilla([
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Hola'],
        ]);

        Http::fake(fn (Request $request) => str_contains($request->url(), 'message_templates')
            ? Http::response(['data' => [$definicion]], 200)
            : Http::response(['error' => ['message' => 'Session has expired', 'code' => 190]], 400));

        $resultado = $this->guard()->check($this->instancia(), 'aviso', 'es', [
            ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['id' => '123456789']]]],
        ]);

        $this->assertTrue($resultado['ok']);
    }

    /**
     * El media id es de la línea que lo subió. Con la clave de caché sin la
     * instancia, el «no existe» de una empresa le tapaba el archivo a otra.
     */
    public function test_el_media_de_una_linea_no_se_confunde_con_el_de_otra(): void
    {
        $definicion = $this->plantilla([
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Hola'],
        ]);

        Http::fake(function (Request $request) use ($definicion) {
            if (str_contains($request->url(), 'message_templates')) {
                return Http::response(['data' => [$definicion]], 200);
            }

            // Para la línea A el archivo no existe; para la B, sí.
            return $request->hasHeader('Authorization', 'Bearer token-a')
                ? Http::response(['error' => ['message' => 'Unsupported get request', 'code' => 100]], 400)
                : Http::response(['mime_type' => 'image/png', 'file_size' => 10, 'url' => 'https://x'], 200);
        });

        $componentes = [['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['id' => '123456789']]]]];

        $a = $this->guard()->check($this->instancia('waba-a', 'token-a'), 'aviso', 'es', $componentes);
        $b = $this->guard()->check($this->instancia('waba-b', 'token-b'), 'aviso', 'es', $componentes);

        $this->assertSame('template_header_media_gone', $a['code']);
        $this->assertTrue($b['ok'], $b['error'] ?? '');
    }

    // ── Parámetros con nombre ───────────────────────────────────────────────

    private function plantillaConNombre(): array
    {
        return $this->plantilla(
            [['type' => 'BODY', 'text' => 'Hola {{nombre}}, tu factura {{numero}} está lista, {{nombre}}.']],
            ['parameter_format' => 'NAMED']
        );
    }

    public function test_una_plantilla_con_nombre_exige_parameter_name(): void
    {
        $this->catalogo([$this->plantillaConNombre()]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso', 'es', [
            ['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => 'Marta'],
                ['type' => 'text', 'text' => 'F-100'],
            ]],
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('template_parameter_name_missing', $resultado['code']);
        $this->assertStringContainsString('{{nombre}}', $resultado['error']);
    }

    public function test_una_plantilla_con_nombre_bien_rellenada_pasa(): void
    {
        $this->catalogo([$this->plantillaConNombre()]);

        $componentes = [['type' => 'body', 'parameters' => [
            ['type' => 'text', 'parameter_name' => 'numero', 'text' => 'F-100'],
            ['type' => 'text', 'parameter_name' => 'nombre', 'text' => 'Marta'],
        ]]];

        $instancia = $this->instancia();
        $resultado = $this->guard()->check($instancia, 'aviso', 'es', $componentes);

        $this->assertTrue($resultado['ok'], $resultado['error'] ?? '');
        $this->assertSame(
            'Hola Marta, tu factura F-100 está lista, Marta.',
            $this->guard()->preview($instancia, 'aviso', 'es', $componentes)
        );
    }

    public function test_un_nombre_que_la_plantilla_no_tiene_se_nombra(): void
    {
        $this->catalogo([$this->plantillaConNombre()]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso', 'es', [
            ['type' => 'body', 'parameters' => [
                ['type' => 'text', 'parameter_name' => 'nombre', 'text' => 'Marta'],
                ['type' => 'text', 'parameter_name' => 'factura', 'text' => 'F-100'],
            ]],
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('faltan {{numero}}', $resultado['error']);
        $this->assertStringContainsString('sobran {{factura}}', $resultado['error']);
    }

    /** Sin `parameter_format` en el catálogo, se nota en las variables. */
    public function test_sin_parameter_format_se_deduce_de_las_variables(): void
    {
        $definicion = $this->plantillaConNombre();
        unset($definicion['parameter_format']);
        $this->catalogo([$definicion]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso', 'es', [
            ['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => 'Marta'],
                ['type' => 'text', 'text' => 'F-100'],
            ]],
        ]);

        $this->assertSame('template_parameter_name_missing', $resultado['code']);
    }

    // ── Encabezado de texto ─────────────────────────────────────────────────

    public function test_el_encabezado_de_texto_con_variable_se_exige(): void
    {
        $this->catalogo([$this->plantilla([
            ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Tu factura de {{1}}'],
            ['type' => 'BODY', 'text' => 'Hola {{1}}'],
        ])]);

        $instancia = $this->instancia();
        $cuerpo = ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Marta']]];

        $sin = $this->guard()->check($instancia, 'aviso', 'es', [$cuerpo]);
        $this->assertSame('template_header_text_missing', $sin['code']);

        $con = $this->guard()->check($instancia, 'aviso', 'es', [
            ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => 'septiembre']]],
            $cuerpo,
        ]);
        $this->assertTrue($con['ok'], $con['error'] ?? '');
    }

    // ── Botones ─────────────────────────────────────────────────────────────

    private function plantillaConBotones(): array
    {
        return $this->plantilla([
            ['type' => 'BODY', 'text' => 'Tu pedido salió.'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Gracias'],
                ['type' => 'URL', 'text' => 'Rastrear', 'url' => 'https://envios.test/{{1}}'],
                ['type' => 'COPY_CODE', 'text' => 'Copiar cupón'],
            ]],
        ]);
    }

    public function test_un_boton_de_url_dinamica_sin_dato_no_sale(): void
    {
        $this->catalogo([$this->plantillaConBotones()]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso', 'es', [
            ['type' => 'button', 'sub_type' => 'copy_code', 'index' => '2', 'parameters' => [
                ['type' => 'coupon_code', 'coupon_code' => 'DESC10'],
            ]],
        ]);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('template_button_missing', $resultado['code']);
        $this->assertStringContainsString('«Rastrear»', $resultado['error']);
    }

    public function test_los_botones_dinamicos_bien_rellenados_pasan(): void
    {
        $this->catalogo([$this->plantillaConBotones()]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso', 'es', [
            ['type' => 'button', 'sub_type' => 'url', 'index' => '1', 'parameters' => [['type' => 'text', 'text' => 'ABC123']]],
            ['type' => 'button', 'sub_type' => 'copy_code', 'index' => 2, 'parameters' => [['type' => 'coupon_code', 'coupon_code' => 'DESC10']]],
        ]);

        $this->assertTrue($resultado['ok'], $resultado['error'] ?? '');
    }

    /** Las de autenticación mandan el código en el botón, como sufijo de URL. */
    public function test_una_plantilla_de_autenticacion_exige_el_codigo_en_el_boton(): void
    {
        $this->catalogo([$this->plantilla([
            ['type' => 'BODY', 'text' => '{{1}} es tu código de verificación.'],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copiar código']]],
        ], ['category' => 'AUTHENTICATION'])]);

        $instancia = $this->instancia();
        $cuerpo = ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '482913']]];

        $sin = $this->guard()->check($instancia, 'aviso', 'es', [$cuerpo]);
        $this->assertSame('template_button_missing', $sin['code']);
        $this->assertStringContainsString('código de verificación', $sin['error']);

        $con = $this->guard()->check($instancia, 'aviso', 'es', [
            $cuerpo,
            ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => '482913']]],
        ]);
        $this->assertTrue($con['ok'], $con['error'] ?? '');
    }

    // ── Estado, idioma y paginación ─────────────────────────────────────────

    public function test_una_plantilla_pausada_no_se_envia(): void
    {
        $this->catalogo([$this->plantilla([['type' => 'BODY', 'text' => 'Hola']], ['status' => 'PAUSED'])]);

        $resultado = $this->guard()->check($this->instancia(), 'aviso', 'es', []);

        $this->assertFalse($resultado['ok']);
        $this->assertSame('template_not_approved', $resultado['code']);
        $this->assertStringContainsString('pausada', $resultado['error']);
    }

    /**
     * El catálogo cacheado puede decir «en revisión» de una plantilla que Meta
     * aprobó hace dos minutos. Antes de bloquear se pregunta de nuevo.
     */
    public function test_antes_de_bloquear_por_estado_se_vuelve_a_mirar_el_catalogo(): void
    {
        $llamadas = 0;

        Http::fake(function () use (&$llamadas) {
            $llamadas++;

            return Http::response(['data' => [$this->plantilla(
                [['type' => 'BODY', 'text' => 'Hola']],
                ['status' => $llamadas === 1 ? 'PENDING' : 'APPROVED']
            )]], 200);
        });

        $resultado = $this->guard()->check($this->instancia(), 'aviso', 'es', []);

        $this->assertTrue($resultado['ok'], $resultado['error'] ?? '');
        $this->assertSame(2, $llamadas);
    }

    /**
     * Antes se devolvía la primera versión del nombre aunque fuera de otro
     * idioma, y se validaba el envío contra una plantilla que no es la que sale.
     */
    public function test_se_valida_contra_la_plantilla_del_idioma_pedido(): void
    {
        $this->catalogo([
            $this->plantilla([['type' => 'BODY', 'text' => 'Hi {{1}} and {{2}}']], ['language' => 'en_US']),
            $this->plantilla([['type' => 'BODY', 'text' => 'Hola {{1}}']], ['language' => 'es_CO']),
        ]);

        $guard = $this->guard();
        $instancia = $this->instancia();

        $this->assertSame('es_CO', $guard->definition($instancia, 'aviso', 'es_CO')['language']);
        $this->assertNull($guard->definition($instancia, 'aviso', 'pt_BR'), 'Un idioma que no está no se adivina.');

        $resultado = $guard->check($instancia, 'aviso', 'es_CO', [
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Marta']]],
        ]);
        $this->assertTrue($resultado['ok'], $resultado['error'] ?? '');
    }

    public function test_el_catalogo_se_lee_entero_aunque_tenga_varias_paginas(): void
    {
        $paginas = [];

        Http::fake(function (Request $request) use (&$paginas) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $paginas[] = $query['after'] ?? 'primera';

            if (($query['after'] ?? null) === 'cursor-2') {
                return Http::response(['data' => [$this->plantilla(
                    [['type' => 'BODY', 'text' => 'Hola {{1}}']],
                    ['name' => 'de_la_segunda_pagina']
                )]], 200);
            }

            return Http::response([
                'data' => [$this->plantilla([['type' => 'BODY', 'text' => 'Otra']], ['name' => 'otra'])],
                'paging' => ['cursors' => ['after' => 'cursor-2'], 'next' => 'https://graph.facebook.com/next'],
            ], 200);
        });

        $resultado = $this->guard()->check($this->instancia(), 'de_la_segunda_pagina', 'es', []);

        $this->assertSame(['primera', 'cursor-2'], $paginas);
        $this->assertFalse($resultado['ok'], 'Sin la segunda página la plantilla no se habría validado.');
        $this->assertSame('template_body_parameters', $resultado['code']);
    }
}
