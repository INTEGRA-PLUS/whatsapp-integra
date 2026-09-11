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
    /** Lo que dura un token largo de Instagram, cuando Meta no dice otra cosa. */
    private const SESENTA_DIAS = 60 * 24 * 3600;

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
        // 1. El canje documentado, para un token de una hora.
        $respuesta = Http::get('https://graph.instagram.com/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->appSecret(),
            'access_token' => $tokenCorto,
        ]);

        if (! $respuesta->failed() && $respuesta->json('access_token')) {
            return [
                'access_token' => (string) $respuesta->json('access_token'),
                'expires_in' => (int) ($respuesta->json('expires_in') ?? self::SESENTA_DIAS),
            ];
        }

        $this->registrarFallo('canjear el token por uno largo', $respuesta->json(), $respuesta, $tokenCorto);

        // 2. Si el canje lo rechaza, la explicación más probable es que el token
        //    YA sea de sesenta días: Business Login for Instagram los entrega
        //    así, y pedir `ig_exchange_token` sobre uno que no es de una hora
        //    devuelve «Unsupported request - method type: get», que no ayuda
        //    nada a entenderlo (11-sep-2026, con el token real en la mano:
        //    empieza por IGAG y mide 208-214 caracteres).
        //
        //    La renovación sirve entonces de dos cosas a la vez: confirma esa
        //    hipótesis y devuelve la caducidad de verdad, en vez de que la
        //    inventemos nosotros.
        $renovado = $this->renovar($tokenCorto);

        if ($renovado) {
            Log::channel('instagram')->info('🔑 El token ya era de larga duración; renovado en el momento de conectar');

            return $renovado;
        }

        // 3. Ni canje ni renovación. La renovación exige además que el token
        //    tenga 24 horas, así que uno recién emitido la falla legítimamente.
        //    Se sigue con el que hay: quien llama valida acto seguido pidiendo
        //    el perfil, y si el token no sirviera no se guardaría nada.
        Log::channel('instagram')->warning('⚠️ Se usa el token tal cual: ni el canje ni la renovación funcionaron', [
            'caducidad' => 'supuesta a 60 días; la tarea diaria la corregirá al renovar',
        ]);

        return ['access_token' => $tokenCorto, 'expires_in' => self::SESENTA_DIAS];
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
            'expires_in' => (int) ($respuesta->json('expires_in') ?? self::SESENTA_DIAS),
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
    private function registrarFallo(string $paso, mixed $cuerpo, mixed $respuesta = null, ?string $token = null): void
    {
        $contexto = ['respuesta' => $cuerpo];

        // Cómo empieza el token y cuánto mide. Los de Instagram empiezan por
        // «IGAA»; si aquí saliera otra cosa, el problema no sería el endpoint
        // sino que estamos leyendo el campo equivocado del canje del código.
        if ($token !== null) {
            $contexto['token_empieza_por'] = substr($token, 0, 4);
            $contexto['token_longitud'] = strlen($token);
        }

        // A qué dirección se acabó llamando y con qué parámetros, sin sus
        // valores: ahí van la clave y el token. Sin esto, un «Unsupported
        // request» de Meta no dice si falla la ruta, el método o el token, y se
        // va media hora en adivinar (11-sep-2026).
        if ($respuesta !== null && method_exists($respuesta, 'effectiveUri')) {
            $uri = $respuesta->effectiveUri();

            $contexto['llamada'] = $uri ? $uri->getScheme().'://'.$uri->getHost().$uri->getPath() : null;
            $contexto['parametros'] = $uri
                ? array_map(fn ($par) => explode('=', $par, 2)[0], array_filter(explode('&', $uri->getQuery())))
                : null;
            $contexto['estado_http'] = $respuesta->status();
        }

        Log::channel('instagram')->error("❌ Instagram: no se pudo {$paso}", $contexto);
    }
}
