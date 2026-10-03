<?php

namespace App\Extensions;

use App\Services\IntegraClient;
use App\Support\IntegrationProvider;

/**
 * Cambio de clave WiFi: el asesor la cambia desde el contrato, sin pasarle el
 * caso a nadie.
 *
 * «Se me olvidó la clave del WiFi» y «me la están robando los vecinos» son de
 * las conversaciones más repetidas de un proveedor de internet, y hasta ahora
 * acababan en un radicado para que alguien entrara al equipo. Integra ya sabe
 * hacerlo: si la ONU del cliente está en el ACS, la clave llega al equipo en
 * minutos; si no, queda como solicitud para una persona — la misma bandeja que
 * la app de clientes.
 *
 * ## Por qué la clave la escribe el asesor y no se genera
 *
 * Porque se aplica en casa del cliente y **desconecta todos sus dispositivos**:
 * el cliente tiene que saber cuál es antes de que pase. La elige él, se la
 * dicta al asesor, y el asesor la escribe. Nada de claves aleatorias que luego
 * hay que mandar por chat.
 *
 * ## Qué no hace
 *
 * No le escribe nada al cliente. Integra devuelve el mensaje ya redactado
 * («en unos minutos tus equipos se desconectarán…») y el panel lo enseña con un
 * botón de copiar, como el informe del diagnóstico.
 *
 * Y la clave no se guarda en ningún sitio de este lado: ni en la base de datos,
 * ni en el log, ni en la respuesta.
 *
 * @see IntegraClient::changeWifiPassword() La llamada.
 */
class CambioDeClaveWifiExtension extends Extension
{
    public function slug(): string
    {
        return 'wifi_password';
    }

    public function name(): string
    {
        return 'Cambio de clave WiFi';
    }

    public function description(): string
    {
        return 'Cambia la clave del WiFi del cliente desde su contrato, sin abrir un radicado.';
    }

    public function detail(): string
    {
        return 'En el panel del cliente, dentro del contrato, aparece el WiFi: cómo se llaman sus '
            .'redes y si el cambio de clave es automático. El asesor escribe la clave nueva que '
            .'le dicta el cliente y se envía a Integra.'
            ."\n\n"
            .'Si el equipo del cliente está conectado a la plataforma de gestión, la clave llega '
            .'sola en unos minutos y cambia en las dos redes (2,4 y 5 GHz). Si no, queda como '
            .'solicitud para que la aplique una persona de tu equipo, en la misma bandeja que '
            .'las de la app de clientes.'
            ."\n\n"
            .'Cuando la clave se aplica, TODOS los dispositivos del cliente se desconectan y hay '
            .'que volver a conectarlos con la clave nueva. Por eso la elige el cliente y no se '
            .'genera sola: tiene que saberla antes de que pase.'
            ."\n\n"
            .'No le envía nada al cliente: Integra devuelve el mensaje ya redactado y aquí se '
            .'copia y se pega. La clave no se guarda en el CRM.'
            ."\n\n"
            .'Necesita tu cuenta de Integra conectada con el permiso «contratos.wifi», que no '
            .'viene con el de leer contratos. Si conectaste Integra antes de que existiera, '
            .'reconéctalo con tu usuario y contraseña.';
    }

    public function icon(): string
    {
        return 'Wifi';
    }

    public function category(): string
    {
        return self::CATEGORIA_PRODUCTIVIDAD;
    }

    public function requiresIntegration(): ?string
    {
        return IntegrationProvider::INTEGRA;
    }

    public function permissions(): array
    {
        return [
            'Leer las redes WiFi del contrato del cliente con el que se está conversando',
            'Pedirle a tu Integra que cambie la clave del WiFi de ese contrato',
        ];
    }

    public function hooks(): array
    {
        return [
            'Cuando un asesor abre un contrato en el panel de Integra y pulsa «Cambiar clave»',
        ];
    }
}
