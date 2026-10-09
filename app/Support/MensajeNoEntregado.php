<?php

namespace App\Support;

/**
 * Texto en español para un mensaje cuyo contenido WhatsApp no entrega.
 *
 * Hay dos caminos que llegan aquí y conviene no confundirlos:
 *
 *  - En vivo, la Cloud API manda `type: unsupported` (o `type: errors`, según
 *    la versión) con el error 131051. El contenido original NO viaja: sólo, y
 *    no siempre, el tipo real dentro de `unsupported`.
 *  - En el volcado de coexistencia pasa lo mismo con los mensajes que Meta no
 *    sabe representar (llamadas, invitaciones a canal, vista única…), pero ahí
 *    el mensaje SÍ sigue existiendo en la app del celular. Decírselo al agente
 *    cambia mucho lo que puede hacer, así que el texto se adapta.
 *
 * Vive fuera del controlador porque el volcado y la migración de arreglo
 * necesitan exactamente la misma frase.
 */
class MensajeNoEntregado
{
    /** Tipos reales que Meta sí nombra dentro de `unsupported`. */
    private const ETIQUETAS = [
        'poll_creation'     => 'una encuesta',
        'poll_update'       => 'un voto en una encuesta',
        'edit'              => 'la edición de un mensaje anterior',
        'pin'               => 'un mensaje fijado',
        'keep_in_chat'      => 'un mensaje guardado en el chat',
        'group_invite'      => 'una invitación a un grupo',
        'newsletter_invite' => 'una invitación a un canal',
        'view_once'         => 'una foto o video de una sola vista',
        'gif'               => 'un GIF',
        'link_preview'      => 'un enlace con vista previa',
        'media_placeholder' => 'un archivo que todavía se estaba subiendo',
        'product'           => 'un producto del catálogo',
        'order'             => 'un pedido del catálogo',
        'list'              => 'una lista interactiva',
        'interactive'       => 'un mensaje interactivo',
        'button'            => 'un botón',
        'hsm'               => 'una plantilla',
        'reaction'          => 'una reacción',
        'image'             => 'una imagen',
        'location'          => 'una ubicación',
    ];

    /**
     * Tipos con los que Meta no dice nada: «unknown» llega tal cual dentro de
     * `unsupported` y el chat lo enseñaba entre paréntesis («El cliente envió
     * un mensaje (unknown)»), que al asesor no le dice nada.
     */
    private const SIN_DETALLE = ['unknown', 'unsupported', 'errors'];

    /** «una encuesta» describe bien dentro de una frase, pero como título sobra el artículo. */
    public static function sinArticulo(string $etiqueta): string
    {
        return ucfirst(preg_replace('/^(un|una|la) /', '', $etiqueta));
    }

    /**
     * @param  ?string  $tipoReal   El `unsupported.type` de Meta, si vino.
     * @param  array    $error      El primer elemento de `errors[]`.
     * @param  bool     $saliente   El mensaje lo mandó el negocio, no el cliente.
     * @param  bool     $delHistorial  Vino del volcado de coexistencia.
     * @param  bool     $enCelular  El número está en coexistencia: aunque la API
     *                              no lo reciba, el mensaje está en la app del
     *                              celular del negocio.
     */
    public static function describir(
        ?string $tipoReal,
        array $error = [],
        bool $saliente = false,
        bool $delHistorial = false,
        bool $enCelular = false
    ): string {
        // Borrar un mensaje («Se eliminó este mensaje» en el celular) le llega
        // a la API como `unsupported` de tipo `revoke`. No falta nada que
        // reenviar: el cliente lo quitó. Pedirle que lo reenviara como texto,
        // foto o archivo —lo que decía el chat el 9-oct-2026— no tenía sentido.
        if ($tipoReal === 'revoke') {
            return $saliente ? 'Eliminaste un mensaje.' : 'El cliente eliminó un mensaje.';
        }

        $quien = $saliente ? 'Enviaste' : 'El cliente envió';
        $que   = $tipoReal !== null ? (self::ETIQUETAS[$tipoReal] ?? null) : null;

        if (in_array($tipoReal, self::SIN_DETALLE, true)) {
            $tipoReal = null;
        }

        // Sin tipo reconocible, el detalle de Meta es lo único que queda. Sus
        // títulos genéricos ("Message type unknown") no aportan nada al agente,
        // así que se descartan y queda sólo la frase en español.
        if ($que === null) {
            $detalle = $tipoReal
                ?: ($error['error_data']['details'] ?? ($error['title'] ?? ($error['message'] ?? null)));

            if ($detalle !== null && preg_match('/(message type (unknown|is not currently supported)|unsupported message received)/i', $detalle)) {
                $detalle = null;
            }

            $que = 'un mensaje' . ($detalle ? " ({$detalle})" : '');
        }

        // Del historial el mensaje sigue en el celular: eso es accionable y es
        // lo primero que el agente necesita saber. En vivo, no: ahí lo único
        // que sirve es pedirle al cliente que lo reenvíe.
        if ($delHistorial) {
            return "{$quien} {$que}. WhatsApp no incluyó su contenido en el historial importado; "
                . 'sigue visible en la app del celular.';
        }

        // En coexistencia el mensaje SÍ está en el WhatsApp del celular del
        // negocio. El 9-oct-2026 un cliente de River mandó un video que se veía
        // en el celular y el CRM decía «pídele que lo reenvíe»: lo primero que
        // el asesor tiene que saber es dónde mirarlo.
        if ($enCelular) {
            return "{$quien} {$que} que WhatsApp no pasó al CRM. "
                . 'Está en el WhatsApp del celular del negocio: míralo ahí o pídele que lo reenvíe.';
        }

        return "{$quien} {$que}. WhatsApp no entrega ese tipo de mensaje a la API, "
                . 'así que su contenido no se puede mostrar. Pídele que lo reenvíe como texto, foto o archivo.';
    }

    /**
     * Las columnas que le corresponden a un mensaje no entregable.
     *
     * `no_entregado` es la marca que lee el chat: estos mensajes no son avisos
     * de la plataforma como un cambio de número, son huecos en la conversación
     * y se dibujan en su lado con el resto del hilo.
     */
    public static function columnas(
        array $mensaje,
        bool $saliente = false,
        bool $delHistorial = false,
        bool $enCelular = false
    ): array {
        $error       = $mensaje['errors'][0] ?? [];
        $unsupported = $mensaje['unsupported'] ?? null;
        $tipoReal    = is_array($unsupported) ? ($unsupported['type'] ?? null) : $unsupported;
        $eliminado   = $tipoReal === 'revoke';

        return [
            'type'     => 'system',
            'content'  => self::describir($tipoReal, $error, $saliente, $delHistorial, $enCelular),
            'metadata' => [
                // La frase entera no sirve como vista previa en la lista de
                // chats: ahí sólo caben unas palabras.
                'resumen'         => match (true) {
                    $eliminado => 'Mensaje eliminado',
                    isset(self::ETIQUETAS[(string) $tipoReal]) => self::sinArticulo(self::ETIQUETAS[(string) $tipoReal]) . ' (WhatsApp no lo entregó)',
                    default => 'Mensaje que WhatsApp no entregó',
                },
                'no_entregado'    => true,
                'eliminado'       => $eliminado,
                'en_celular'      => $enCelular,
                'tipo_original'   => $tipoReal,
                'error_code'      => $error['code'] ?? null,
                'del_historial'   => $delHistorial,
                'errors'          => $mensaje['errors'] ?? [],
                'unsupported'     => $unsupported,
                'unhandled'       => $mensaje,
            ],
        ];
    }
}
