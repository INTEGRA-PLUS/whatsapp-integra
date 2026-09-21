<?php

namespace App\Extensions;

use App\Services\TextoPredictivoIaClient;
use App\Support\AiPrompt;

/**
 * Texto predictivo: tres formas de contestar, sobre el cuadro de redacción.
 *
 * El caso real es la hora punta. Un asesor con nueve chats abiertos escribe la
 * misma frase cuarenta veces al día —«con gusto, ¿me confirma el número de
 * cédula?»— y el cuello de botella no es saber qué decir, es teclearlo. Las
 * respuestas rápidas ya cubren lo que se repite palabra por palabra; lo que no
 * cubren es lo que se repite en intención pero cambia en los detalles, que es
 * casi todo.
 *
 * ## Lo que NO hace, y es lo importante
 *
 * **No envía.** Ni con confirmación, ni con retardo, ni «si el asesor no toca
 * nada en diez segundos». Pulsar una sugerencia la mete en el campo de texto,
 * con el cursor dentro, y ahí se queda hasta que una persona pulse enviar. La
 * diferencia entre una herramienta que sugiere y un bot que responde es
 * exactamente ese clic, y es lo que permite tenerla encendida en conversaciones
 * donde jamás se dejaría contestar sola a un modelo.
 *
 * **No inventa datos.** El flujo de n8n redacta el tono; las cifras las pone el
 * asesor. Toda sugerencia con un número que no esté literalmente en la
 * conversación se descarta antes de llegar a la pantalla — la comprobación vive
 * en el nodo «Verificar sugerencias», y se hace en código, no pidiéndoselo al
 * modelo. Es la misma regla del flujo de menús: la IA entiende y redacta, el
 * código afirma. Aquí pesa más que en ningún otro sitio, porque lo que salga de
 * aquí lo va a enviar alguien con prisa, y alguien con prisa no relee.
 *
 * ## Por qué a petición, con el automático como ajuste
 *
 * Pedir sugerencias para cada mensaje que entra es gastar una inferencia en las
 * conversaciones que se contestan con «listo, gracias», que son la mitad. El
 * botón gasta cuando alguien lo necesita. El ajuste `automatico` existe para
 * quien prefiera tenerlas ya puestas al abrir el chat, y está encendido de
 * fábrica porque una sugerencia que hay que pedir llega tarde: para cuando
 * aparece, el asesor ya escribió media frase.
 *
 * Aun encendido, sólo se piden cuando el último mensaje es del cliente y el
 * campo está vacío: sugerirle a alguien que ya está escribiendo es estorbarle.
 *
 * @see TextoPredictivoIaClient A quién se le pregunta.
 */
class TextoPredictivoExtension extends Extension
{
    public function slug(): string
    {
        return 'predictive_text';
    }

    public function name(): string
    {
        return 'Texto predictivo';
    }

    public function description(): string
    {
        return 'Propone tres maneras de contestar el mensaje que el asesor tiene delante. Las escribe él.';
    }

    public function detail(): string
    {
        return 'Sobre el cuadro de redacción del chat aparecen hasta tres borradores de respuesta, '
            .'cada uno con una etiqueta que dice de qué va: «pedir la dirección», «confirmar el '
            .'envío», «avisar de que se está gestionando». Se pulsa uno y el texto entra en el '
            .'campo, listo para corregir.'
            ."\n\n"
            .'No envía nada. Nunca. Pulsar una sugerencia la escribe en el campo y ahí se queda '
            .'hasta que una persona pulse enviar — no hay confirmación automática ni envío '
            .'diferido. Y no inventa datos: cualquier sugerencia que traiga un precio, una fecha, '
            .'un plazo o un número de radicado que no esté ya escrito en la conversación se '
            .'descarta antes de que la veas. Si hace falta un dato que no hay, la sugerencia lo '
            .'pide en vez de inventárselo.'
            ."\n\n"
            .'Se piden solas al abrir un chat en el que el cliente escribió lo último y tú aún no '
            .'has escrito nada. Si prefieres pedirlas tú, se apaga el automático y queda el botón '
            .'de la barra del cuadro de redacción. Mientras escribes no molesta: en cuanto hay '
            .'texto en el campo, las sugerencias se quitan de en medio.'
            ."\n\n"
            .'No sustituye a las respuestas rápidas: aquéllas son tuyas, fijas y gratis, y siguen '
            .'donde estaban con la barra «/». Esto es para lo que cambia en cada conversación.';
    }

    public function icon(): string
    {
        return 'Lightbulb';
    }

    public function category(): string
    {
        return self::CATEGORIA_PRODUCTIVIDAD;
    }

    public function permissions(): array
    {
        return [
            'Leer los mensajes recientes de una conversación cuando el asesor pide sugerencias',
            'Enviar esos mensajes al servicio de IA configurado en la plataforma',
        ];
    }

    public function hooks(): array
    {
        return [
            'Al abrir un chat cuyo último mensaje es del cliente, si el automático está encendido',
            'Cuando un asesor pulsa el botón de sugerencias en el cuadro de redacción',
        ];
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'automatico',
                'type' => 'boolean',
                'label' => 'Pedirlas solas al abrir el chat',
                'help' => 'Sólo cuando el cliente escribió lo último y el asesor no ha escrito nada '
                    .'todavía. Apagado, las sugerencias se piden con el botón del cuadro de '
                    .'redacción y no se gasta nada hasta entonces.',
                'default' => true,
            ],
            [
                'key' => 'cuantas',
                'type' => 'number',
                'label' => 'Cuántas sugerencias',
                'help' => 'Tres es lo que cabe sobre el cuadro de redacción sin tapar la '
                    .'conversación. Por encima de tres nadie las lee: las hojea.',
                'default' => 3,
                'min' => 1,
                'max' => 5,
            ],
            [
                'key' => 'mensajes',
                'type' => 'number',
                'label' => 'Mensajes que lee antes de sugerir',
                'help' => 'Cuántos mensajes recientes ve el modelo. Más contexto es mejor '
                    .'sugerencia y más coste por petición. Con 16 se cubre de sobra el tramo que '
                    .'importa de un chat de soporte.',
                'default' => 16,
                'min' => 6,
                'max' => 40,
            ],
            [
                'key' => 'instrucciones',
                'type' => 'textarea',
                'label' => 'Cómo quieres que suenen',
                'help' => 'Lo que escribas aquí se SUMA a las reglas de la plataforma, no las '
                    .'reemplaza: sirve para el tono, el trato y lo que tu empresa no ofrece por '
                    .'WhatsApp. Ejemplo: «Tratamos de usted. No damos descuentos por chat. Si '
                    .'preguntan por garantía, remitimos al taller.» Lo que nunca cambia es que no '
                    .'inventa cifras ni envía nada solo.',
                'default' => '',
            ],
        ];
    }

    public function sanitizeSettings(array $input, int $companyId): array
    {
        return [
            'automatico' => (bool) ($input['automatico'] ?? true),
            // Los topes se aplican aquí y no sólo en el formulario porque estas
            // filas también se escriben desde tinker, y estos dos números son
            // cuántas inferencias y cuánto texto salen de la empresa por clic.
            'cuantas' => max(1, min(5, (int) ($input['cuantas'] ?? 3))),
            'mensajes' => max(6, min(40, (int) ($input['mensajes'] ?? 16))),
            // El mismo saneado que el prompt entrenable del flujo de chats: le
            // quita al texto la forma de instrucción —marcadores de turno,
            // tokens `<|im_start|>`/`[INST]`, los delimitadores `===` y `---`—
            // para que unas «preferencias de estilo» no puedan convertirse en un
            // «ignora las reglas anteriores». El flujo además repite las reglas
            // innegociables DESPUÉS de este bloque, porque en un prompt la
            // última palabra pesa.
            'instrucciones' => mb_substr(AiPrompt::sanitizeInstructions((string) ($input['instrucciones'] ?? '')), 0, 2000),
        ];
    }
}
