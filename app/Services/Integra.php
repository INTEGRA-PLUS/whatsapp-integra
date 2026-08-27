<?php

namespace App\Services;

use App\Models\CompanyIntegration;
use Illuminate\Http\JsonResponse;

/**
 * La conexión de una empresa con su Integra: un solo sitio que sabe si existe
 * y que entrega el cliente listo para usar.
 *
 * Antes, cada función que necesitaba Integra repetía los mismos cuatro pasos:
 * buscar la fila de credenciales, comprobar que estuviera conectada, construir
 * el cliente y traducir el error a un mensaje. Eran siete copias del mismo
 * bloque, y cada nueva función que consultara Integra añadía la octava. Peor:
 * cada copia decidía por su cuenta de qué integración sacar el token, así que
 * una empresa podía tener Integra conectado y una parte del sistema seguir
 * diciendo que no.
 *
 * Aquí la pregunta se hace una vez: hay conexión o no la hay.
 */
class Integra
{
    /**
     * Dónde buscar las credenciales, por orden de preferencia.
     *
     * Integra es un solo entorno por empresa, pero el panel de Integraciones
     * dejó que se conectara desde dos tarjetas distintas —pagos y contactos— y
     * cada una guardó su fila. Mientras eso siga así, vale cualquiera de las
     * dos: al usuario le da igual desde cuál pulsó "Conectar", y exigirle
     * conectar dos veces el mismo servidor sólo produce empresas a medias.
     */
    private const SOURCES = [
        CompanyIntegration::KEY_INVOICE_PAYMENTS,
        CompanyIntegration::KEY_CONTACTS_SYNC,
    ];

    public const NOT_CONNECTED = 'Tu software Integra no está conectado. Conéctalo desde Integraciones.';

    /** Las credenciales que mandan para esta empresa, o null si no hay ninguna. */
    public static function connection(int $companyId): ?CompanyIntegration
    {
        return CompanyIntegration::where('company_id', $companyId)
            ->whereIn('key', self::SOURCES)
            ->get()
            ->filter->isConnected()
            ->sortBy(fn (CompanyIntegration $i) => array_search($i->key, self::SOURCES, true))
            ->first();
    }

    /** El cliente listo para consultar, o null si la empresa no tiene conexión. */
    public static function for(int $companyId): ?IntegraClient
    {
        return self::connection($companyId)?->client();
    }

    public static function connected(int $companyId): bool
    {
        return self::connection($companyId) !== null;
    }

    /**
     * Consulta Integra y devuelve la respuesta que espera el frontend.
     *
     * @param callable(IntegraClient): mixed $query Devuelve los datos, o una
     *        JsonResponse propia si necesita el control (una degradación, por
     *        ejemplo). Lanzar RuntimeException se traduce solo.
     * @param ?string $missing Mensaje cuando no hay conexión, si el genérico no sirve.
     */
    public static function respond(int $companyId, callable $query, ?string $missing = null): JsonResponse
    {
        $client = self::for($companyId);

        if (! $client) {
            return response()->json(['message' => $missing ?? self::NOT_CONNECTED], 422);
        }

        return self::run($client, $query);
    }

    /**
     * Igual que respond(), para quien ya resolvió su propio cliente porque su
     * función tiene además un interruptor propio (el flujo de pagos del chat).
     *
     * El mensaje de la excepción viene ya redactado para la UI desde
     * IntegraClient —habla de tokens y scopes, que es justo lo que el admin
     * necesita leer—, así que se pasa tal cual.
     */
    public static function run(IntegraClient $client, callable $query): JsonResponse
    {
        try {
            $result = $query($client);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return $result instanceof JsonResponse ? $result : response()->json($result);
    }
}
