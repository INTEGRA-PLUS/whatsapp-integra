<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\MessengerLoginService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Conectar las páginas de Facebook de una empresa para atender su Messenger.
 *
 * El viaje tiene un paso más que el de Instagram y es el que lo complica: una
 * cuenta puede administrar **varias páginas** —la del negocio, la del primo, una
 * de 2014 que nadie recuerda— y conectar la que no era manda los mensajes de un
 * negocio a la bandeja de otro. Así que al volver de Facebook no se conecta
 * nada todavía: se enseña la lista y elige una persona.
 *
 * El callback es público —Meta redirige ahí sin cabeceras nuestras— y **es una
 * de las URL que visita el revisor del App Review**, así que contesta 200 y dice
 * algo con sentido pase lo que pase.
 */
class MessengerConexionController extends Controller
{
    private const ESTADO = 'messenger_oauth';

    private const PAGINAS = 'messenger_paginas';

    public function __construct(private MessengerLoginService $messenger) {}

    public function conectar(Request $request)
    {
        if (! $this->messenger->estaConfigurado()) {
            return back()->with('error', 'Falta configurar Messenger. Avísanos y lo revisamos.');
        }

        $estado = Str::random(40);

        $request->session()->put(self::ESTADO, [
            'valor' => $estado,
            'empresa' => $request->user()->company_id,
        ]);

        return redirect()->away($this->messenger->urlDeAutorizacion($estado));
    }

    public function callback(Request $request)
    {
        $error = $request->query('error_reason') ?? $request->query('error');

        if ($error !== null) {
            Log::channel('messenger')->info('↩️ El usuario no completó la conexión de Messenger', [
                'motivo' => $error,
            ]);

            return $this->volver($request, 'No se conectó ninguna página. Facebook canceló la autorización; puedes intentarlo otra vez.', false);
        }

        if (! $request->filled('code')) {
            return $this->pagina(
                'Conectar Messenger',
                'Esta dirección es el regreso del diálogo de autorización de Facebook. Se llega aquí desde Integra CRM, no directamente.'
            );
        }

        $guardado = $request->session()->pull(self::ESTADO);

        if (! is_array($guardado) || ! hash_equals((string) ($guardado['valor'] ?? ''), (string) $request->query('state'))) {
            Log::channel('messenger')->warning('❌ Conexión de Messenger rechazada: el state no coincide', [
                'ip' => $request->ip(),
            ]);

            return $this->volver($request, 'La conexión caducó o se abrió desde otro sitio. Vuelve a intentarlo desde Integra CRM.', false);
        }

        $token = $this->messenger->tokenDeUsuario((string) $request->query('code'));

        if (! $token) {
            return $this->volver($request, 'No pudimos completar la conexión con Facebook. Inténtalo de nuevo en un momento.', false);
        }

        $paginas = $this->messenger->paginas($token);

        if ($paginas === []) {
            return $this->volver(
                $request,
                'Esa cuenta de Facebook no administra ninguna página. Messenger se conecta a una página, no a un perfil personal.',
                false
            );
        }

        // Los tokens de página se guardan en la sesión y no en un campo oculto
        // del formulario: son credenciales, y un formulario se queda en el
        // historial del navegador y en cualquier extensión que lea el DOM.
        $request->session()->put(self::PAGINAS, [
            'empresa' => (int) $guardado['empresa'],
            'lista' => $paginas,
        ]);

        return $this->elegir($request);
    }

    /**
     * La pantalla de elección.
     *
     * Con una sola página elegible tampoco se conecta sola: el cliente acaba de
     * dar permisos sobre sus páginas y merece ver cuál va a quedar atendida
     * desde el CRM antes de que pase.
     */
    public function elegir(Request $request)
    {
        $guardado = $request->session()->get(self::PAGINAS);

        if (! is_array($guardado)) {
            return $this->volver($request, 'No hay ninguna conexión a medias. Empieza otra vez desde Instancias.', false);
        }

        return inertia('Instances/ElegirPaginaMessenger', [
            'paginas' => collect($guardado['lista'])
                // El token no sale del servidor.
                ->map(fn ($pagina) => collect($pagina)->except('token')->all())
                ->values()
                ->all(),
        ]);
    }

    public function guardar(Request $request)
    {
        $validado = $request->validate([
            'pagina_id' => 'required|string',
        ]);

        $guardado = $request->session()->get(self::PAGINAS);

        if (! is_array($guardado)) {
            return back()->with('error', 'La conexión caducó. Empieza otra vez desde Instancias.');
        }

        $pagina = collect($guardado['lista'])->firstWhere('id', $validado['pagina_id']);

        if (! $pagina) {
            return back()->with('error', 'Esa página no estaba entre las que autorizaste.');
        }

        if (! $pagina['puede_mensajear']) {
            return back()->with('error',
                'En esa página no tienes permiso para responder mensajes. Pídele al administrador '
                .'que te dé el rol de mensajería y vuelve a intentarlo.');
        }

        // Primero se suscribe y sólo después se guarda la línea. Al revés
        // quedaría una línea que se ve conectada y no recibe nada, que es
        // exactamente el fallo que no da error en ninguna parte.
        if (! $this->messenger->suscribirPagina($pagina['id'], $pagina['token'])) {
            return back()->with('error',
                'Facebook no dejó suscribir la página a las notificaciones. Inténtalo de nuevo en un momento.');
        }

        $instancia = Instance::updateOrCreate(
            [
                'channel' => Instance::CANAL_MESSENGER,
                'external_account_id' => $pagina['id'],
            ],
            [
                'company_id' => (int) $guardado['empresa'],
                'uuid' => (string) Str::uuid(),
                'name' => $pagina['nombre'],
                'type' => 'meta',
                'active' => true,
                'status' => 'active',
                'access_token' => $pagina['token'],
                // El token de página no caduca: sólo se invalida si el cliente
                // cambia la contraseña o nos retira el permiso. Dejarlo en null
                // es lo honesto; poner una fecha inventada haría que la tarea
                // de renovación intentara renovar lo que no hace falta.
                'token_expires_at' => null,
            ]
        );

        $request->session()->forget(self::PAGINAS);

        Log::channel('messenger')->info('✅ Página de Messenger conectada', [
            'empresa' => $guardado['empresa'],
            'pagina' => $pagina['id'],
            'nombre' => $pagina['nombre'],
        ]);

        return redirect()->route('instances.index')
            ->with('success', "Messenger conectado: {$instancia->name}");
    }

    private function volver(Request $request, string $mensaje, bool $bien)
    {
        if (! $request->user()) {
            return $this->pagina($bien ? 'Messenger conectado' : 'No se conectó la página', $mensaje);
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
