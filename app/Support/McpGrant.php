<?php

namespace App\Support;

use App\Models\CompanyIntegration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * El permiso de paso con el que el flujo de IA entra al pass-through del MCP.
 *
 * Responde a una sola pregunta —**de qué empresa estamos hablando**— sin que la
 * respuesta pueda venir del modelo ni de n8n. Es lo que sustituye a mandarle a
 * n8n el dominio y el token `itg_` de cada cliente: aquí viaja un identificador
 * cifrado y de minutos, y el token del ERP no sale de Laravel.
 *
 * Lleva además los permisos que la empresa concedió en ese momento. Copiarlos
 * en vez de releerlos en cada llamada es deliberado: una conversación que
 * empezó pudiendo radicar no los pierde a mitad porque un admin toque el
 * interruptor —le dejaría al cliente una promesa a medias—, y al revés tampoco
 * los gana sin que se acuñe un permiso nuevo. Dura minutos, así que el cambio
 * se aplica igual en la conversación siguiente.
 *
 * Caduca porque acaba escrito en los registros de ejecución de n8n: las
 * credenciales de n8n son estáticas y la URL del nodo es lo único que admite
 * una expresión, así que el permiso va en la URL. Un permiso eterno en un log
 * es una llave; uno de quince minutos, un recibo.
 */
final class McpGrant
{
    /**
     * @param  list<string>  $permissions  Permisos de IA de la empresa (ver
     *                                     CompanyIntegration::AI_PERMISSIONS), ya saneados.
     */
    private function __construct(
        public readonly int $companyId,
        public readonly ?int $conversationId,
        public readonly array $permissions,
    ) {}

    /** Minutos que vale un permiso recién acuñado. */
    public static function ttl(): int
    {
        return max(1, (int) config('services.mcp_integra.grant_ttl', 15));
    }

    /**
     * Acuña un permiso para una empresa.
     *
     * @param  list<string>  $permissions
     */
    public static function mint(int $companyId, ?int $conversationId, array $permissions): string
    {
        $payload = json_encode([
            'c' => $companyId,
            'k' => $conversationId,
            'p' => array_values(array_intersect(CompanyIntegration::AI_PERMISSIONS, $permissions)),
            'x' => now()->addMinutes(self::ttl())->timestamp,
        ], JSON_UNESCAPED_UNICODE);

        // base64url: el permiso viaja como un segmento de la URL, y el base64
        // que devuelve Crypt trae `/` y `+`. Sin esto, el `/` parte la ruta en
        // dos y la petición ni siquiera llega al controlador.
        return rtrim(strtr(base64_encode(Crypt::encryptString($payload)), '+/', '-_'), '=');
    }

    /**
     * Abre un permiso, o null si no vale: manipulado, de otra instalación
     * (clave distinta) o caducado.
     *
     * No se distingue el motivo a propósito. Quien llama con un permiso malo no
     * tiene que aprender de nuestra respuesta si le falló la firma o el reloj.
     */
    public static function open(string $grant): ?self
    {
        $cipher = base64_decode(strtr($grant, '-_', '+/'), true);

        if ($cipher === false) {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($cipher), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ! isset($data['c'], $data['x'])) {
            return null;
        }

        if ((int) $data['x'] < now()->timestamp) {
            return null;
        }

        return new self(
            (int) $data['c'],
            isset($data['k']) ? (int) $data['k'] : null,
            array_values(array_intersect(CompanyIntegration::AI_PERMISSIONS, (array) ($data['p'] ?? []))),
        );
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Clave del tope de llamadas: por conversación, no por empresa.
     *
     * Un bucle de herramientas lo produce una conversación concreta; frenar a
     * la empresa entera por culpa de una dejaría sin atender a los demás
     * clientes, que es un daño mayor que el que se evita.
     */
    public function throttleKey(): string
    {
        return 'mcp-integra:'.$this->companyId.':'.($this->conversationId ?? 'sin-hilo');
    }
}
