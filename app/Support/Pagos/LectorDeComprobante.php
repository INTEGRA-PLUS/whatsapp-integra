<?php

namespace App\Support\Pagos;

use App\Support\Documentos\ImagenDelCliente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Saca de la captura de un pago los datos con los que se registra: monto,
 * fecha, referencia, banco y a quién se pagó.
 *
 * Usa el mismo modelo de visión que `ImagenDelCliente`, pero no le pide prosa:
 * le pide JSON con claves fijas. La descripción libre servía para conversar y
 * no para pagar —«$89.900» en medio de una frase no se puede precargar en un
 * formulario—.
 *
 * **Lo que devuelve es una propuesta.** El modelo lee píxeles: confunde un 8 con
 * un 3 y no distingue un comprobante editado de uno real. Por eso nada de aquí
 * registra un pago; lo registra una persona al aprobarlo.
 *
 * Las claves van escritas en el prompt y no sólo en `format`, y `think` va en
 * false: los modelos que razonan ignoran el esquema en silencio y devuelven la
 * respuesta vacía o con claves inventadas (ver gpt-oss en la memoria del
 * proyecto).
 */
class LectorDeComprobante
{
    private const PROMPT = 'Mira esta imagen. Responde SOLO con un objeto JSON con exactamente estas claves:'
        .' "es_comprobante" (true si es un comprobante, recibo o pantallazo de un pago o transferencia; false si es otra cosa),'
        .' "monto" (el valor pagado en pesos, solo el número, sin símbolos; null si no se ve),'
        .' "fecha" (la fecha del pago en formato AAAA-MM-DD; null si no se ve),'
        .' "referencia" (el número de referencia, comprobante, aprobación o transacción, tal cual; null si no se ve),'
        .' "banco" (el banco o la app desde la que se pagó, p. ej. Bancolombia, Nequi, Daviplata, PSE, Efecty; null si no se ve),'
        .' "destino" (a nombre de quién o a qué cuenta o convenio se pagó; null si no se ve),'
        .' "estado" ("aprobado", "rechazado", "pendiente" o "desconocido", según lo que diga el comprobante).'
        .' No inventes nada que no se vea en la imagen.';

    /**
     * @return array{es_comprobante: bool, monto: ?float, fecha: ?string, referencia: ?string,
     *               banco: ?string, destino: ?string, estado: string, crudo: array}|null
     *         `null` si no se pudo leer (sin modelo, sin imagen o sin respuesta útil).
     */
    public static function leer(string $bytes): ?array
    {
        if (! ImagenDelCliente::configurado()) {
            return null;
        }

        try {
            $respuesta = Http::withToken((string) config('services.vision.token'))
                ->acceptJson()
                ->timeout((int) config('services.vision.timeout', 90))
                ->post(rtrim((string) config('services.vision.url'), '/').'/api/chat', [
                    'model' => (string) config('services.vision.model'),
                    'stream' => false,
                    'think' => false,
                    'format' => 'json',
                    'options' => ['num_predict' => 300, 'temperature' => 0],
                    'messages' => [[
                        'role' => 'user',
                        'content' => self::PROMPT,
                        'images' => [base64_encode(ImagenDelCliente::encoger($bytes))],
                    ]],
                ]);
        } catch (\Throwable $e) {
            Log::channel('whatsapp')->info('ℹ️ El modelo de visión no respondió al leer un comprobante', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $respuesta->successful()) {
            Log::channel('whatsapp')->warning('⚠️ El modelo de visión rechazó un comprobante', [
                'status' => $respuesta->status(),
                'body' => mb_substr((string) $respuesta->body(), 0, 300),
            ]);

            return null;
        }

        $crudo = self::json((string) ($respuesta->json('message.content') ?? ''));

        return $crudo === null ? null : self::normalizar($crudo);
    }

    /** Normaliza lo que dijo el modelo. Público para poder probarlo sin red. */
    public static function normalizar(array $crudo): array
    {
        $esComprobante = filter_var($crudo['es_comprobante'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $estado = strtolower(trim((string) ($crudo['estado'] ?? '')));

        return [
            'es_comprobante' => $esComprobante,
            'monto' => self::monto($crudo['monto'] ?? null),
            'fecha' => self::fecha($crudo['fecha'] ?? null),
            'referencia' => self::texto($crudo['referencia'] ?? null, 120),
            'banco' => self::texto($crudo['banco'] ?? null, 120),
            'destino' => self::texto($crudo['destino'] ?? null, 190),
            'estado' => in_array($estado, ['aprobado', 'rechazado', 'pendiente'], true) ? $estado : 'desconocido',
            'crudo' => $crudo,
        ];
    }

    /**
     * «$89.900», «89.900,00», «89,900.00», 89900: todos son 89900.
     *
     * En Colombia el punto separa miles y la coma decimales, pero las apps
     * bancarias no se ponen de acuerdo. Regla: si hay los dos signos, el último
     * es el decimal; si hay uno solo, es decimal únicamente cuando lo siguen
     * exactamente dos cifras y aparece una vez.
     */
    public static function monto(mixed $valor): ?float
    {
        if (is_int($valor) || is_float($valor)) {
            return $valor > 0 ? round((float) $valor, 2) : null;
        }

        $s = preg_replace('/[^\d.,]/', '', (string) $valor);

        if ($s === '' || ! preg_match('/\d/', $s)) {
            return null;
        }

        $ultimoPunto = strrpos($s, '.');
        $ultimaComa = strrpos($s, ',');

        if ($ultimoPunto !== false && $ultimaComa !== false) {
            $decimal = $ultimoPunto > $ultimaComa ? '.' : ',';
        } else {
            $signo = $ultimoPunto !== false ? '.' : ($ultimaComa !== false ? ',' : null);
            $decimal = $signo !== null
                && substr_count($s, $signo) === 1
                && preg_match('/\\'.$signo.'\d{2}$/', $s)
                ? $signo
                : null;
        }

        if ($decimal !== null) {
            $pos = strrpos($s, $decimal);
            $entero = preg_replace('/\D/', '', substr($s, 0, $pos));
            $s = ($entero === '' ? '0' : $entero).'.'.preg_replace('/\D/', '', substr($s, $pos + 1));
        } else {
            $s = preg_replace('/\D/', '', $s);
        }

        $n = (float) $s;

        return $n > 0 ? round($n, 2) : null;
    }

    /** AAAA-MM-DD o DD/MM/AAAA; una fecha futura es un error de lectura. */
    public static function fecha(mixed $valor): ?string
    {
        $s = trim((string) $valor);

        if ($s === '') {
            return null;
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd/m/y'] as $formato) {
            try {
                $f = Carbon::createFromFormat('!'.$formato, $s);
            } catch (\Throwable) {
                continue;
            }

            if ($f && $f->format($formato) === $s) {
                return $f->isAfter(now()->endOfDay()) ? null : $f->toDateString();
            }
        }

        return null;
    }

    private static function texto(mixed $valor, int $max): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $s = trim((string) $valor);

        return ($s === '' || strtolower($s) === 'null') ? null : mb_substr($s, 0, $max);
    }

    /** El primer objeto JSON de la respuesta, aunque venga envuelto en texto o en ```. */
    private static function json(string $texto): ?array
    {
        $datos = json_decode($texto, true);

        if (is_array($datos)) {
            return $datos;
        }

        if (preg_match('/\{.*\}/s', $texto, $m)) {
            $datos = json_decode($m[0], true);

            return is_array($datos) ? $datos : null;
        }

        return null;
    }
}
