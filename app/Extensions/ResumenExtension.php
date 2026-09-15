<?php

namespace App\Extensions;

use App\Services\ResumenIaClient;

/**
 * «Ponme al día»: el resumen de una conversación larga, a un clic.
 *
 * El caso real es el traspaso. Un asesor coge un chat que lleva cuarenta
 * mensajes y tres días —porque el compañero libra, porque entra el turno de
 * tarde, porque el cliente vuelve a escribir después de una semana— y antes de
 * contestar tiene que leérselo entero. Eso son minutos por conversación, y es
 * cuando se cometen los errores de "ya le dije que sí" a alguien a quien le
 * dijeron que no.
 *
 * ## Por qué a petición y no automático
 *
 * Resumir cada conversación según llega es gastar una inferencia en las que
 * tienen dos mensajes, que son la mayoría y no necesitan resumen. El botón
 * gasta sólo cuando alguien lo necesita, y además deja el dato que de verdad
 * interesa antes de ampliar esto: **cuántas veces se pulsa**. Si nadie lo usa,
 * automatizarlo habría sido gastar en silencio.
 *
 * ## Lo que NO hace
 *
 * No le escribe nada al cliente, no responde por nadie, no etiqueta ni asigna.
 * Resume y ya — igual que el semáforo marca y ya, y por el mismo motivo: una
 * acción automática sobre una lectura de IA hay que acertarla antes de
 * encenderla.
 *
 * Tampoco decide por el asesor. El resumen se muestra junto a la conversación,
 * no en su lugar: el hilo completo sigue estando ahí, un clic más allá.
 *
 * @see ResumenIaClient A quién se le pregunta.
 */
class ResumenExtension extends Extension
{
    public function slug(): string
    {
        return 'conversation_summary';
    }

    public function name(): string
    {
        return 'Resumen de conversación con IA';
    }

    public function description(): string
    {
        return 'Pone al día al asesor de un chat largo en cinco líneas, sin tener que leerlo entero.';
    }

    public function detail(): string
    {
        return 'Añade un botón «Resumir» en la cabecera del chat. Al pulsarlo, un modelo lee la '
            .'conversación y devuelve tres cosas: qué ha pasado, los puntos clave y qué queda '
            .'pendiente. Se abre sobre el chat y se cierra cuando ya no hace falta.'
            ."\n\n"
            .'Es para los traspasos: el turno que entra, el compañero que libra, el cliente que '
            .'vuelve tras una semana. En un chat de cuarenta mensajes ahorra la lectura completa, '
            .'que es donde se cometen los errores de contradecir lo que ya se le dijo.'
            ."\n\n"
            .'El resumen se guarda con la conversación y no se vuelve a pedir mientras nadie '
            .'escriba: si lo abres dos veces seguidas, la segunda es instantánea y no cuesta una '
            .'consulta al modelo. En cuanto llega un mensaje nuevo, el botón ofrece actualizarlo.'
            ."\n\n"
            .'No le escribe nada al cliente, no responde por nadie y no etiqueta ni asigna. El '
            .'hilo completo sigue estando donde estaba: el resumen se muestra junto a la '
            .'conversación, no en su lugar.';
    }

    public function icon(): string
    {
        return 'Sparkles';
    }

    public function category(): string
    {
        return self::CATEGORIA_PRODUCTIVIDAD;
    }

    public function permissions(): array
    {
        return [
            'Leer los mensajes de una conversación cuando alguien pide su resumen',
            'Enviar esos mensajes al servicio de IA configurado en la plataforma',
            'Guardar el resumen junto a la conversación',
        ];
    }

    public function hooks(): array
    {
        return [
            'Cuando un asesor pulsa «Resumir» en la cabecera del chat',
        ];
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'mensajes',
                'type' => 'number',
                'label' => 'Mensajes que resume',
                'help' => 'Cuántos mensajes recientes se le mandan al modelo. Más mensajes es más '
                    .'contexto y más coste por resumen. Con 40 se cubre la conversación completa '
                    .'en la mayoría de los casos.',
                'default' => 40,
                'min' => 10,
                'max' => 200,
            ],
            [
                'key' => 'tono',
                'type' => 'select',
                'label' => 'Cómo lo escribe',
                'help' => 'El «telegrama» va al grano y es el que se lee de un vistazo antes de '
                    .'contestar. El «informe» redacta más y sirve mejor para dejar constancia.',
                'default' => 'telegrama',
                'options' => [
                    ['value' => 'telegrama', 'label' => 'Telegrama — frases cortas, al grano'],
                    ['value' => 'neutro', 'label' => 'Normal'],
                    ['value' => 'informe', 'label' => 'Informe — redactado, para dejar constancia'],
                ],
            ],
            [
                'key' => 'minimo',
                'type' => 'number',
                'label' => 'A partir de cuántos mensajes aparece el botón',
                'help' => 'En un chat de tres mensajes el resumen sobra y el botón sólo estorba. '
                    .'Por debajo de este número no se muestra.',
                'default' => 8,
                'min' => 2,
                'max' => 50,
            ],
        ];
    }

    public function sanitizeSettings(array $input, int $companyId): array
    {
        $tono = $input['tono'] ?? null;

        return [
            // Los límites se aplican aquí y no sólo en el formulario porque
            // estas filas también se escriben desde tinker, y el número acaba
            // siendo cuántos mensajes viajan a un servicio externo por clic.
            'mensajes' => max(10, min(200, (int) ($input['mensajes'] ?? 40))),
            'tono' => in_array($tono, ['telegrama', 'neutro', 'informe'], true) ? $tono : 'telegrama',
            'minimo' => max(2, min(50, (int) ($input['minimo'] ?? 8))),
        ];
    }
}
