<?php

namespace App\Support;

/**
 * El `signed_request` con el que Meta avisa de una desautorización o de una
 * petición de borrado de datos.
 *
 * No es el mismo mecanismo que el de los webhooks: allí la firma viaja en la
 * cabecera `X-Hub-Signature-256` y se calcula sobre el cuerpo crudo; aquí llega
 * un único campo de formulario con las dos mitades pegadas por un punto —firma
 * y payload, ambas en base64url— y se firma sólo la segunda.
 *
 * Se valida siempre. Estos avisos disparan borrados y desconexiones, así que
 * aceptar uno sin firmar sería dejar que cualquiera desconecte la cuenta de un
 * cliente mandando un POST.
 */
class SolicitudFirmadaDeMeta
{
    private function __construct(public readonly array $datos) {}

    /**
     * Devuelve la solicitud si la firma cuadra, y null si no.
     *
     * Null cubre a propósito todos los casos malos —vacío, mal formado, firma
     * que no casa, algoritmo distinto— porque para quien llama son lo mismo:
     * esto no viene de Meta y no se toca nada.
     */
    public static function abrir(?string $firmada, ?string $secreto): ?self
    {
        if (! is_string($firmada) || $firmada === '' || ! is_string($secreto) || $secreto === '') {
            return null;
        }

        if (substr_count($firmada, '.') !== 1) {
            return null;
        }

        [$firma, $carga] = explode('.', $firmada, 2);

        $firmaRecibida = self::decodificar($firma);
        $cargaCruda = self::decodificar($carga);

        if ($firmaRecibida === null || $cargaCruda === null) {
            return null;
        }

        $datos = json_decode($cargaCruda, true);

        if (! is_array($datos)) {
            return null;
        }

        // Meta sólo firma con HMAC-SHA256. Comprobarlo evita el ataque clásico
        // de pedir un algoritmo débil —o ninguno— y colar una firma cualquiera.
        if (($datos['algorithm'] ?? null) !== 'HMAC-SHA256') {
            return null;
        }

        // La firma se calcula sobre el payload TAL COMO VIAJA, todavía en
        // base64url. Decodificarlo antes de comparar da siempre un no.
        $esperada = hash_hmac('sha256', $carga, $secreto, true);

        if (! hash_equals($esperada, $firmaRecibida)) {
            return null;
        }

        return new self($datos);
    }

    /**
     * El IGSID de quien pide la desautorización o el borrado.
     */
    public function usuario(): ?string
    {
        $usuario = $this->datos['user_id'] ?? null;

        return is_scalar($usuario) && (string) $usuario !== '' ? (string) $usuario : null;
    }

    /**
     * base64url: `-` y `_` en vez de `+` y `/`, y sin relleno.
     */
    private static function decodificar(string $valor): ?string
    {
        $normalizado = strtr($valor, '-_', '+/');
        $relleno = strlen($normalizado) % 4;

        if ($relleno > 0) {
            $normalizado .= str_repeat('=', 4 - $relleno);
        }

        $decodificado = base64_decode($normalizado, true);

        return $decodificado === false ? null : $decodificado;
    }
}
