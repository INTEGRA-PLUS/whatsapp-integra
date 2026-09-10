<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * El regreso del diálogo de Business Login for Instagram.
 *
 * Meta manda aquí al usuario después de que acepte o cancele, con `?code=...` o
 * con `?error=...`. La URL está registrada en el panel y **es una de las que el
 * revisor del App Review visita**, así que tiene que contestar 200 y decir algo
 * con sentido en ambos casos.
 *
 * Lo que todavía NO hace: canjear el código por un token y dejar la cuenta
 * conectada. No es un olvido — hace falta antes decidir dónde vive una línea de
 * Instagram, porque `instances` está modelada alrededor de un `phone_number_id`
 * y una cuenta de Instagram no tiene ninguno. Reaprovechar esa columna para
 * meter un IGSID sería el atajo que luego nadie se atreve a deshacer.
 *
 * Mientras tanto esta pantalla dice la verdad en vez de fingir que conectó.
 */
class InstagramConexionController extends Controller
{
    public function callback(Request $request)
    {
        $error = $request->query('error_reason') ?? $request->query('error');

        if ($error !== null) {
            Log::channel('instagram')->info('↩️ El usuario no completó la conexión de Instagram', [
                'motivo' => $error,
            ]);

            return $this->pagina(
                'No se conectó la cuenta',
                'Instagram canceló la autorización. Puedes volver a intentarlo desde Integra CRM cuando quieras.'
            );
        }

        if (! $request->filled('code')) {
            return $this->pagina(
                'Conectar Instagram',
                'Esta dirección es el regreso del diálogo de autorización de Instagram. Se llega aquí desde Integra CRM, no directamente.'
            );
        }

        // El código es de un solo uso y caduca en un minuto. No se registra: es
        // credencial, aunque sea efímera.
        Log::channel('instagram')->info('✅ Instagram devolvió un código de autorización');

        return $this->pagina(
            'Autorización recibida',
            'Instagram nos autorizó correctamente. La conexión de la cuenta a tu bandeja está en construcción y se activará en la próxima actualización.'
        );
    }

    private function pagina(string $titulo, string $texto)
    {
        $titulo = e($titulo);
        $texto = e($texto);

        return response(<<<HTML
        <!doctype html>
        <html lang="es">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$titulo} · Integra CRM</title>
            <style>
                body { margin: 0; display: grid; place-items: center; min-height: 100vh;
                       font: 16px/1.6 system-ui, sans-serif; color: #1c2430; background: #f6f7f9; }
                main { max-width: 34rem; padding: 2.5rem; }
                h1 { font-size: 1.35rem; margin: 0 0 .75rem; }
                p { margin: 0; color: #55606f; }
            </style>
        </head>
        <body><main><h1>{$titulo}</h1><p>{$texto}</p></main></body>
        </html>
        HTML)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
