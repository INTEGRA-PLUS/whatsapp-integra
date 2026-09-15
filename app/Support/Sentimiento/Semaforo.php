<?php

namespace App\Support\Sentimiento;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;

/**
 * Convierte los mensajes de una conversación en un color.
 *
 * Aquí está la parte del diseño que no es léxico, y es la que de verdad decide
 * si el semáforo sirve para algo:
 *
 * **1. El color responde a "¿necesita atención antes que los demás?", no a
 * "¿tiene un problema?".** Todo el que escribe a soporte tiene un problema. Si
 * eso bastara para ponerse rojo, la bandeja entera sería roja el primer día y
 * nadie volvería a mirarla. Un cliente que reporta una avería con calma está en
 * verde, y eso es lo correcto aunque suene raro.
 *
 * **2. Manda la trayectoria, no el último mensaje.** Los casos que escalan se
 * distinguen por cómo evoluciona el ánimo. Se pondera con decaimiento
 * exponencial: lo reciente pesa más, pero lo de antes no desaparece.
 *
 * **3. Se enfada rápido y se calma despacio.** Un "gracias" no borra tres
 * mensajes de enfado. Sin esa asimetría, un cliente furioso al que le contestan
 * "ya lo reviso" y responde "ok" volvería a verde de golpe, y el agente que
 * tomara el chat después entraría a ciegas.
 *
 * **4. No todo es texto.** En 70.000 conversaciones de soporte, *si al cliente
 * lo ayudaron* predijo su valoración mejor que el tono con el que escribía. Tres
 * mensajes seguidos sin respuesta son una señal más fiable que cualquier
 * adjetivo, y esa no hay que leerla: se cuenta.
 *
 * Aislamiento: recibe la conversación ya resuelta y sólo consulta
 * `$conversation->messages()`, así que no hay forma de leer mensajes de otra
 * empresa desde aquí. Quien la resuelve es la extensión, desde la instancia.
 */
class Semaforo
{
    /**
     * Cuánto pesa cada mensaje respecto del siguiente más reciente.
     *
     * 0,6 da una memoria corta pero real: el quinto mensaje hacia atrás todavía
     * aporta un 13 %. Con 0,3 sólo contaría el último y volveríamos a decidir
     * por una frase; con 0,9 una conversación resuelta hace una semana seguiría
     * roja.
     */
    private const DECAIMIENTO = 0.6;

    /** Cuánto se avanza hacia la calma de una vez. Ver el punto 3. */
    private const INERCIA = 0.5;

    /**
     * Umbrales por sensibilidad: [rojo, amarillo].
     *
     * Asimétricos respecto al cero a propósito. El coste de los dos errores no
     * es el mismo: un cliente furioso que se cuela en verde cuesta mucho más que
     * uno tranquilo marcado en amarillo, así que el amarillo empieza pronto.
     */
    private const UMBRALES = [
        'bajo' => [-0.70, -0.30],
        'medio' => [-0.55, -0.18],
        'alto' => [-0.40, -0.10],
    ];

    public function __construct(private Analizador $analizador) {}

    /**
     * @param array<string, mixed> $ajustes Los de la extensión, ya saneados.
     *
     * @return ?Lectura null cuando no hay con qué juzgar: el frontend pinta
     *                 gris. Devolver verde aquí sería la mentira tranquilizadora
     *                 que hace que la gente deje de mirar el semáforo.
     */
    public function leer(WhatsAppConversation $conversation, array $ajustes): ?Lectura
    {
        $ventana = (int) ($ajustes['ventana_mensajes'] ?? 8);
        $rojas = $this->palabrasRojas($ajustes['palabras_rojas'] ?? '');

        $mensajes = $conversation->messages()
            ->where('direction', 'inbound')
            ->where('type', 'text')
            ->where('is_internal', false)
            ->orderByDesc('id')
            ->limit($ventana)
            ->get(['id', 'content']);

        if ($mensajes->isEmpty()) {
            return null;
        }

        $puntuaciones = $mensajes
            ->map(fn (WhatsAppMessage $m) => $this->analizador->mensaje((string) $m->content, $rojas))
            ->all();

        // Leído y sin nada que señalar ES verde, no gris. El gris se reserva
        // para lo que todavía no se ha mirado —una conversación sin mensajes de
        // texto del cliente—, y esa diferencia es la que hace que el verde
        // signifique algo: si "no analizado" también saliera verde, el color
        // sería una mentira tranquilizadora en la mitad de la bandeja.
        $esfuerzo = $this->esfuerzo($conversation);
        $hablan = array_filter($puntuaciones, fn (Puntuacion $p) => $p->score !== 0.0);

        $bruto = max(-1.0, min(1.0, $this->ponderar($puntuaciones) + $esfuerzo));
        $score = $this->suavizar($bruto, $conversation->sentiment_score);
        $nivel = $this->nivel($score, (string) ($ajustes['sensibilidad'] ?? 'medio'));

        return new Lectura(
            $nivel,
            round($score, 3),
            $this->motivo($nivel, $puntuaciones, $conversation),
            Lectura::ORIGEN_MATRIZ,
            // La matriz nunca se declara muy segura: su techo real en soporte
            // ronda la mitad de los casos. La confianza alta se la deja a la IA,
            // y es lo que permite que ésta pise el color sin discusión.
            $hablan === [] ? 0.35 : 0.5,
        );
    }

    /**
     * Media ponderada con decaimiento: el mensaje más reciente manda.
     *
     * @param list<Puntuacion> $puntuaciones En orden de más reciente a más antiguo.
     */
    private function ponderar(array $puntuaciones): float
    {
        $suma = 0.0;
        $pesos = 0.0;
        $peso = 1.0;

        foreach ($puntuaciones as $p) {
            // Los mensajes que no dicen nada —"ok", "hola", "listo"— NO votan,
            // pero sí consumen su posición en el decaimiento.
            //
            // Las dos mitades importan. Si votaran, un "hola" anterior partiría
            // por la mitad un mensaje de insultos y lo dejaría en amarillo: un
            // cliente que te llama ladrón es rojo, venga de donde venga. Y si
            // además no consumieran posición, el enfado de hace ocho mensajes
            // pesaría como si fuera el último.
            //
            // El silencio no es calma: una conversación sólo sale del rojo con
            // mensajes que digan algo bueno, no dejando de quejarse.
            if ($p->score !== 0.0) {
                $suma += $p->score * $peso;
                $pesos += $peso;
            }

            $peso *= self::DECAIMIENTO;
        }

        return $pesos > 0 ? $suma / $pesos : 0.0;
    }

    /**
     * Se enfada rápido y se calma despacio.
     *
     * Empeorar es inmediato; mejorar avanza la mitad del camino cada vez. Dos
     * mensajes amables para salir de rojo, no uno.
     */
    private function suavizar(float $bruto, ?float $previo): float
    {
        if ($previo === null || $bruto <= $previo) {
            return $bruto;
        }

        return $previo + ($bruto - $previo) * self::INERCIA;
    }

    /**
     * Lo que no dice el texto: cuántas veces lo ha pedido sin que nadie
     * conteste.
     *
     * Se cuenta sobre los mensajes reales y no sobre lo que el cliente diga que
     * lleva esperando, que es lo que hace que esta señal sea la más fiable de
     * todas: no depende de cómo escriba ni de que escriba bien.
     */
    private function esfuerzo(WhatsAppConversation $conversation): float
    {
        if ($conversation->status !== 'open') {
            return 0.0;
        }

        $ultimos = $conversation->messages()
            ->where('is_internal', false)
            ->where('type', '!=', 'system')
            ->orderByDesc('id')
            ->limit(6)
            ->pluck('direction');

        $seguidos = 0;

        foreach ($ultimos as $direccion) {
            if ($direccion !== 'inbound') {
                break;
            }

            $seguidos++;
        }

        return match (true) {
            $seguidos >= 5 => -0.40,
            $seguidos >= 3 => -0.25,
            default => 0.0,
        };
    }

    private function nivel(float $score, string $sensibilidad): string
    {
        [$rojo, $amarillo] = self::UMBRALES[$sensibilidad] ?? self::UMBRALES['medio'];

        return match (true) {
            $score <= $rojo => Lectura::ROJO,
            $score <= $amarillo => Lectura::AMARILLO,
            default => Lectura::VERDE,
        };
    }

    /**
     * Por qué está en ese color, en una línea que un agente pueda leer de paso.
     *
     * @param list<Puntuacion> $puntuaciones
     */
    private function motivo(string $nivel, array $puntuaciones, WhatsAppConversation $conversation): string
    {
        if ($nivel === Lectura::VERDE) {
            return 'Sin señales de fricción';
        }

        // Se explica por lo que pesó de verdad, y lo que más pesa es lo más
        // reciente: mencionar el enfado de hace ocho mensajes cuando el último
        // habla de cancelar sería describir otra conversación.
        foreach ($puntuaciones as $p) {
            foreach ($p->categorias as $categoria) {
                if (isset(Lexico::MOTIVOS[$categoria]) && $categoria !== 'positivo') {
                    return ucfirst(Lexico::MOTIVOS[$categoria]);
                }
            }
        }

        if ($this->esfuerzo($conversation) < 0) {
            return 'Lleva varios mensajes seguidos sin respuesta';
        }

        return 'Tono negativo en los últimos mensajes';
    }

    /**
     * Las palabras que añade la empresa, una por línea.
     *
     * @return list<string>
     */
    private function palabrasRojas(mixed $texto): array
    {
        if (! is_string($texto) || trim($texto) === '') {
            return [];
        }

        return collect(preg_split('/\R/u', $texto) ?: [])
            ->map(fn ($l) => trim((string) $l))
            ->filter()
            ->unique()
            ->take(50)
            ->values()
            ->all();
    }
}
