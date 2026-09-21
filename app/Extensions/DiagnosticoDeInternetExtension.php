<?php

namespace App\Extensions;

use App\Services\IntegraClient;
use App\Support\IntegrationProvider;

/**
 * Diagnóstico de internet: qué le pasa al servicio del cliente, ahora mismo.
 *
 * El caso real es la conversación que empieza con «no me sirve el internet».
 * Hoy el asesor no tiene forma de saber nada: abre un radicado, el radicado
 * llega a redes, redes entra al ERP, consulta el router y contesta —cuando
 * contesta—. Mientras tanto el cliente espera, y la mitad de las veces la
 * respuesta es que el problema es del cliente (su router apagado, su cable) o
 * que el corte es del nodo entero y hay otras cuarenta personas preguntando lo
 * mismo. Dos casos que se resuelven en el chat sin mover a nadie.
 *
 * Esta extensión pone ese diagnóstico en el panel del cliente, dentro del
 * contrato: un botón que pregunta y contesta en segundos.
 *
 * ## No decide, informa
 *
 * **No crea radicados, no agenda visitas y no le escribe nada al cliente.** El
 * veredicto de Integra dice si hace falta visita y a qué área mandarlo; lo que
 * se haga con eso lo decide el asesor con la respuesta delante. Es deliberado:
 * una función que despachara técnicos sola con un diagnóstico de confianza
 * «media» mandaría camionetas a casas donde sólo había que reiniciar un router.
 *
 * ## Por qué necesita Integra sí o sí
 *
 * Porque no hay nada que diagnosticar sin el ERP: la consulta se la hace
 * Integra al router del cliente, y esta extensión sólo la pide y la pinta. Sin
 * conexión no degrada a «menos información», se queda sin ninguna — por eso es
 * la primera extensión con `requiresIntegration()` y por eso no se deja
 * instalar antes de conectar. Además necesita un scope que **no** viene con el
 * de leer contratos: `contratos.diagnostico` se pide aparte al emitir el token,
 * y un token que lee contratos perfectamente responde 403 aquí.
 *
 * ## Es lenta, y eso es parte del producto
 *
 * Entre 2 y 6 segundos, hasta unos 20 en el peor caso, porque se conecta al
 * router en el momento en vez de leer un estado guardado de hace una hora. Un
 * dato de hace una hora en una conversación de «no tengo internet» no vale
 * nada. La pantalla lo dice mientras espera, para que nadie piense que se
 * colgó.
 *
 * @see IntegraClient::contractDiagnostic() La llamada.
 */
class DiagnosticoDeInternetExtension extends Extension
{
    public function slug(): string
    {
        return 'internet_diagnostic';
    }

    public function name(): string
    {
        return 'Diagnóstico de internet';
    }

    public function description(): string
    {
        return 'Pregunta a Integra qué le pasa al servicio del cliente, en el momento, sin salir del chat.';
    }

    public function detail(): string
    {
        return 'En el panel del cliente, dentro del contrato, aparece un botón de diagnóstico. Al '
            .'pulsarlo, Integra se conecta al router de ese cliente y contesta qué está pasando: '
            .'si el servicio está bien, si el equipo no responde, si el corte es del nodo y '
            .'afecta a más gente, o si lo que falla está del lado del cliente.'
            ."\n\n"
            .'Con la respuesta vienen tres cosas que ahorran la consulta a redes: si hace falta '
            .'una visita técnica, a qué área hay que mandarlo, y un informe ya redactado para '
            .'copiar y pegarle al cliente. Cuando Integra no está seguro del todo lo dice, y ahí '
            .'lo sensato es repetir el diagnóstico antes de despachar a un técnico.'
            ."\n\n"
            .'No abre radicados, no agenda visitas y no le envía nada al cliente. Sólo consulta y '
            .'te enseña el resultado: lo que se haga después lo decides tú con el diagnóstico '
            .'delante.'
            ."\n\n"
            .'Necesita tu cuenta de Integra conectada, y que su token tenga el permiso '
            .'«contratos.diagnostico» — ese no viene con el de leer contratos, hay que pedirlo '
            .'aparte a quien administra tu Integra. Sin las dos cosas el botón no aparece.'
            ."\n\n"
            .'Tarda lo que tarda: entre 2 y 6 segundos normalmente, y hasta unos 20 cuando el '
            .'equipo del cliente está en las últimas. Es el precio de preguntarle al router de '
            .'verdad en vez de leer un dato viejo. Integra admite 20 consultas por minuto, así '
            .'que es para atender a un cliente, no para revisar la base entera.';
    }

    public function icon(): string
    {
        return 'Activity';
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
            'Leer el contrato del cliente con el que se está conversando',
            'Pedirle a tu Integra el diagnóstico de red de ese contrato',
        ];
    }

    public function hooks(): array
    {
        return [
            'Cuando un asesor abre un contrato en el panel de Integra y pulsa «Diagnosticar»',
        ];
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'informe_whatsapp',
                'type' => 'boolean',
                'label' => 'Mostrar el informe listo para copiar',
                'help' => 'Integra devuelve el diagnóstico ya redactado en lenguaje de cliente. '
                    .'Encendido, sale debajo del veredicto con un botón de copiar. No se envía '
                    .'nunca solo: lo pega el asesor si quiere, y puede editarlo antes.',
                'default' => true,
            ],
        ];
    }

    public function sanitizeSettings(array $input, int $companyId): array
    {
        return [
            'informe_whatsapp' => (bool) ($input['informe_whatsapp'] ?? true),
        ];
    }
}
