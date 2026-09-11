<?php

namespace App\Services;

use App\Models\Instance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cuánta gente nueva admite hoy un número de WhatsApp.
 *
 * Meta limita a cuántos destinatarios **distintos** se le puede escribir
 * primero en 24 horas, por tramos: 250, 1.000, 10.000, 100.000 y sin límite. Se
 * sube solo, manteniendo volumen y calidad, y no hay botón ni ticket que lo
 * acelere: un número recién registrado empieza en 250 y llegar a 100.000 son
 * semanas.
 *
 * El producto ya leía `messaging_limit_tier` y lo pintaba en Configuración,
 * pero no lo usaba para nada: una campaña de 12.000 sobre un número en tramo de
 * 1.000 arrancaba igual, mandaba mil y el resto empezaba a fallar de uno en
 * uno. Quien la lanzó se enteraba contando los fallos.
 *
 * Esto no bloquea nada. Igual que {@see TemplateParameterGuard}, ante la duda
 * deja pasar: el tramo es el techo de destinatarios *nuevos* en 24 horas, y una
 * campaña repartida en varios días cabe de sobra. Lo que hace falta es que
 * quien pulsa enviar lo sepa antes, no después.
 */
class MessagingLimitService
{
    /**
     * Lo que Meta contesta, traducido a un número.
     *
     * `TIER_50` existe en cuentas sin verificar; se incluye porque un número sin
     * verificación de negocio es justo el que más sorprende al operador.
     */
    private const TRAMOS = [
        'TIER_50' => 50,
        'TIER_250' => 250,
        'TIER_1K' => 1000,
        'TIER_10K' => 10000,
        'TIER_100K' => 100000,
    ];

    /** Diez minutos: el tramo cambia de día en día, no de minuto en minuto. */
    private const MINUTOS_EN_CACHE = 10;

    public function __construct(private MetaWhatsAppService $meta) {}

    /**
     * El tramo y la calidad del número, cacheados.
     *
     * Devuelve `limite => null` cuando no se sabe —el número no responde, la
     * cuenta no reporta tramo, o es ilimitado—. Quien lo consume debe tratar
     * «no se sabe» como «no avises», nunca como «no puedes».
     *
     * @return array{tier: ?string, limite: ?int, ilimitado: bool, calidad: ?string, conocido: bool}
     */
    public function paraInstancia(Instance $instance): array
    {
        if (! $instance->isMetaConfigured()) {
            return $this->desconocido();
        }

        return Cache::remember(
            "wa:limite-mensajeria:{$instance->id}",
            now()->addMinutes(self::MINUTOS_EN_CACHE),
            function () use ($instance) {
                $respuesta = $this->meta->getPhoneNumber(
                    $instance->phone_number_id,
                    $instance->access_token
                );

                if (! ($respuesta['success'] ?? false)) {
                    // Que Meta no conteste no es un problema de la campaña. Se
                    // registra y se deja pasar sin aviso, que es menos malo que
                    // un aviso inventado.
                    Log::channel('whatsapp')->info('No se pudo leer el tramo de mensajería', [
                        'instance_id' => $instance->id,
                    ]);

                    return $this->desconocido();
                }

                return $this->interpretar($respuesta['data'] ?? []);
            }
        );
    }

    /**
     * ¿Cabe esta campaña en lo que el número admite hoy?
     *
     * @return array{cabe: bool, limite: ?int, tier: ?string, calidad: ?string,
     *               conocido: bool, destinatarios: int, aviso: ?string}
     */
    public function revisarCampana(Instance $instance, int $destinatarios): array
    {
        $limite = $this->paraInstancia($instance);

        $cabe = ! $limite['conocido']
            || $limite['ilimitado']
            || $limite['limite'] === null
            || $destinatarios <= $limite['limite'];

        return array_merge($limite, [
            'destinatarios' => $destinatarios,
            'cabe' => $cabe,
            'aviso' => $cabe
                ? $this->avisoDeCalidad($limite)
                : $this->avisoDeTramo($limite['limite'], $destinatarios),
        ]);
    }

    /**
     * El aviso, escrito para quien va a pulsar enviar.
     *
     * Dice el número, qué va a pasar y qué puede hacer. Un «has superado el
     * límite» a secas manda a soporte a preguntar las tres cosas.
     */
    private function avisoDeTramo(int $limite, int $destinatarios): string
    {
        $sobran = $destinatarios - $limite;

        return "Este número tiene permiso de WhatsApp para escribirle primero a "
            . number_format($limite, 0, ',', '.')." personas nuevas cada 24 horas, y la campaña son "
            . number_format($destinatarios, 0, ',', '.').". Los "
            . number_format($sobran, 0, ',', '.')." restantes serán rechazados por Meta, uno a uno, "
            . 'hasta que el plazo se renueve. El tramo sube solo enviando de forma sostenida y sin '
            . 'que la gente bloquee el número: reparte la campaña en varios días o úsala con menos gente.';
    }

    /**
     * La calidad baja no impide enviar, pero es el aviso previo a que Meta
     * restrinja el número. Callarlo es dejar que se descubra cuando ya pasó.
     */
    private function avisoDeCalidad(array $limite): ?string
    {
        if (($limite['calidad'] ?? null) !== 'RED') {
            return null;
        }

        return 'La calidad de este número está en rojo para WhatsApp, que es el paso previo a que le '
            . 'bajen el límite o lo restrinjan. Suele venir de gente que bloquea o reporta: conviene '
            . 'revisar a quién se le está escribiendo antes de lanzar una campaña grande.';
    }

    /** @return array{tier: ?string, limite: ?int, ilimitado: bool, calidad: ?string, conocido: bool} */
    private function interpretar(array $datos): array
    {
        $tier = $datos['messaging_limit_tier'] ?? null;
        $calidad = $datos['quality_rating'] ?? null;

        if ($tier === null) {
            return array_merge($this->desconocido(), ['calidad' => $calidad]);
        }

        // Cualquier tramo que Meta añada en el futuro cae aquí: sin número
        // conocido no se avisa, que es el lado seguro.
        $ilimitado = $tier === 'TIER_UNLIMITED';

        return [
            'tier' => $tier,
            'limite' => self::TRAMOS[$tier] ?? null,
            'ilimitado' => $ilimitado,
            'calidad' => $calidad,
            'conocido' => $ilimitado || isset(self::TRAMOS[$tier]),
        ];
    }

    /** @return array{tier: null, limite: null, ilimitado: false, calidad: null, conocido: false} */
    private function desconocido(): array
    {
        return [
            'tier' => null,
            'limite' => null,
            'ilimitado' => false,
            'calidad' => null,
            'conocido' => false,
        ];
    }
}
