<?php

namespace App\Services;

use App\Models\WhatsAppMenuOption;
use Illuminate\Support\Facades\Cache;

/**
 * Qué puede hacer de verdad el token de Integra de una empresa.
 *
 * Existe porque "conectado" no quiere decir "funciona". El token de Integra se
 * emite con scopes, y uno pegado a mano puede tener `facturas.leer` y no
 * `contratos.leer`: el panel dice conectado en verde, el admin arma un menú con
 * "Estado de mi servicio", lo enciende, y cada cliente que lo toca acaba
 * derivado a un asesor sin que nadie entienda por qué. El fallo sólo se ve en
 * los logs, y para entonces ya lo sufrió un cliente.
 *
 * Aquí se pregunta antes: se toca cada familia de endpoints con la consulta más
 * barata que exista y se anota si el token pasa. 401 y 403 son las únicas
 * respuestas que significan "no puedes"; cualquier otra —incluido el 404 de un
 * contrato que no existe o el 422 de un parámetro que no le gusta— significa
 * que el permiso está y que la ruta responde.
 */
class IntegraCapabilities
{
    /** Diez minutos: lo bastante para no repetir cuatro llamadas por pantalla,
     *  lo bastante poco para que reemitir el token se note enseguida. */
    private const TTL_MINUTES = 10;

    /**
     * Qué permiso necesita cada acción del menú.
     *
     * Todas las de Integra necesitan además `contactos`, porque el primer paso
     * de cualquiera es reconocer a quien escribe por su número de WhatsApp.
     */
    public const REQUIRED = [
        'consultar_factura' => ['contactos', 'facturas'],
        'pagar_en_linea' => ['contactos'],
        'estado_servicio' => ['contactos', 'contratos'],
        'reportar_falla' => ['contactos', 'radicados'],
    ];

    public const LABELS = [
        'contactos' => 'Buscar al cliente',
        'facturas' => 'Leer facturas',
        'contratos' => 'Leer el contrato',
        'radicados' => 'Crear radicados',
    ];

    /**
     * El scope de Integra que hay que pedir para cada uno, tal cual se escribe
     * en `php artisan api:token`. Va en la interfaz porque es lo que el admin
     * tiene que reenviarle a quien administra su Integra.
     */
    public const SCOPES = [
        'contactos' => 'contactos.leer',
        'facturas' => 'facturas.leer',
        'contratos' => 'contratos.leer',
        'radicados' => 'radicados.leer + radicados.crear',
    ];

    /**
     * @return array{connected: bool, checked: bool, can: array<string, bool>, error: ?string}
     */
    public static function for(int $companyId, bool $fresh = false): array
    {
        $key = "integra:capabilities:{$companyId}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(self::TTL_MINUTES), function () use ($companyId) {
            $client = Integra::for($companyId);

            if (! $client) {
                return ['connected' => false, 'checked' => false, 'can' => [], 'error' => null];
            }

            return self::probe($client);
        });
    }

    /**
     * @return array{connected: bool, checked: bool, can: array<string, bool>, error: ?string}
     */
    private static function probe(IntegraClient $client): array
    {
        $probes = [
            // Un documento que no existe: la respuesta da igual, lo que se mira
            // es si el token llega a preguntar.
            'contactos' => fn () => $client->searchContacts('0', 1),
            'facturas' => fn () => $client->pendingInvoices(['nit' => '0']),
            'radicados' => fn () => $client->radicadoCatalogs(),
            'contratos' => fn () => $client->contractSummary('0', '0'),
        ];

        $can = [];
        $dead = 0;

        foreach ($probes as $name => $probe) {
            try {
                $probe();
                $can[$name] = true;
            } catch (\RuntimeException $e) {
                $can[$name] = ! in_array($e->getCode(), [401, 403], true);
                $dead += $e->getCode() === 401 ? 1 : 0;
            }
        }

        // Un 401 en todo no es "le faltan permisos": es un token revocado o
        // caducado, y el admin tiene que reemitirlo, no ampliarlo. Distinguirlo
        // le ahorra pedir scopes para un token que ya no vale.
        return [
            'connected' => true,
            'checked' => true,
            'can' => $can,
            'error' => $dead === count($probes)
                ? 'Integra rechaza el token: está revocado o caducado. Vuelve a conectar la integración.'
                : null,
        ];
    }

    /**
     * Los permisos que le faltan a una opción para poder responder.
     *
     * @return list<string>
     */
    public static function missingFor(WhatsAppMenuOption $option, array $capabilities): array
    {
        if (! ($capabilities['checked'] ?? false)) {
            return [];
        }

        return array_values(array_filter(
            self::REQUIRED[$option->action_type] ?? [],
            fn (string $need) => ($capabilities['can'][$need] ?? false) === false
        ));
    }
}
