<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\InstagramLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Conectar una cuenta profesional de Instagram a una empresa.
 *
 * Dos rutas y un viaje entre ellas: `conectar()` manda al cliente a la ventana
 * de autorización de Instagram, y `callback()` recoge el regreso. La segunda es
 * pública —Meta redirige ahí sin cabeceras nuestras— y **es una de las URL que
 * el revisor del App Review visita**, así que tiene que contestar 200 y decir
 * algo con sentido pase lo que pase.
 */
class InstagramConexionController extends Controller
{
    /**
     * La clave de sesión donde vive el `state` mientras el cliente está en
     * Instagram.
     */
    private const ESTADO = 'instagram.state';

    public function __construct(private InstagramLoginService $instagram) {}

    /**
     * Arranca el inicio de sesión.
     *
     * El `state` es lo que impide que alguien haga clic en un enlace preparado y
     * termine conectando **su** cuenta de Instagram a la empresa de otro: sin
     * comparar contra la sesión, el callback aceptaría cualquier código que le
     * llegue.
     */
    public function conectar(Request $request)
    {
        $usuario = $request->user();

        if (! $this->instagram->estaConfigurado()) {
            return back()->with('error', 'Falta configurar la app de Instagram. Avísanos y lo revisamos.');
        }

        $estado = Str::random(40);

        $request->session()->put(self::ESTADO, [
            'valor' => $estado,
            'empresa' => $usuario->company_id,
        ]);

        return redirect()->away($this->instagram->urlDeAutorizacion($estado));
    }

    public function callback(Request $request)
    {
        $error = $request->query('error_reason') ?? $request->query('error');

        if ($error !== null) {
            Log::channel('instagram')->info('↩️ El usuario no completó la conexión de Instagram', [
                'motivo' => $error,
            ]);

            return $this->volver($request, 'No se conectó la cuenta. Instagram canceló la autorización; puedes intentarlo otra vez.', false);
        }

        if (! $request->filled('code')) {
            // Alguien llegó aquí a mano, o el revisor está mirando la URL.
            return $this->pagina(
                'Conectar Instagram',
                'Esta dirección es el regreso del diálogo de autorización de Instagram. Se llega aquí desde Integra CRM, no directamente.'
            );
        }

        $guardado = $request->session()->pull(self::ESTADO);

        if (! is_array($guardado) || ! hash_equals((string) ($guardado['valor'] ?? ''), (string) $request->query('state'))) {
            Log::channel('instagram')->warning('❌ Conexión de Instagram rechazada: el state no coincide', [
                'ip' => $request->ip(),
            ]);

            return $this->volver($request, 'La conexión caducó o se abrió desde otro sitio. Vuelve a intentarlo desde Integra CRM.', false);
        }

        $instancia = $this->conectarCuenta((int) $guardado['empresa'], (string) $request->query('code'));

        if (! $instancia) {
            return $this->volver($request, 'No pudimos completar la conexión con Instagram. Inténtalo de nuevo en un momento.', false);
        }

        return $this->volver($request, "Instagram conectado: @{$instancia->usuarioDeInstagram()}", true);
    }

    /**
     * Canje del código y alta de la línea.
     *
     * El orden importa: primero se consigue el token de 60 días y el perfil, y
     * sólo entonces se toca la base. Guardar la línea antes dejaría cuentas a
     * medio conectar —sin token útil— que la bandeja daría por buenas.
     */
    private function conectarCuenta(int $empresa, string $codigo): ?Instance
    {
        $corto = $this->instagram->canjearCodigo($codigo);

        if (! $corto) {
            return null;
        }

        $largo = $this->instagram->tokenLargo($corto['access_token']);

        if (! $largo) {
            return null;
        }

        $perfil = $this->instagram->perfil($largo['access_token']);

        if (! $perfil) {
            return null;
        }

        // updateOrCreate y no create: reconectar una cuenta ya conectada es
        // normal —al renovar permisos, o si el token se perdió— y crear una
        // línea nueva partiría en dos el historial del mismo cliente.
        $instancia = Instance::updateOrCreate(
            [
                'channel' => Instance::CANAL_INSTAGRAM,
                'external_account_id' => $perfil['user_id'],
            ],
            [
                'company_id' => $empresa,
                'uuid' => (string) Str::uuid(),
                'name' => '@'.$perfil['username'],
                'type' => 'meta',
                'active' => true,
                // 'active', no 'connected': `status` es un enum de
                // ('active','inactive','pending') desde la primera migración, y
                // cualquier otro valor lo rechaza la base sin decir cuál era el
                // problema.
                'status' => 'active',
                'access_token' => $largo['access_token'],
                'token_expires_at' => now()->addSeconds($largo['expires_in']),
            ]
        );

        $instancia->guardarCuentaDeInstagram($perfil['username'], $perfil['profile_picture_url']);
        $instancia->save();

        Log::channel('instagram')->info('✅ Cuenta de Instagram conectada', [
            'empresa' => $empresa,
            'cuenta' => $perfil['user_id'],
            'usuario' => $perfil['username'],
            'caduca' => $instancia->token_expires_at?->toDateString(),
        ]);

        return $instancia;
    }

    /**
     * De vuelta a la aplicación, con el resultado a la vista.
     *
     * Si la sesión se perdió por el camino —el cliente tardó, o volvió en otro
     * navegador— no hay a dónde volver, así que se muestra una página en vez de
     * mandarlo al login sin explicación.
     */
    private function volver(Request $request, string $mensaje, bool $bien)
    {
        if (! $request->user()) {
            return $this->pagina($bien ? 'Instagram conectado' : 'No se conectó la cuenta', $mensaje);
        }

        return redirect()->route('instances.index')->with($bien ? 'success' : 'error', $mensaje);
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
