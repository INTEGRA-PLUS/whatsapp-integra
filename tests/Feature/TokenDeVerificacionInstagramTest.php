<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El respaldo del token de verificación de Instagram.
 *
 * Existe esta prueba por un fallo concreto: `config/services.php` usaba
 * `env('META_IG_WEBHOOK_VERIFY_TOKEN', env('META_WEBHOOK_VERIFY_TOKEN'))`, que
 * parece hacer lo que dice y no lo hace. El bloque `x-app-env` del compose
 * declara `${META_IG_WEBHOOK_VERIFY_TOKEN:-}`, así que cuando la variable no
 * está en el `.env.docker` **llega al contenedor definida y vacía**, no
 * ausente. `env()` sólo recurre a su valor por defecto si la clave no existe.
 *
 * Resultado: devolvía cadena vacía, el respaldo nunca se activaba y el panel de
 * Meta no podía verificar el webhook (10-sep-2026).
 *
 * Se prueba leyendo el archivo de configuración de verdad y no `config()`,
 * porque lo que falló fue precisamente la expresión de ese archivo, y el
 * framework ya tiene la config resuelta cuando arranca la prueba.
 */
class TokenDeVerificacionInstagramTest extends TestCase
{
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['META_IG_WEBHOOK_VERIFY_TOKEN', 'META_WEBHOOK_VERIFY_TOKEN'] as $clave) {
            $this->original[$clave] = $_ENV[$clave] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $clave => $valor) {
            if ($valor === null) {
                unset($_ENV[$clave], $_SERVER[$clave]);
                putenv($clave);

                continue;
            }

            $this->ponerEnv($clave, $valor);
        }

        parent::tearDown();
    }

    /**
     * El caso que rompió producción: definida pero vacía.
     */
    public function test_un_token_vacio_cae_en_el_de_whatsapp(): void
    {
        $this->ponerEnv('META_IG_WEBHOOK_VERIFY_TOKEN', '');
        $this->ponerEnv('META_WEBHOOK_VERIFY_TOKEN', 'el-de-whatsapp');

        $this->assertSame('el-de-whatsapp', $this->tokenResuelto());
    }

    public function test_sin_la_variable_tambien_cae_en_el_de_whatsapp(): void
    {
        unset($_ENV['META_IG_WEBHOOK_VERIFY_TOKEN'], $_SERVER['META_IG_WEBHOOK_VERIFY_TOKEN']);
        putenv('META_IG_WEBHOOK_VERIFY_TOKEN');
        $this->ponerEnv('META_WEBHOOK_VERIFY_TOKEN', 'el-de-whatsapp');

        $this->assertSame('el-de-whatsapp', $this->tokenResuelto());
    }

    /**
     * Y cuando sí se declara uno propio, manda ese.
     */
    public function test_un_token_propio_gana(): void
    {
        $this->ponerEnv('META_IG_WEBHOOK_VERIFY_TOKEN', 'el-de-instagram');
        $this->ponerEnv('META_WEBHOOK_VERIFY_TOKEN', 'el-de-whatsapp');

        $this->assertSame('el-de-instagram', $this->tokenResuelto());
    }

    private function tokenResuelto(): ?string
    {
        // Se relee el archivo: es la expresión de dentro lo que se está probando.
        $services = require config_path('services.php');

        return $services['meta']['instagram']['verify_token'];
    }

    private function ponerEnv(string $clave, string $valor): void
    {
        $_ENV[$clave] = $valor;
        $_SERVER[$clave] = $valor;
        putenv("{$clave}={$valor}");
    }
}
