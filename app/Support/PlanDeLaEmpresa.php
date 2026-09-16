<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;

/**
 * Qué puede usar una empresa, cuánto paga y si se le cobra.
 *
 * Todas las preguntas sobre planes pasan por aquí. Es a propósito: un candado
 * repartido por cinco controladores es un candado que alguien se deja abierto al
 * añadir el sexto.
 *
 * ## Son dos cosas, no una
 *
 * - **El plan de CRM** (`crm`) decide el tamaño: agentes, contactos, líneas y el
 *   crédito de IA. Tiene precio fijo.
 * - **El complemento de IA** (`ia`) decide qué funciones con modelo se
 *   encienden. Tiene su propio precio y se suma.
 *
 * Antes eran tres planes con la IA metida dentro del más caro. Se separó el
 * 15-sep-2026 al ver que casi toda la base llegó con Integra y **ya paga el CRM
 * dentro del ERP**: lo que se les puede vender no es el CRM, es la IA.
 *
 * ## Lo que este objeto NO hace es apagar el CRM
 *
 * Ni por plan vencido, ni por cobro suspendido, ni por crédito de IA agotado.
 * Decide qué se puede *instalar* y avisa de lo demás. Apagarle el WhatsApp a una
 * cooperativa un día de recaudo por una factura pendiente es la forma más cara
 * que existe de cobrar, y el que se queda sin atender es el socio, que no debe
 * nada.
 */
class PlanDeLaEmpresa
{
    public function __construct(private Company $company) {}

    public static function de(Company $company): self
    {
        return new self($company);
    }

    // ─── El plan de CRM ──────────────────────────────────────────────────────

    /**
     * El plan de CRM contratado.
     *
     * Cae en `basico` cuando el valor no se reconoce: es el suelo, y dar el
     * suelo ante la duda es lo correcto — lo contrario sería regalar el plan
     * grande a quien tenga la columna con un plan retirado del catálogo.
     */
    public function slug(): string
    {
        $plan = (string) ($this->company->plan ?: 'basico');

        return isset(config('planes.crm')[$plan]) ? $plan : 'basico';
    }

    public function nombre(): string
    {
        return config("planes.crm.{$this->slug()}.nombre", 'Básico');
    }

    /** @return array<string, mixed> */
    private function planCrm(): array
    {
        return config("planes.crm.{$this->slug()}", []);
    }

    public function agentesIncluidos(): int
    {
        return (int) ($this->planCrm()['agentes'] ?? 0);
    }

    public function contactosIncluidos(): int
    {
        return (int) ($this->planCrm()['contactos'] ?? 0);
    }

    public function lineasIncluidas(): int
    {
        return (int) ($this->planCrm()['lineas'] ?? 0);
    }

    // ─── El complemento de IA ────────────────────────────────────────────────

    /** El complemento contratado: `ninguno`, `esencial` o `completa`. */
    public function slugIa(): string
    {
        $ia = (string) ($this->company->ia ?: 'ninguno');

        return isset(config('planes.ia')[$ia]) ? $ia : 'ninguno';
    }

    public function nombreIa(): string
    {
        return config("planes.ia.{$this->slugIa()}.nombre", 'Sin IA');
    }

    /**
     * ¿Tiene contratada alguna función con IA?
     *
     * **Ésta es la pregunta que hace el resto del sistema**: la pantalla del
     * flujo de IA, el candado de los ajustes y el panel pasan por aquí.
     * Preguntar por `$company->plan` en vez de por esto es lo que hace que un
     * cambio de catálogo rompa cinco sitios a la vez.
     */
    public function tieneIa(): bool
    {
        return $this->slugIa() !== 'ninguno';
    }

    /**
     * ¿Tiene contratados los flujos caros — chat y menús con IA?
     *
     * Separado de `tieneIa()` porque el coste no es parejo: una conversación de
     * chat con IA cuesta trece veces un análisis de semáforo. El complemento
     * Esencial da las funciones baratas; sólo Completa abre éstas.
     */
    public function permiteFlujoIa(string $flujo): bool
    {
        return in_array($flujo, (array) config("planes.ia.{$this->slugIa()}.flujos", []), true);
    }

    // ─── Qué puede instalar ──────────────────────────────────────────────────

    /**
     * ¿Puede esta empresa instalar esta extensión?
     *
     * Las del CRM van con cualquier plan; las demás dependen del complemento de
     * IA contratado.
     */
    public function permiteExtension(string $slug): bool
    {
        if (in_array($slug, (array) config('planes.extensiones_del_crm', []), true)) {
            return true;
        }

        return in_array($slug, (array) config("planes.ia.{$this->slugIa()}.extensiones", []), true);
    }

    /**
     * ¿Puede encender este ajuste concreto?
     *
     * Existe porque una extensión puede ir con el CRM y tener dentro un ajuste
     * que no: el semáforo entra con cualquier plan, pero «afinar con IA» exige
     * complemento. Sin esto el candado se saltaría por el formulario de ajustes,
     * que es por donde nadie mira.
     *
     * Un ajuste que ningún nivel de IA reclama es un ajuste normal y se permite:
     * lo contrario cerraría campos por accidente al añadir una extensión nueva.
     */
    public function permiteAjuste(string $slug, string $ajuste): bool
    {
        if (! $this->esAjusteDeIa($slug, $ajuste)) {
            return true;
        }

        return in_array(
            $ajuste,
            (array) config("planes.ia.{$this->slugIa()}.ajustes.{$slug}", []),
            true
        );
    }

    /**
     * Qué ajustes de esta extensión le quedan cerrados por no tener complemento.
     *
     * Lo necesita el catálogo para poder decir «la tienes, pero su parte con IA
     * no» — que es el caso del semáforo y el que más confusión ha causado: la
     * extensión se instala y funciona coloreando con el diccionario, y lo único
     * que exige complemento es «afinar con IA».
     *
     * Va aquí y no en el controlador porque el controlador lo leía de
     * `planes.ajustes_con_ia`, una clave que dejó de existir al separar el CRM
     * de la IA. Salía siempre vacío y nadie se enteraba: el candado funcionaba,
     * pero la pantalla no lo decía.
     *
     * @return list<string>
     */
    public function ajustesDeIaBloqueados(string $slug): array
    {
        $todos = [];

        foreach (config('planes.ia', []) as $nivel) {
            foreach ((array) ($nivel['ajustes'][$slug] ?? []) as $campo) {
                $todos[] = $campo;
            }
        }

        return array_values(array_filter(
            array_unique($todos),
            fn (string $campo) => ! $this->permiteAjuste($slug, $campo)
        ));
    }

    /** ¿Algún nivel de IA reclama este campo como suyo? */
    private function esAjusteDeIa(string $slug, string $ajuste): bool
    {
        foreach (config('planes.ia', []) as $nivel) {
            if (in_array($ajuste, (array) ($nivel['ajustes'][$slug] ?? []), true)) {
                return true;
            }
        }

        return false;
    }

    // ─── Crédito de IA ───────────────────────────────────────────────────────

    /**
     * Conversaciones con IA incluidas al mes.
     *
     * Sale del **plan de CRM**, no del complemento: es un número que depende del
     * tamaño del cliente, no de qué funciones tenga encendidas. Sin complemento
     * es cero, porque no hay nada que consumir.
     *
     * Al agotarse no se corta nada: se factura el exceso.
     */
    public function creditoIa(): int
    {
        return $this->tieneIa() ? (int) ($this->planCrm()['credito_ia'] ?? 0) : 0;
    }

    // ─── Precio ──────────────────────────────────────────────────────────────

    public function precioCrm(): int
    {
        return (int) ($this->planCrm()['precio'] ?? 0);
    }

    public function precioIa(): int
    {
        return (int) config("planes.ia.{$this->slugIa()}.precio", 0);
    }

    /**
     * Lo que le toca pagar al mes, todo junto.
     *
     * Al cliente de Integra sólo se le cobra el complemento: el CRM va dentro de
     * lo que ya paga por el ERP. Por eso su precio es cero hasta que contrate la
     * IA — que es exactamente la venta que se busca.
     */
    public function precioMensual(): int
    {
        // El precio a medida gana sobre todo lo demás, incluido el descuento de
        // los clientes de Integra: es el número que se negoció y firmó, no el
        // resultado de una fórmula. Aquí y no en cada pantalla, porque todo lo
        // que cobra —el ciclo, el cobro del mes, la suscripción, los avisos—
        // pasa por este método.
        if ($this->esAMedida()) {
            return $this->precioPersonalizado();
        }

        return $this->incluidoEnIntegra()
            ? $this->precioIa()
            : $this->precioCrm() + $this->precioIa();
    }

    /**
     * ¿Se le cobra un precio negociado en vez del de catálogo?
     *
     * El catálogo llega hasta 158 al mes y hay clientes por encima. Lo que pagan
     * de más no es un plan mayor: es lo que no cabe en ningún plan —desarrollos
     * sobre su ERP, atención personalizada—. Su plan y su complemento siguen
     * decidiendo qué funciones tiene; esto sólo decide cuánto paga.
     */
    public function esAMedida(): bool
    {
        return $this->precioPersonalizado() > 0;
    }

    public function precioPersonalizado(): int
    {
        return (int) ($this->company->precio_personalizado ?? 0);
    }

    // ─── El ciclo y el periodo pagado ────────────────────────────────────────

    /** Cada cuánto se le cobra: `mensual`, `trimestral` o `anual`. */
    public function ciclo(): string
    {
        $ciclo = (string) ($this->company->ciclo ?: 'mensual');

        return isset(config('planes.ciclos')[$ciclo]) ? $ciclo : 'mensual';
    }

    public function nombreCiclo(): string
    {
        return config("planes.ciclos.{$this->ciclo()}.nombre", 'Mensual');
    }

    /**
     * Lo que se le cobra de una vez en su ciclo.
     *
     * Mensualidades y no meses: el anual son doce meses por diez mensualidades,
     * y esa diferencia es el descuento. Multiplicar por los meses cobraría el
     * año completo y se comería el argumento con el que se vende.
     */
    public function precioDelCiclo(): int
    {
        $mensualidades = (int) config("planes.ciclos.{$this->ciclo()}.mensualidades", 1);

        return $this->precioMensual() * $mensualidades;
    }

    public function suscripcionHasta(): ?\Illuminate\Support\Carbon
    {
        return $this->company->suscripcion_hasta;
    }

    /**
     * ¿Tiene el periodo al día?
     *
     * Sin fecha devuelve `false`: es una empresa a la que nunca se le emitió un
     * cobro. Es distinto de vencida y se cuenta aparte, porque una es un olvido
     * administrativo y la otra es una renovación pendiente.
     */
    public function suscripcionVigente(): bool
    {
        return $this->suscripcionHasta()?->endOfDay()->isFuture() ?? false;
    }

    /**
     * Días que faltan para renovar. Negativo si ya venció, `null` si nunca se
     * le emitió un cobro.
     */
    public function diasParaRenovar(): ?int
    {
        return $this->suscripcionHasta()
            ? (int) now()->startOfDay()->diffInDays($this->suscripcionHasta()->endOfDay(), false)
            : null;
    }

    /**
     * El mismo precio pagando el año por adelantado.
     *
     * Dos meses gratis, o sea diez mensualidades repartidas en doce. Es el
     * número que se dice en la mesa: «29 de lista, 24 si pagas el año».
     */
    public function precioMensualAnual(): int
    {
        $gratis = (int) config('planes.meses_gratis_al_pagar_anual', 0);

        return (int) round($this->precioMensual() * (12 - $gratis) / 12);
    }

    // ─── Lo que tiene de verdad, contra lo que incluye su plan ───────────────

    /**
     * Cuántos contactos tiene de verdad.
     *
     * Se cuentan de `contacts`, que es donde acaban solos: cada persona nueva
     * que escribe queda registrada. La gracia está en la diferencia con lo que
     * incluye su plan — es lo que dice cuándo toca hablar de subir.
     */
    public function contactosReales(): int
    {
        return Contact::where('company_id', $this->company->id)->count();
    }

    public function agentesReales(): int
    {
        return User::where('company_id', $this->company->id)->where('active', true)->count();
    }

    public function lineasReales(): int
    {
        return Instance::where('company_id', $this->company->id)->count();
    }

    /**
     * El plan de CRM que le tocaría por lo que usa de verdad.
     *
     * Para proponerlo en vez de que alguien lo ponga a ojo. `null` si se sale
     * del catálogo, que es cuando hay que cotizar a mano.
     */
    public function planSugerido(): ?string
    {
        $contactos = $this->contactosReales();
        $agentes = $this->agentesReales();
        $lineas = $this->lineasReales();

        foreach (config('planes.crm', []) as $slug => $datos) {
            // Las tres, no dos. Con las líneas fuera, una empresa con dos líneas
            // en un plan de una salía avisada de que se pasó y con la sugerencia
            // de quedarse donde está: «tiene 2 líneas de 1, le correspondería
            // Básico». Una recomendación que se contradice enseña a no leerlas.
            if ($contactos <= $datos['contactos']
                && $agentes <= $datos['agentes']
                && $lineas <= $datos['lineas']) {
                return $slug;
            }
        }

        return null;
    }

    /**
     * ¿Se pasó de lo que incluye su plan, y de qué?
     *
     * **Esto no cobra ni bloquea nada.** Sale en el panel para que alguien llame
     * y hable de subir, que es una conversación comercial, no una factura
     * automática por crecer.
     *
     * Devuelve en qué se pasó y no un simple `true` porque son conversaciones
     * distintas: «tienes más agentes de los que incluye tu plan» se resuelve de
     * otra manera que «te crecieron los contactos».
     *
     * @return list<string> Alguna de: `contactos`, `agentes`, `lineas`.
     */
    public function sePasoDe(): array
    {
        $pasados = [];

        if ($this->contactosIncluidos() && $this->contactosReales() > $this->contactosIncluidos()) {
            $pasados[] = 'contactos';
        }

        if ($this->agentesIncluidos() && $this->agentesReales() > $this->agentesIncluidos()) {
            $pasados[] = 'agentes';
        }

        if ($this->lineasIncluidas() && $this->lineasReales() > $this->lineasIncluidas()) {
            $pasados[] = 'lineas';
        }

        return $pasados;
    }

    public function sePasoDelTramo(): bool
    {
        return $this->sePasoDe() !== [];
    }

    // ─── Cobro ───────────────────────────────────────────────────────────────

    public function cobro(): string
    {
        $cobro = (string) ($this->company->cobro ?: 'cortesia');

        return in_array($cobro, (array) config('planes.cobros', []), true) ? $cobro : 'cortesia';
    }

    /**
     * ¿El CRM va dentro de lo que ya paga por Integra?
     *
     * Distinto de «no se le factura»: un cliente en cortesía es un pendiente
     * comercial que alguien va a revisar, y uno de Integra **ya está pagando**.
     * Mezclarlos es lo que hace que dentro de unos meses alguien mande una
     * factura por algo que el cliente tiene contratado desde hace un año.
     */
    public function incluidoEnIntegra(): bool
    {
        return $this->cobro() === 'integra' || (bool) $this->company->viene_de_integra;
    }

    /**
     * ¿Se le factura este mes?
     *
     * El cliente de Integra sólo entra en la factura cuando contrata el
     * complemento de IA: el CRM ya se lo cobró el ERP, pero la IA no. Ése es el
     * único camino por el que un cliente de Integra empieza a aparecer en la
     * lista de cobro, y es la venta que se busca.
     */
    public function seFactura(): bool
    {
        if ($this->incluidoEnIntegra()) {
            return $this->tieneIa() && ! $this->enMesGratis();
        }

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
            'ia' => $this->slugIa(),
            'ia_nombre' => $this->nombreIa(),
            'tiene_ia' => $this->tieneIa(),

            'cobro' => $this->cobro(),
            'se_factura' => $this->seFactura(),
            'incluido_en_integra' => $this->incluidoEnIntegra(),
            'en_mes_gratis' => $this->enMesGratis(),
            'gratis_hasta' => optional($this->company->gratis_hasta)->toDateString(),

            'precio_crm' => $this->precioCrm(),
            'precio_ia' => $this->precioIa(),
            'precio_usd' => $this->precioMensual(),
            // Para que el panel pueda marcar «a medida» en vez de enseñar un
            // número que no cuadra con ningún plan del catálogo.
            'a_medida' => $this->esAMedida(),
            'precio_de_catalogo' => $this->incluidoEnIntegra()
                ? $this->precioIa()
                : $this->precioCrm() + $this->precioIa(),
            'precio_usd_anual' => $this->precioMensualAnual(),

            'ciclo' => $this->ciclo(),
            'ciclo_nombre' => $this->nombreCiclo(),
            'precio_del_ciclo' => $this->precioDelCiclo(),
            'suscripcion_hasta' => optional($this->suscripcionHasta())->toDateString(),
            'suscripcion_vigente' => $this->suscripcionVigente(),
            'dias_para_renovar' => $this->diasParaRenovar(),

            'agentes_incluidos' => $this->agentesIncluidos(),
            'agentes_reales' => $this->agentesReales(),
            'contactos_incluidos' => $this->contactosIncluidos(),
            'contactos_reales' => $this->contactosReales(),
            'lineas_incluidas' => $this->lineasIncluidas(),
            'lineas_reales' => $this->lineasReales(),

            'credito_ia' => $this->creditoIa(),
            'plan_sugerido' => $this->planSugerido(),
            'se_paso_de' => $this->sePasoDe(),
            'se_paso_del_tramo' => $this->sePasoDelTramo(),
        ];
    }
}
