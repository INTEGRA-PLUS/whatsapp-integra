<?php

namespace App\Services;

use App\Models\Instance;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Conectar las páginas de Facebook de un cliente para atender su Messenger.
 *
 * Se parece al de Instagram pero no es el mismo camino, y las diferencias son
 * justo las que hacen fallar la primera conexión:
 *
 * - **Aquí sí se entra por Facebook.** En Instagram el botón «continuar con
 *   Facebook» devolvía un token inservible; aquí es el único camino.
 * - **Una cuenta puede tener varias páginas.** Instagram era una cuenta y ya;
 *   aquí hay que preguntar cuál, porque conectar la equivocada manda los
 *   mensajes de un negocio a la bandeja de otro.
 * - **No basta con el token: hay que suscribir la página a la app**
 *   (`/{page-id}/subscribed_apps`). Sin ese paso la conexión se ve perfecta en
 *   el CRM y no entra ni un mensaje, que es el fallo más caro de diagnosticar
 *   porque no da error en ninguna parte.
 * - **El token de página no caduca.** El de Instagram muere a los 60 días y
 *   hace falta renovarlo; éste sólo se invalida si el cliente cambia la
 *   contraseña o nos quita el permiso. No hay tarea programada que escribir.
 */
class MessengerLoginService
{
    /**
     * Los cinco permisos de la solicitud, y ninguno más.
     *
     * `business_management` no se usa para enviar: es **dependencia** de
     * `pages_messaging` y de `pages_show_list`, y Meta rebota la revisión si no
     * va dentro. Los otros cuatro son literalmente lo que hace falta: ver las
     * páginas del cliente, leer sus datos, suscribir el webhook y mensajear.
     */
    public const PERMISOS = [
        'pages_show_list',
        'pages_messaging',
        'pages_manage_metadata',
        'pages_read_engagement',
        'business_management',
    ];

    /**
     * Los campos del webhook que sabemos atender.
     *
     * Se suscriben por página, no por app: la app declara el tópico `page` una
     * vez y cada página se apunta con los suyos. Pedir de más trae eventos que
     * nadie procesa y ruido en el log.
     */
    public const CAMPOS_DEL_WEBHOOK = [
        'messages',
        'messaging_postbacks',
        'message_reactions',
        'message_reads',
        'message_echoes',
    ];

    /**
     * Quien conecta tiene que poder contestar en esa página.
     *
     * Meta lo mide con «tareas». Sin MESSAGING el token sale igualmente, y el
     * envío falla después con un permiso que no menciona a la persona: mejor
     * dejar fuera la página en la pantalla de elección y decir por qué.
     */
    public const TAREA_NECESARIA = 'MESSAGING';

    public function estaConfigurado(): bool
    {
        return $this->appId() !== '' && $this->appSecret() !== '';
    }

    /**
     * La ventana de autorización de Facebook.
     *
     * Con `config_id` se abre el diálogo de **Facebook Login para empresas**,
     * que es el que Meta quiere para apps que sirven a negocios y el que enseña
     * el selector de páginas. Sin él se cae al login clásico pidiendo los
     * permisos sueltos: funciona, pero el cliente ve una pantalla más pobre.
     * Se admiten los dos para no depender de un valor del panel que puede no
     * estar puesto el día del despliegue.
     */
    public function urlDeAutorizacion(string $estado): string
    {
        $parametros = [
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'state' => $estado,
        ];

        if ($configId = $this->configId()) {
            $parametros['config_id'] = $configId;
        } else {
            $parametros['scope'] = implode(',', self::PERMISOS);
        }

        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query($parametros);
    }

    /**
     * Cambia el código por un token de usuario, y ése por uno de 60 días.
     *
     * El largo no es para guardarlo: es el que hay que usar para pedir los
     * tokens de página, que son los que de verdad se quedan. Con el corto
     * también salen, pero heredan su hora de vida y el cliente se cae solo esa
     * misma tarde.
     */
    public function tokenDeUsuario(string $codigo): ?string
    {
        $corto = $this->canjearCodigo($codigo);

        if (! $corto) {
            return null;
        }

        return $this->tokenLargo($corto) ?? $corto;
    }

    private function canjearCodigo(string $codigo): ?string
    {
        $respuesta = Http::get("https://graph.facebook.com/{$this->version()}/oauth/access_token", [
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'redirect_uri' => $this->redirectUri(),
            'code' => $codigo,
        ]);

        if ($respuesta->failed() || ! $respuesta->json('access_token')) {
            $this->fallo('canjear el código de Facebook', $respuesta->json());

            return null;
        }

        return (string) $respuesta->json('access_token');
    }

    private function tokenLargo(string $corto): ?string
    {
        $respuesta = Http::get("https://graph.facebook.com/{$this->version()}/oauth/access_token", [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'fb_exchange_token' => $corto,
        ]);

        if ($respuesta->failed() || ! $respuesta->json('access_token')) {
            // No es fatal: se sigue con el corto y el cliente queda conectado
            // hoy. Se avisa porque los tokens de página que salgan de aquí
            // heredan esa caducidad y nadie lo notaría hasta que dejen de
            // entrar mensajes.
            $this->fallo('alargar el token de usuario', $respuesta->json());

            return null;
        }

        return (string) $respuesta->json('access_token');
    }

    /**
     * Las páginas que administra quien acaba de autorizar.
     *
     * `tasks` viene en la misma respuesta y es lo que dice si esa persona puede
     * contestar en la página. Se devuelven todas, marcadas: la pantalla de
     * elección las enseña y explica por qué alguna no se puede conectar, que es
     * más útil que esconderla y dejar al cliente buscándola.
     *
     * @return list<array{id: string, nombre: string, token: string, puede_mensajear: bool, foto: ?string}>
     */
    public function paginas(string $tokenDeUsuario): array
    {
        $respuesta = Http::get("https://graph.facebook.com/{$this->version()}/me/accounts", [
            'fields' => 'id,name,access_token,tasks,picture{url}',
            'limit' => 100,
            'access_token' => $tokenDeUsuario,
        ]);

        if ($respuesta->failed()) {
            $this->fallo('leer las páginas del cliente', $respuesta->json());

            return [];
        }

        return collect($respuesta->json('data') ?? [])
            ->filter(fn ($pagina) => ! empty($pagina['id']) && ! empty($pagina['access_token']))
            ->map(fn ($pagina) => [
                'id' => (string) $pagina['id'],
                'nombre' => (string) ($pagina['name'] ?? 'Página sin nombre'),
                'token' => (string) $pagina['access_token'],
                'puede_mensajear' => in_array(self::TAREA_NECESARIA, $pagina['tasks'] ?? [], true),
                'foto' => $pagina['picture']['data']['url'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * Apunta la página a nuestra app para que empiece a mandar eventos.
     *
     * Es el paso que no existe en Instagram y el que más veces se olvida: sin
     * él la línea aparece conectada, el token funciona, el envío funciona… y no
     * entra ni un mensaje. Por eso, si esto falla, la conexión entera se da por
     * fallida en vez de dejar una línea muda.
     */
    public function suscribirPagina(string $pageId, string $tokenDePagina): bool
    {
        $respuesta = Http::asForm()->post(
            "https://graph.facebook.com/{$this->version()}/{$pageId}/subscribed_apps",
            [
                'subscribed_fields' => implode(',', self::CAMPOS_DEL_WEBHOOK),
                'access_token' => $tokenDePagina,
            ]
        );

        if ($respuesta->failed() || ! $respuesta->json('success')) {
            $this->fallo('suscribir la página al webhook', $respuesta->json(), ['pagina' => $pageId]);

            return false;
        }

        return true;
    }

    /** Y lo contrario, al desconectar: la página deja de mandarnos nada. */
    public function desuscribirPagina(Instance $linea): bool
    {
        if (empty($linea->external_account_id) || empty($linea->access_token)) {
            return false;
        }

        $respuesta = Http::asForm()->delete(
            "https://graph.facebook.com/{$this->version()}/{$linea->external_account_id}/subscribed_apps",
            ['access_token' => $linea->access_token]
        );

        return ! $respuesta->failed();
    }

    private function fallo(string $que, mixed $respuesta, array $extra = []): void
    {
        Log::channel('instagram')->error("❌ Messenger: no se pudo {$que}", array_merge([
            'respuesta' => $respuesta,
        ], $extra));
    }

    private function appId(): string
    {
        return (string) config('services.meta.app_id');
    }

    private function appSecret(): string
    {
        return (string) app(MetaWhatsAppService::class)->appSecretForAppId($this->appId());
    }

    private function configId(): ?string
    {
        $id = trim((string) config('services.meta.messenger.config_id'));

        return $id === '' ? null : $id;
    }

    private function version(): string
    {
        return (string) config('services.meta.messenger.api_version', 'v23.0');
    }

    private function redirectUri(): string
    {
        return route('messenger.callback');
    }
}
