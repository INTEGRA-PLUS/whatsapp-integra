<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * El fallo de "la variable llega vacía, no ausente" — cazado antes de desplegar.
 *
 * El bloque `x-app-env` de docker-compose.yml declara muchas variables como
 * `${MI_VAR:-}`. Eso hace que lleguen al contenedor **definidas y vacías**, y
 * `env()` sólo recurre a su segundo argumento cuando la clave **no existe**.
 * Resultado: `env('MI_VAR', 'respaldo')` devuelve cadena vacía y el respaldo no
 * se activa nunca, aunque leyendo el código parezca que sí.
 *
 * Ha mordido tres veces:
 *
 * - `META_IG_WEBHOOK_VERIFY_TOKEN` — el panel de Meta no podía verificar el
 *   webhook de Instagram (10-sep-2026).
 * - `META_APP_SECRETS` — el respaldo a `META_APP_SECRET` estaba muerto: una
 *   instalación con sólo el singular respondía 403 a todos los webhooks.
 * - Y la forma general, con variables que directamente no se añadieron al
 *   compose y por eso no llegaban.
 *
 * La regla: si el compose la declara vacía, en config se lee con `?:`, no con el
 * segundo argumento de `env()`.
 *
 * Se leen los archivos de verdad y no `config()`, porque lo que falla es la
 * expresión escrita en el archivo y el framework ya la tiene resuelta cuando
 * arranca la prueba.
 */
class VariablesVaciasEnDockerTest extends TestCase
{
    public function test_ninguna_variable_vacia_en_el_compose_se_lee_con_un_defecto_que_no_se_aplicara(): void
    {
        $vacias = $this->declaradasVacias();

        $this->assertNotEmpty($vacias, 'No se pudo leer el bloque x-app-env del compose.');

        $trampas = [];

        foreach (glob(base_path('config/*.php')) as $archivo) {
            preg_match_all(
                "/env\(\s*'([A-Z0-9_]+)'\s*,\s*([^)]+)\)/",
                $this->sinComentarios((string) file_get_contents($archivo)),
                $coincidencias,
                PREG_SET_ORDER
            );

            foreach ($coincidencias as [, $variable, $defecto]) {
                $defecto = trim($defecto);

                // Un defecto nulo o vacío da lo mismo que la cadena vacía que va
                // a llegar: no hay trampa, el resultado es el que se espera.
                if (in_array($defecto, ['null', "''", '""'], true)) {
                    continue;
                }

                if (in_array($variable, $vacias, true)) {
                    $trampas[] = basename($archivo).": env('{$variable}', {$defecto})";
                }
            }
        }

        $this->assertSame([], $trampas, implode("\n", array_merge(
            ['Estas variables llegan al contenedor DEFINIDAS Y VACÍAS, así que su', 'valor por defecto no se aplicará nunca. Usa `env(\'X\') ?: $defecto`:', ''],
            $trampas
        )));
    }

    /**
     * El archivo sin comentarios.
     *
     * Hace falta porque el comentario que explica este fallo cita el código
     * equivocado —"antes ponía env('X', env('Y'))"—, y sin esto el test se lee a
     * sí mismo y falla por una explicación. Lo que hay que mirar es lo que se
     * ejecuta, no lo que se cuenta.
     */
    private function sinComentarios(string $php): string
    {
        $limpio = '';

        foreach (token_get_all($php) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $limpio .= is_array($token) ? $token[1] : $token;
        }

        return $limpio;
    }

    /**
     * Las variables que el compose declara como `${X:-}`, sin valor de respaldo.
     *
     * @return list<string>
     */
    private function declaradasVacias(): array
    {
        $compose = (string) file_get_contents(base_path('docker-compose.yml'));
        $inicio = strpos($compose, 'x-app-env');
        $fin = strpos($compose, "\nservices:", $inicio ?: 0);

        if ($inicio === false || $fin === false) {
            return [];
        }

        preg_match_all(
            '/^\s{2}([A-Z0-9_]+):\s*\$\{[A-Z0-9_]+:-\}\s*$/m',
            substr($compose, $inicio, $fin - $inicio),
            $coincidencias
        );

        return $coincidencias[1];
    }
}
