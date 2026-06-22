<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP para el software Integra.
 *
 * Conexión OAuth en 2 pasos (Integra es el proveedor; esta app es el cliente):
 *
 *   1) Navegador → GET {base_url}/oauth/authorize?redirect_uri={callback}&state={aleatorio}
 *        Integra autentica al usuario con su propia vista de login y redirige de vuelta a
 *        {callback}?code={code}&state={state}. El code es de un solo uso y vive 5 min.
 *
 *   2) Servidor → POST {base_url}/api/auth/token   { "code": "...", "redirect_uri": "..." }
 *        200:  { "token": "...", "expires_at": "2026-06-19T23:00:00Z",
 *                "user": { "id": 1, "name": "...", "email": "...", "company": "..." } }
 *        400/422: code inválido/expirado o redirect_uri que no coincide.
 *
 *   --- ya implementados, se usan con Authorization: Bearer {token} ---
 *
 *   GET  {base_url}/api/me              200 { "user": { ... } } | 401 token revocado
 *   POST {base_url}/api/auth/logout     204 revoca el token (usado por "Desconectar")
 *
 *   --- pendientes de implementar en Integra ---
 *
 *   GET  {base_url}/api/clientes?search=texto   (Authorization)
 *     200:  { "data": [ { "id": 5, "nombre": "...", "documento": "...", "saldo": 120000 } ] }
 *
 *   POST {base_url}/api/payments      (Authorization)
 *     body: { "cliente_id": 5, "cliente_nombre": "...", "valor": 50000, "pago_total": false }
 *     201:  { "id": 99, "recibo": "REC-0099", "estado": "aplicado", "saldo_restante": 70000 }
 */
class IntegraClient
{
    protected string $baseUrl;
    protected ?string $token;

    public function __construct(string $baseUrl, ?string $token = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    protected function request(): PendingRequest
    {
        $req = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->timeout(20);

        if ($this->token) {
            $req = $req->withToken($this->token);
        }

        return $req;
    }

    /**
     * Construye la URL de autorización a la que se redirige el navegador (paso 1).
     */
    public function authorizeUrl(string $redirectUri, string $state): string
    {
        return $this->baseUrl.'/oauth/authorize?'.http_build_query([
            'redirect_uri' => $redirectUri,
            'state'        => $state,
        ]);
    }

    /**
     * Canjea el código de autorización por un token de acceso (paso 2, server-to-server).
     *
     * @return array{token:string, expires_at:?string, user:array}
     * @throws \RuntimeException con un mensaje apto para mostrar al usuario.
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        try {
            $res = $this->request()->post('/api/auth/token', [
                'code'         => $code,
                'redirect_uri' => $redirectUri,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Integra token exchange error', ['msg' => $e->getMessage()]);
            throw new \RuntimeException('No se pudo contactar al servidor de Integra. Revisa la URL.');
        }

        // Code inválido/expirado o redirect_uri que no coincide.
        if (in_array($res->status(), [400, 401, 422], true)) {
            throw new \RuntimeException($res->json('message') ?? 'El código de autorización no es válido o expiró. Vuelve a conectar.');
        }

        if (! $res->successful()) {
            throw new \RuntimeException('Integra respondió con un error ('.$res->status().').');
        }

        $token = $res->json('token');
        if (! $token) {
            throw new \RuntimeException('Integra no devolvió un token de acceso.');
        }

        return [
            'token'      => $token,
            'expires_at' => $res->json('expires_at'),
            'user'       => $res->json('user') ?? [],
        ];
    }

    /**
     * Verifica que el token siga siendo válido.
     *
     * @return array datos del usuario.
     * @throws \RuntimeException
     */
    public function me(): array
    {
        try {
            $res = $this->request()->get('/api/me');
        } catch (\Throwable $e) {
            throw new \RuntimeException('No se pudo contactar al servidor de Integra.');
        }

        if ($res->status() === 401) {
            throw new \RuntimeException('La sesión con Integra expiró. Vuelve a conectar.');
        }
        if (! $res->successful()) {
            throw new \RuntimeException('Integra respondió con un error ('.$res->status().').');
        }

        return $res->json('user') ?? $res->json() ?? [];
    }

    /**
     * Revoca el token en Integra (best-effort: no lanza si falla, porque
     * de todos modos vamos a borrarlo de nuestro lado).
     */
    public function logout(): void
    {
        try {
            $this->request()->post('/api/auth/logout');
        } catch (\Throwable $e) {
            Log::info('Integra logout no-op', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Busca clientes para el autocompletado del modal.
     *
     * @return array<int, array>
     */
    public function searchClients(string $search): array
    {
        try {
            $res = $this->request()->get('/api/clientes', ['search' => $search]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('No se pudo contactar al servidor de Integra.');
        }

        if (! $res->successful()) {
            throw new \RuntimeException('No se pudieron cargar los clientes.');
        }

        return $res->json('data') ?? [];
    }

    /**
     * Registra un pago contra una factura/cuenta del cliente.
     *
     * @return array respuesta de Integra (recibo, estado, etc.)
     * @throws \RuntimeException
     */
    public function createPayment(array $payload): array
    {
        try {
            $res = $this->request()->post('/api/payments', $payload);
        } catch (\Throwable $e) {
            throw new \RuntimeException('No se pudo contactar al servidor de Integra.');
        }

        if ($res->status() === 401) {
            throw new \RuntimeException('La sesión con Integra expiró. Vuelve a conectar.');
        }
        if (! $res->successful()) {
            $msg = $res->json('message') ?? 'No se pudo registrar el pago en Integra.';
            throw new \RuntimeException($msg);
        }

        return $res->json() ?? [];
    }
}
