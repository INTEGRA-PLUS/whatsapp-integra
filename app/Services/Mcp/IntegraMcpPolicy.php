<?php

namespace App\Services\Mcp;

use App\Models\CompanyIntegration;
use App\Support\McpGrant;
use Illuminate\Support\Facades\Log;

/**
 * Qué herramientas del MCP puede usar la IA de cada empresa.
 *
 * Conviene decir de entrada qué NO es esto: **el control de verdad es el perfil
 * del token**, y lo aplica el servidor de Integra. Un token `lectura` no
 * escribe aunque este archivo entero esté mal. Lo de aquí es defensa en
 * profundidad y, sobre todo, el sitio donde se respetan los permisos que el
 * admin concedió en el panel: de las 39 herramientas, 36 consultan y 3
 * escriben, y "consultar mi factura" y "abrir un radicado a mi nombre" no son
 * la misma decisión.
 *
 * Es el mismo criterio con el que nace toda empresa en
 * DefaultAiMenusIntegration: sólo lectura, y las escrituras se conceden a mano.
 *
 * Cómo se reconoce una escritura, en orden:
 *
 *   1. `annotations.readOnlyHint === false` en el `tools/list` del servidor
 *      real. Es lo primero porque no depende del nombre: si mañana renombran
 *      la herramienta, la clasificación sigue valiendo.
 *   2. Una lista de nombres configurable, por si ese servidor no anota.
 *
 * Y si una herramienta que escribe no encaja en ninguna de las dos listas de
 * permisos, se le exigen TODOS los de escritura. No sabemos cuál es, así que se
 * pide el máximo: equivocarse hacia "pide más permiso" cuesta una respuesta;
 * equivocarse hacia el otro lado cuesta una fila en el ERP de un cliente.
 */
class IntegraMcpPolicy
{
    /**
     * Las tres escrituras del servidor y qué permiso pide cada una.
     *
     * Los nombres son configurables porque viven en OTRO repositorio y pueden
     * cambiar sin que nadie toque éste; el `readOnlyHint` es el que aguanta un
     * rename, y esto es sólo la red por debajo.
     *
     * @return array<string, list<string>> permiso => nombres de herramienta
     */
    public static function writeTools(): array
    {
        $configured = config('services.mcp_integra.write_tools');

        return is_array($configured) && $configured !== [] ? $configured : [
            CompanyIntegration::AI_TICKETS => ['radicados_crear'],
            CompanyIntegration::AI_PAYMENTS => ['pagos_registrar', 'contratos_prorroga'],
        ];
    }

    /**
     * ¿Puede esta conversación llamar a esta herramienta?
     *
     * @param  list<array<string, mixed>>  $catalog  Lo que devolvió `tools/list`.
     * @return ?string null si puede; si no, el motivo redactado para que lo lea
     *                 el modelo y se lo explique al cliente.
     */
    public static function deny(McpGrant $grant, string $tool, array $catalog): ?string
    {
        if (! self::writes($tool, $catalog)) {
            // Consultar no compromete nada: lo cubre el permiso de lectura, que
            // es con el que nace toda empresa.
            return $grant->allows(CompanyIntegration::AI_READ)
                ? null
                : 'Esta empresa no ha autorizado consultar su sistema. Ofrécele un asesor al cliente.';
        }

        $needed = self::permissionFor($tool);

        if ($needed === null) {
            // Escritura sin clasificar: se piden todos los permisos de
            // escritura y se deja rastro para poder añadirla a la lista.
            Log::channel('whatsapp')->warning('⚠️ MCP Integra: herramienta de escritura sin clasificar', [
                'company_id' => $grant->companyId,
                'herramienta' => mb_substr($tool, 0, 60),
            ]);

            $needed = array_keys(self::writeTools());
        }

        foreach ((array) $needed as $permission) {
            if (! $grant->allows($permission)) {
                return 'Esta empresa no ha autorizado esa acción. Explícale al cliente que no puedes hacerla '
                    .'y ofrécele pasar el chat a un asesor.';
            }
        }

        return null;
    }

    /**
     * ¿Esta herramienta deja rastro en el sistema del cliente?
     *
     * @param  list<array<string, mixed>>  $catalog
     */
    public static function writes(string $tool, array $catalog): bool
    {
        foreach ($catalog as $entry) {
            if (($entry['name'] ?? null) !== $tool) {
                continue;
            }

            $hint = data_get($entry, 'annotations.readOnlyHint');

            // Sólo un `false` explícito decide. Si el servidor no anota, la
            // ausencia no es un "sí, es de lectura": se pasa a los nombres.
            if ($hint === false) {
                return true;
            }
            if ($hint === true) {
                return false;
            }
            break;
        }

        return self::permissionFor($tool) !== null;
    }

    /** El permiso que pide una escritura conocida, o null si no la conocemos. */
    private static function permissionFor(string $tool): ?string
    {
        foreach (self::writeTools() as $permission => $names) {
            if (in_array($tool, (array) $names, true)) {
                return $permission;
            }
        }

        return null;
    }

    /**
     * ¿El perfil guardado permite escribir?
     *
     * Hoy los 43 tokens emitidos son de perfil `lectura`, así que **toda**
     * escritura va a fallar en el servidor. Saberlo aquí permite decírselo al
     * modelo con una frase que puede repetirle al cliente, en vez de dejar que
     * se lleve un error del ERP y prometa un radicado que nadie abrió.
     */
    public static function profileWrites(?string $profile): bool
    {
        return $profile === 'escritura';
    }
}
