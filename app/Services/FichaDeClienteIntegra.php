<?php

namespace App\Services;

/**
 * La ficha del cliente en Integra, tal y como se ve en el panel del chat.
 *
 * El agente que abre una conversación veía hasta ahora el número y poco más:
 * para saber si quien escribe debe plata, si el servicio está suspendido o si
 * ya reportó la falla dos veces tenía que salirse a Integra y buscarlo a mano.
 * Aquí se junta en una sola llamada todo lo que el ERP sabe de esa persona.
 *
 * Integra no tiene un endpoint "dame todo del cliente": lo reparte en tres.
 *   - `/contactos/buscar` → datos personales, contratos y facturas pendientes.
 *   - `/facturas`         → el historial (lo que ya pagó, no sólo lo que debe).
 *   - `/contratos/{nro}/resumen` → pagos recientes, radicados, promesa de pago,
 *     saldo a favor y el ciclo de facturación. Es POR CONTRATO, así que hay que
 *     recorrerlos y unir; los pagos y el saldo son del titular y se repiten en
 *     todos, por eso se deduplican por número de recibo.
 *
 * **Cada bloque degrada por separado.** Un token sin `contratos.leer` deja sin
 * pagos ni radicados, pero no debe llevarse por delante el saldo y las facturas,
 * que es lo que más se consulta. Lo que no se pudo leer se nombra en
 * `sin_permiso` para que el panel lo diga en vez de fingir que no hay nada.
 */
class FichaDeClienteIntegra
{
    /**
     * Cuántos contratos se resumen.
     *
     * Cada uno es una llamada HTTP más, y la ficha se abre en mitad de una
     * conversación: un cliente con quince contratos no puede dejar el panel
     * quince peticiones colgado. Los primeros son los más recientes, que es lo
     * que se está atendiendo.
     */
    private const MAX_CONTRATOS_RESUMIDOS = 5;

    /** Cuántas facturas del historial se traen (Integra las manda de la más reciente). */
    private const MAX_FACTURAS = 20;

    /**
     * Arma la ficha.
     *
     * @param  ?string  $identificacion  Cédula/NIT si el CRM ya la conoce (contacto
     *                                   sincronizado desde Integra). Es el criterio bueno: el teléfono puede
     *                                   estar en la ficha de otro familiar.
     * @param  ?string  $telefono  Número de WhatsApp como respaldo. Se busca por los
     *                             últimos 10 dígitos, que es como Integra guarda los celulares.
     */
    public static function armar(IntegraClient $client, ?string $identificacion, ?string $telefono): array
    {
        $identificacion = trim((string) $identificacion) ?: null;
        $telefono = self::ultimos10($telefono);

        $criterio = $identificacion ?? $telefono;

        if (! $criterio) {
            return self::vacia(null, null);
        }

        $contactos = $client->searchContacts($criterio, 8)['data'];

        // Buscar por identificación y no encontrarla puede ser que el dato del
        // CRM esté viejo; se reintenta por teléfono antes de darlo por perdido.
        $porTelefono = false;
        if (empty($contactos) && $telefono && $telefono !== $criterio) {
            $contactos = $client->searchContacts($telefono, 8)['data'];
            $porTelefono = true;
        }

        if (empty($contactos)) {
            return self::vacia($identificacion ? 'identificacion' : 'telefono', $criterio);
        }

        $cliente = self::elegir($contactos, $identificacion, $telefono);

        return self::conCliente(
            $client,
            $cliente,
            count($contactos),
            $porTelefono || ! $identificacion ? 'telefono' : 'identificacion',
            $porTelefono ? $telefono : $criterio
        );
    }

    /**
     * Completa la ficha del cliente ya identificado.
     *
     * @param  array  $cliente  Contacto tal y como lo devuelve /contactos/buscar.
     */
    private static function conCliente(
        IntegraClient $client,
        array $cliente,
        int $coincidencias,
        string $tipoCriterio,
        string $criterio
    ): array {
        $datos = is_array($cliente['contacto'] ?? null) ? $cliente['contacto'] : [];
        $resumen = is_array($cliente['resumen'] ?? null) ? $cliente['resumen'] : [];
        $contratos = is_array($cliente['contratos'] ?? null) ? $cliente['contratos'] : [];
        $pendientes = is_array($cliente['facturas_pendientes'] ?? null) ? $cliente['facturas_pendientes'] : [];

        $nit = $cliente['identificacion'] ?? $datos['identificacion'] ?? null;
        $clienteId = isset($cliente['id']) ? (int) $cliente['id'] : null;

        $sinPermiso = [];

        $historial = self::historial($client, $clienteId, $nit, $sinPermiso);
        $delContrato = self::resumenDeContratos($client, $contratos, $nit, $sinPermiso);

        return [
            'buscable' => true,
            'encontrado' => true,
            'coincidencias' => $coincidencias,
            'criterio' => ['tipo' => $tipoCriterio, 'valor' => $criterio],
            'cliente' => [
                'id' => $clienteId,
                'identificacion' => $nit,
                'nombre' => $cliente['nombre_completo'] ?? $datos['nombre'] ?? '',
                'celular' => $datos['celular'] ?? null,
                'telefono' => $datos['telefono1'] ?? null,
                'email' => $datos['email'] ?? null,
                'direccion' => $datos['direccion'] ?? null,
                'barrio' => $datos['barrio'] ?? null,
                'municipio' => $datos['municipio'] ?? null,
                'departamento' => $datos['departamento'] ?? null,
            ],
            'resumen' => [
                'contratos' => $resumen['total_contratos'] ?? count($contratos),
                'contratos_activos' => $resumen['contratos_activos'] ?? null,
                'facturas_pendientes' => $resumen['facturas_pendientes'] ?? count($pendientes),
                'total_por_pagar' => (float) ($cliente['total_por_pagar'] ?? $resumen['total_por_pagar'] ?? 0),
                'saldo_a_favor' => $delContrato['saldo_a_favor'],
                'radicados_abiertos' => $delContrato['radicados_abiertos'],
            ],
            'contratos' => self::contratos($contratos, $delContrato['por_contrato']),
            'facturas' => [
                'pendientes' => self::pendientes($pendientes, $historial),
                'historial' => $historial,
            ],
            'pagos' => $delContrato['pagos'],
            'radicados' => $delContrato['radicados'],
            'sin_permiso' => array_values(array_unique($sinPermiso)),
        ];
    }

    /**
     * Cuál de las coincidencias es el cliente.
     *
     * Un mismo teléfono aparece en varias fichas (el hijo que puso su celular en
     * el contrato del padre), así que se prefiere siempre la coincidencia exacta
     * por documento; si no la hay, la que tenga ese número como celular. El
     * `coincidencias` que viaja a la UI es para que el agente sepa que hubo más
     * de una y pueda desconfiar de la elegida.
     */
    private static function elegir(array $contactos, ?string $identificacion, ?string $telefono): array
    {
        if ($identificacion) {
            foreach ($contactos as $c) {
                if (trim((string) ($c['identificacion'] ?? '')) === $identificacion) {
                    return $c;
                }
            }
        }

        if ($telefono) {
            foreach ($contactos as $c) {
                $datos = is_array($c['contacto'] ?? null) ? $c['contacto'] : [];
                foreach (['celular', 'telefono1', 'telefono2'] as $campo) {
                    if (self::ultimos10($datos[$campo] ?? null) === $telefono) {
                        return $c;
                    }
                }
            }
        }

        return $contactos[0];
    }

    /**
     * Historial de facturas. Se queda con lo que el agente lee de un vistazo:
     * código, fecha, estado y saldo.
     */
    private static function historial(IntegraClient $client, ?int $clienteId, ?string $nit, array &$sinPermiso): array
    {
        $params = $clienteId ? ['cliente_id' => $clienteId] : ($nit ? ['nit' => $nit] : null);

        if (! $params) {
            return [];
        }

        try {
            $facturas = $client->invoiceHistory($params + ['por_pagina' => self::MAX_FACTURAS])['data'];
        } catch (\RuntimeException $e) {
            $sinPermiso[] = 'facturas';

            return [];
        }

        return array_map(fn (array $f) => [
            'id' => $f['id'] ?? null,
            'codigo' => $f['codigo'] ?? null,
            'fecha' => $f['fecha'] ?? null,
            'vencimiento' => $f['vencimiento'] ?? null,
            'estado' => $f['estado'] ?? null,
            'vencida' => (bool) ($f['vencida'] ?? false),
            'electronica' => ($f['tipo_label'] ?? null) === 'electronica',
            'total' => (float) ($f['montos']['total'] ?? 0),
            'pagado' => (float) ($f['montos']['pagado'] ?? 0),
            'por_pagar' => (float) ($f['montos']['por_pagar'] ?? 0),
        ], $facturas);
    }

    /**
     * Recorre los contratos pidiendo su resumen y junta lo que sólo vive ahí:
     * pagos, radicados, promesa de pago, saldo a favor y el porqué del estado
     * del servicio.
     *
     * Un contrato que responde 404 (número que ya no existe) se salta sin ruido;
     * lo que interrumpe el recorrido es que Integra diga que no hay permiso,
     * porque entonces ningún otro contrato va a responder tampoco.
     *
     * @return array{pagos: array, radicados: array, radicados_abiertos: ?int, saldo_a_favor: ?float, por_contrato: array}
     */
    private static function resumenDeContratos(IntegraClient $client, array $contratos, ?string $nit, array &$sinPermiso): array
    {
        $vacio = [
            'pagos' => [],
            'radicados' => [],
            'radicados_abiertos' => null,
            'saldo_a_favor' => null,
            'por_contrato' => [],
        ];

        if (! $nit || empty($contratos)) {
            return $vacio;
        }

        $pagos = [];
        $radicados = [];
        $abiertos = 0;
        $saldo = null;
        $porContrato = [];

        foreach (array_slice($contratos, 0, self::MAX_CONTRATOS_RESUMIDOS) as $contrato) {
            $nro = (string) ($contrato['nro'] ?? '');
            if ($nro === '') {
                continue;
            }

            try {
                $resumen = $client->contractSummary($nro, $nit);
            } catch (\RuntimeException $e) {
                $sinPermiso[] = 'contratos';

                return $vacio;
            }

            if (! $resumen) {
                continue;
            }

            $facturacion = $resumen['facturacion'] ?? [];
            $servicio = $resumen['servicio']['internet'] ?? [];
            $soportes = $resumen['soportes'] ?? [];

            $saldo = $saldo === null
                ? (isset($facturacion['saldo_a_favor']) ? (float) $facturacion['saldo_a_favor'] : null)
                : $saldo;
            $abiertos += (int) ($soportes['abiertos'] ?? 0);

            // Los pagos son del titular, así que el mismo recibo llega repetido
            // en cada contrato suyo: se indexan por número para no listarlo dos
            // veces y hacerle creer al agente que pagó el doble.
            foreach ($resumen['pagos_recientes'] ?? [] as $pago) {
                $pagos[(string) ($pago['recibo'] ?? count($pagos))] = [
                    'recibo' => $pago['recibo'] ?? null,
                    'fecha' => $pago['fecha'] ?? null,
                    'valor' => (float) ($pago['valor'] ?? 0),
                    'medio' => $pago['medio'] ?? null,
                ];
            }

            foreach ($soportes['items'] ?? [] as $radicado) {
                $radicados[] = [
                    'codigo' => $radicado['codigo'] ?? null,
                    'fecha' => $radicado['fecha'] ?? null,
                    'servicio' => $radicado['servicio'] ?? null,
                    'estado' => $radicado['estado'] ?? null,
                    'contrato_nro' => $nro,
                ];
            }

            $porContrato[$nro] = [
                'motivo' => $servicio['motivo'] ?? null,
                'detalle' => $servicio['detalle'] ?? null,
                'monto_para_reactivar' => $servicio['monto_para_reactivar'] ?? null,
                'promesa_pago' => $facturacion['promesa_pago'] ?? null,
                'ciclo' => $facturacion['ciclo'] ?? null,
                'consumo_mes_gb' => $resumen['consumo']['mes_actual']['total_gb'] ?? null,
                // Lo que sólo se mira cuando alguien abre el contrato: viaja en
                // la misma respuesta porque el resumen YA está descargado, y
                // pedirlo otra vez al pulsar sería una llamada al ERP por gesto.
                'ampliado' => [
                    'condiciones' => $resumen['condiciones'] ?? null,
                    'wifi' => $resumen['wifi'] ?? null,
                    'contrato_digital' => $resumen['contrato_digital'] ?? null,
                    'consumo' => $resumen['consumo'] ?? null,
                    'facturas' => array_map(fn (array $f) => [
                        'id' => $f['id'] ?? null,
                        'codigo' => $f['codigo'] ?? null,
                        'vencimiento' => $f['vencimiento'] ?? null,
                        'vencida' => (bool) ($f['vencida'] ?? false),
                        'por_pagar' => (float) ($f['montos']['por_pagar'] ?? 0),
                    ], $facturacion['pendientes']['items'] ?? []),
                ],
            ];
        }

        $pagos = array_values($pagos);
        usort($pagos, fn ($a, $b) => strcmp((string) $b['fecha'], (string) $a['fecha']));
        usort($radicados, fn ($a, $b) => strcmp((string) $b['fecha'], (string) $a['fecha']));

        return [
            'pagos' => array_slice($pagos, 0, 5),
            'radicados' => array_slice($radicados, 0, 10),
            'radicados_abiertos' => $abiertos,
            'saldo_a_favor' => $saldo,
            'por_contrato' => $porContrato,
        ];
    }

    /** Los contratos, con lo que añade su resumen si se pudo leer. */
    private static function contratos(array $contratos, array $porContrato): array
    {
        return array_map(function (array $c) use ($porContrato) {
            $nro = (string) ($c['nro'] ?? '');
            $extra = $porContrato[$nro] ?? [];

            return [
                'nro' => $c['nro'] ?? null,
                'activo' => (bool) ($c['activo'] ?? false),
                'vigente' => (bool) ($c['vigente'] ?? true),
                'estado' => $c['estado'] ?? null,
                'plan' => $c['plan_internet']['nombre'] ?? null,
                'precio' => $c['plan_internet']['precio'] ?? null,
                'bajada' => $c['plan_internet']['bajada'] ?? null,
                'subida' => $c['plan_internet']['subida'] ?? null,
                'television' => (bool) ($c['television']['tiene_servicio'] ?? false),
                'direccion' => $c['ubicacion']['direccion_instalacion'] ?? null,
                'tecnologia' => $c['tecnologia'] ?? null,
                'grupo_corte' => $c['grupo_corte'] ?? null,
                'motivo' => $extra['motivo'] ?? null,
                'detalle' => $extra['detalle'] ?? null,
                'monto_para_reactivar' => $extra['monto_para_reactivar'] ?? null,
                'promesa_pago' => $extra['promesa_pago'] ?? null,
                'ciclo' => $extra['ciclo'] ?? null,
                'consumo_mes_gb' => $extra['consumo_mes_gb'] ?? null,
                'ampliado' => $extra['ampliado'] ?? null,
            ];
        }, $contratos);
    }

    /**
     * Las facturas pendientes, con el id que su propio listado no trae.
     *
     * `/contactos/buscar` manda de cada pendiente el código, el saldo y el
     * vencimiento, pero NO el id — y sin id no hay detalle que pedir, que es
     * justo lo que el agente quiere abrir cuando le acaban de preguntar "¿y esos
     * $76.000 de qué son?". El id se saca cruzando por código con el historial,
     * que ya está descargado y sí lo trae. Si la factura cayó fuera de las
     * últimas veinte, se queda sin id y la fila simplemente no se puede abrir.
     */
    private static function pendientes(array $facturas, array $historial): array
    {
        $hoy = now()->toDateString();

        $idsPorCodigo = [];
        foreach ($historial as $f) {
            if (! empty($f['codigo']) && ! empty($f['id'])) {
                $idsPorCodigo[(string) $f['codigo']] = $f['id'];
            }
        }

        return array_map(fn (array $f) => [
            'id' => $idsPorCodigo[(string) ($f['codigo'] ?? '')] ?? null,
            'codigo' => $f['codigo'] ?? null,
            'por_pagar' => (float) ($f['por_pagar'] ?? 0),
            'vencimiento' => $f['vencimiento'] ?? null,
            'vencida' => ! empty($f['vencimiento']) && $f['vencimiento'] < $hoy,
            'contrato_nro' => $f['contrato_nro'] ?? null,
        ], $facturas);
    }

    /**
     * La ficha de quien Integra no conoce (o de quien no se puede buscar, como
     * el cliente que oculta su número tras un nombre de usuario).
     */
    private static function vacia(?string $tipoCriterio, ?string $criterio): array
    {
        return [
            'buscable' => $criterio !== null,
            'encontrado' => false,
            'coincidencias' => 0,
            'criterio' => $criterio ? ['tipo' => $tipoCriterio, 'valor' => $criterio] : null,
            'cliente' => null,
            'resumen' => null,
            'contratos' => [],
            'facturas' => ['pendientes' => [], 'historial' => []],
            'pagos' => [],
            'radicados' => [],
            'sin_permiso' => [],
        ];
    }

    /**
     * Los últimos 10 dígitos, que es como Integra guarda los celulares: WhatsApp
     * los entrega con indicativo (573046430059) y buscar así no encuentra nada.
     */
    private static function ultimos10(?string $valor): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $valor);

        if ($digitos === '') {
            return null;
        }

        return strlen($digitos) > 10 ? substr($digitos, -10) : $digitos;
    }
}
