<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Business Login for Instagram: el intercambio que convierte un clic del cliente
 * en una cuenta conectada.
 *
 * Tres hosts distintos y no es capricho de Meta, hay que respetarlos:
 *
 * - `www.instagram.com/oauth/authorize` — donde va el cliente a autorizar
 * - `api.instagram.com/oauth/access_token` — cambia el código por un token corto
 * - `graph.instagram.com` — token largo, renovación y datos del perfil
 *
 * Nada de esto usa el App ID ni la clave de Facebook: Instagram tiene los suyos
 * ([[apps-meta-y-tech-provider]]), y usar los otros da un error de credenciales
 * que no dice cuál es el problema.
 */
class InstagramLoginService
{
    /**
     * Sólo los dos permisos que usamos.
     *
     * Pedir `manage_comments` o `content_publish` sin usarlos alarga la revisión
     * y da motivos para rechazarla, y además obliga al cliente a conceder cosas
     * que no le hemos explicado.
     */
    public const PERMISOS = [
        'instagram_business_basic',
        'instagram_business_manage_messages',
    ];

    /**
     * La ventana de autorización a la que se manda al cliente.
     *
     * `force_reauth=true` obliga a escribir credenciales aunque haya una sesión
     * abierta en el navegador. Es lo que se quiere aquí: el asesor que conecta
     * la cuenta de su empresa puede estar en un equipo compartido, y sin esto
     * conectaría sin querer la cuenta de quien se dejó la sesión abierta.
     */
    public function urlDeAutorizacion(string $estado): string
    {
        return 'https://www.instagram.com/oauth/authorize?'.http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', self::PERMISOS),
            'force_reauth' => 'true',
            'state' => $estado,
        ]);
    }

    /**
     * Cambia el código de autorización por un token corto (una hora).
     *
     * @return array{access_token: string, user_id: string, permissions: string}|null
     */
    public function canjearCodigo(string $codigo): ?array
    {
        $respuesta = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
            'client_id' => $this->appId(),
            'client_secret' => $this->appSecret(),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
            'code' => $codigo,
        ]);

        if ($respuesta->failed()) {
            $this->registrarFallo('canjear el código', $respuesta->json());

            return null;
        }

        // Meta devuelve los datos dentro de `data[0]`, no en la raíz. Leerlo de
        // la raíz devuelve null en silencio y parece que el canje falló.
        $datos = $respuesta->json('data.0') ?? $respuesta->json();

        if (empty($datos['access_token']) || empty($datos['user_id'])) {
            $this->registrarFallo('leer el token corto', $datos);

            return null;
        }

        return [
            'access_token' => (string) $datos['access_token'],
            'user_id' => (string) $datos['user_id'],
            // `permissions` llega como LISTA, no como la cadena separada por
            // comas que enseña la documentación. Forzarlo a string reventaba el
            // canje con «Array to string conversion» y el cliente veía un 500
            // justo después de autorizar (11-sep-2026).
            'permissions' => is_array($datos['permissions'] ?? null)
                ? implode(',', $datos['permissions'])
                : (string) ($datos['permissions'] ?? ''),
        ];
    }

    /**
     * Convierte el token corto en uno de 60 días.
     *
     * @return array{access_token: string, expires_in: int}|null
     */
    public function tokenLargo(string $tokenCorto): ?array
    {
        $respuesta = Http::get('https://graph.instagram.com/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->appSecret(),
            'access_token' => $tokenCorto,
        ]);

        if ($respuesta->failed() || ! $respuesta->json('access_token')) {
            $this->registrarFallo('obtener el token largo', $respuesta->json());

            return null;
        }

        return [
            'access_token' => (string) $respuesta->json('access_token'),
            'expires_in' => (int) ($respuesta->json('expires_in') ?? 60 * 24 * 3600),
        ];
    }

    /**
     * Renueva un token largo por otros 60 días.
     *
     * Sólo funciona con un token **vivo**: uno caducado obliga a rehacer el
     * inicio de sesión con el cliente delante, que es justo lo que la tarea
     * programada existe para evitar.
     *
     * @return array{access_token: string, expires_in: int}|null
     */
    public function renovar(string $token): ?array
    {
        $respuesta = Http::get('https://graph.instagram.com/refresh_access_token', [
            'grant_type' => 'ig_refresh_token',
            'access_token' => $token,
        ]);

        if ($respuesta->failed() || ! $respuesta->json('access_token')) {
            $this->registrarFallo('renovar el token', $respuesta->json());

            return null;
        }

        return [
            'access_token' => (string) $respuesta->json('access_token'),
            'expires_in' => (int) ($respuesta->json('expires_in') ?? 60 * 24 * 3600),
        ];
    }

    /**
     * Quién es la cuenta que se acaba de conectar.
     *
     * @return array{user_id: string, username: string, profile_picture_url: ?string}|null
     */
    public function perfil(string $token): ?array
    {
        $respuesta = Http::get('https://graph.instagram.com/v23.0/me', [
            'fields' => 'user_id,username,profile_picture_url',
            'access_token' => $token,
        ]);

        if ($respuesta->failed() || ! $respuesta->json('username')) {
            $this->registrarFallo('leer el perfil', $respuesta->json());

            return null;
        }

        return [
            'user_id' => (string) ($respuesta->json('user_id') ?? $respuesta->json('id')),
            'username' => (string) $respuesta->json('username'),
            'profile_picture_url' => $respuesta->json('profile_picture_url'),
        ];
    }

    public function estaConfigurado(): bool
    {
        return $this->appId() !== '' && $this->appSecret() !== '';
    }

    private function appId(): string
    {
        return (string) config('services.meta.instagram.app_id');
    }

    private function appSecret(): string
    {
        // La clave de la app de Instagram vive en META_APP_SECRETS junto a las
        // demás, indexada por su App ID.
        return (string) app(MetaWhatsAppService::class)->appSecretForAppId($this->appId());
    }

    private function redirectUri(): string
    {
        return route('instagram.callback');
    }

    /**
     * El cuerpo del error de Meta se registra entero a propósito: dice cuál de
     * las cuatro cosas está mal —app id, clave, redirect o permisos— y sin eso
     * cada fallo de conexión es media hora de adivinar.
     */
    private function registrarFallo(string $paso, mixed $cuerpo): void
    {
        Log::channel('instagram')->error("❌ Instagram: no se pudo {$paso}", [
            'respuesta' => $cuerpo,
        ]);
    }
}
