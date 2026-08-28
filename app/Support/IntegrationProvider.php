<?php

namespace App\Support;

/**
 * El catálogo de proveedores que se pueden conectar.
 *
 * Integra es el primero, no el único: la plataforma es de nosotros y ellos son
 * un cliente más, así que mañana entra otro ISP, un ERP o una pasarela de pagos
 * sin tocar la pantalla de integraciones. Añadir un proveedor es añadir una
 * entrada aquí y escribir su conector; todo lo demás —la tarjeta, el logo, qué
 * funciones habilita, qué permisos pide— sale de este archivo.
 *
 * La distinción que faltaba: una CONEXIÓN es un entorno del proveedor (una URL
 * y un token), y las CAPACIDADES son lo que esa conexión habilita (cobrar
 * facturas, sincronizar contactos, responder el menú). Antes cada capacidad
 * guardaba su propia conexión, con estas consecuencias:
 *
 *  - había que escribir la misma URL dos veces, una por tarjeta;
 *  - se emitían dos tokens contra el mismo servidor y, como Integra desactiva
 *    los anteriores al emitir uno nuevo con el mismo nombre, conectar la
 *    segunda tarjeta revocaba la primera;
 *  - no había dónde colgar un proveedor que no fuera Integra.
 */
final class IntegrationProvider
{
    public const INTEGRA = 'integra';

    /**
     * Qué proveedores existen.
     *
     * `legacy_keys` son las filas que este proveedor ocupa hoy en
     * `company_integrations`, heredadas de cuando cada capacidad guardaba su
     * propia conexión. Se conservan para no romper las integraciones vivas: lo
     * que cambia es que ahora las escribe todas la misma conexión.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::INTEGRA => [
                'id' => self::INTEGRA,
                'name' => 'Integra',
                'tagline' => 'Todo tu ISP, en un solo lugar',
                'logo' => '/images/providers/integra.png',
                'description' => 'El software de gestión para ISP: facturación, contratos, soporte y clientes.',
                // Una sola URL y un solo token para todo lo de abajo.
                'connection' => [
                    'url_label' => 'URL de tu entorno Integra',
                    'url_hint' => 'El dominio donde entras a Integra. Se prueba con y sin /software.',
                    'url_placeholder' => 'https://miempresa.integra.com',
                ],
                'capabilities' => [
                    'payments' => [
                        'label' => 'Pagos a facturas',
                        'does' => 'Consultar la deuda de un cliente y registrar su pago desde el chat.',
                        'legacy_key' => 'invoice_payments',
                    ],
                    'contacts' => [
                        'label' => 'Contactos',
                        'does' => 'Traer el maestro de clientes para tener la agenda al día.',
                        'legacy_key' => 'contacts_sync',
                    ],
                    'menus' => [
                        'label' => 'Menús de WhatsApp',
                        'does' => 'Que el bot responda facturas, estado del servicio, consumo y reportes.',
                        'legacy_key' => null,
                    ],
                ],
                'legacy_keys' => ['invoice_payments', 'contacts_sync'],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /**
     * A qué proveedor pertenece una fila de `company_integrations`.
     *
     * Existe para el código que todavía razona en claves sueltas: mientras
     * queden filas heredadas, esto es lo que las devuelve a su proveedor.
     */
    public static function ofKey(string $key): ?string
    {
        foreach (self::all() as $id => $provider) {
            if (in_array($key, $provider['legacy_keys'], true)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Las filas que comparten conexión con la que se acaba de conectar.
     *
     * Es lo que convierte "dos tarjetas, dos tokens" en "un proveedor, una
     * conexión": al conectar una, las demás del mismo proveedor reciben la
     * misma URL y el mismo token en vez de emitir el suyo y revocar este.
     *
     * @return list<string>
     */
    public static function siblingKeys(string $key): array
    {
        $provider = self::ofKey($key);

        return $provider === null ? [$key] : self::all()[$provider]['legacy_keys'];
    }

    /**
     * El catálogo tal como lo consume el frontend: sin conectores ni claves
     * heredadas, que son detalle interno.
     *
     * @return list<array<string, mixed>>
     */
    public static function forDisplay(): array
    {
        return array_values(array_map(fn (array $p) => [
            'id' => $p['id'],
            'name' => $p['name'],
            'tagline' => $p['tagline'],
            'logo' => $p['logo'],
            'description' => $p['description'],
            'connection' => $p['connection'],
            'capabilities' => array_values(array_map(
                fn (array $c, string $id) => ['id' => $id, 'label' => $c['label'], 'does' => $c['does']],
                $p['capabilities'],
                array_keys($p['capabilities'])
            )),
        ], self::all()));
    }
}
