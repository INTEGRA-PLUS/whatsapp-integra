<?php

namespace App\Support;

use App\Models\Company;

/**
 * Qué puede usar una empresa según su plan, y si se le cobra.
 *
 * Todas las preguntas sobre planes pasan por aquí. Es a propósito: un candado
 * repartido por cinco controladores es un candado que alguien se deja abierto al
 * añadir el sexto.
 *
 * **Lo que este objeto NO hace es apagar el CRM.** Ni por plan vencido, ni por
 * cobro suspendido, ni por crédito de IA agotado. Decide qué se puede
 * *instalar* y avisa de lo demás. Apagarle el WhatsApp a una cooperativa un día
 * de recaudo por una factura pendiente es la forma más cara que existe de
 * cobrar, y el que se queda sin atender es el socio, que no debe nada.
 */
class PlanDeLaEmpresa
{
    public function __construct(private Company $company) {}

    public static function de(Company $company): self
    {
        return new self($company);
    }

    public function slug(): string
    {
        $plan = (string) ($this->company->plan ?: 'inteligente');

        return isset(config('planes.disponibles')[$plan]) ? $plan : 'inteligente';
    }

    public function nombre(): string
    {
        return config("planes.disponibles.{$this->slug()}.nombre", 'Inteligente');
    }

    /**
     * ¿Puede esta empresa instalar esta extensión?
     */
    public function permiteExtension(string $slug): bool
    {
        $permitidas = config("planes.disponibles.{$this->slug()}.extensiones", []);

        return $permitidas === '*' || in_array($slug, (array) $permitidas, true);
    }

    /**
     * ¿Puede encender este ajuste concreto?
     *
     * Existe porque una extensión puede estar en un plan y tener dentro un
     * ajuste que no: el semáforo entra en Automatización, pero «afinar con IA»
     * es del plan Inteligente. Sin esto el candado se saltaría por el
     * formulario de ajustes, que es por donde nadie mira.
     */
    public function permiteAjuste(string $slug, string $ajuste): bool
    {
        $conIa = config("planes.ajustes_con_ia.{$slug}", []);

        return ! in_array($ajuste, (array) $conIa, true) || $this->tieneIa();
    }

    public function tieneIa(): bool
    {
        return $this->creditoIa() > 0;
    }

    /**
     * Conversaciones con IA incluidas al mes.
     *
     * Sale del tramo de contactos contratado; si no hay tramo, del suelo del
     * plan. Un cliente sin tramo asignado es un cliente al que todavía nadie le
     * puso precio, y lo correcto ahí es darle el mínimo y que aparezca en el
     * panel, no cero —que parecería una avería—.
     */
    public function creditoIa(): int
    {
        $base = config("planes.disponibles.{$this->slug()}.ia");

        if ($base === null) {
            return 0;
        }

        $contactos = (int) $this->company->contactos_contratados;

        if ($contactos <= 0) {
            return (int) $base;
        }

        foreach (config('planes.credito_ia', []) as $tope => $credito) {
            if ($contactos <= $tope) {
                return (int) $credito;
            }
        }

        // Por encima del último tramo el precio es «a cotizar», así que el
        // crédito también: se le pone a mano y mientras tanto va con el mayor.
        return (int) max(config('planes.credito_ia', [0]));
    }

    /**
     * Lo que le toca pagar al mes, según su plan y su tramo.
     *
     * `null` significa «hay que cotizarlo a mano», y son dos casos distintos:
     * una empresa sin tramo asignado —a la que todavía nadie le puso precio— y
     * una por encima del último tramo, donde el precio es a cotizar a propósito.
     * En los dos, el panel enseña «sin definir» en vez de inventarse una cifra.
     */
    public function precioMensual(): ?int
    {
        $contactos = (int) $this->company->contactos_contratados;

        if ($contactos <= 0) {
            return null;
        }

        foreach (config('planes.precios', []) as $tope => $fila) {
            if ($contactos <= $tope) {
                return $fila[$this->slug()] ?? null;
            }
        }

        return null;
    }

    /**
     * El mismo precio pagando el año por adelantado.
     *
     * Dos meses gratis, o sea diez mensualidades repartidas en doce. Es el
     * número que se dice en la mesa: «299 de lista, 249 si pagas el año».
     */
    public function precioMensualAnual(): ?int
    {
        $lista = $this->precioMensual();

        if ($lista === null) {
            return null;
        }

        $gratis = (int) config('planes.meses_gratis_al_pagar_anual', 0);

        return (int) round($lista * (12 - $gratis) / 12);
    }

    // ─── Cobro ───────────────────────────────────────────────────────────────

    public function cobro(): string
    {
        $cobro = (string) ($this->company->cobro ?: 'cortesia');

        return in_array($cobro, config('planes.cobros', []), true) ? $cobro : 'cortesia';
    }

    /**
     * ¿Se le factura este mes?
     *
     * `cortesia` es el estado de los clientes que ya usaban el CRM antes de que
     * existieran los planes. No es un limbo ni un impago: es una decisión
     * comercial, y dura lo que tenga que durar.
     */
    public function seFactura(): bool
    {
        if (in_array($this->cobro(), ['cortesia', 'prueba'], true)) {
            return false;
        }

        return ! $this->enMesGratis();
    }

    public function enMesGratis(): bool
    {
        return $this->company->gratis_hasta
            && $this->company->gratis_hasta->endOfDay()->isFuture();
    }

    /** @return array<string, mixed> */
    public function resumen(): array
    {
        return [
            'plan' => $this->slug(),
            'plan_nombre' => $this->nombre(),
            'cobro' => $this->cobro(),
            'se_factura' => $this->seFactura(),
            'en_mes_gratis' => $this->enMesGratis(),
            'gratis_hasta' => optional($this->company->gratis_hasta)->toDateString(),
            'contactos_contratados' => $this->company->contactos_contratados,
            'credito_ia' => $this->creditoIa(),
            'tiene_ia' => $this->tieneIa(),
            'precio_usd' => $this->precioMensual(),
            'precio_usd_anual' => $this->precioMensualAnual(),
        ];
    }
}
