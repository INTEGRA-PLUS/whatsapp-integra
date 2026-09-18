<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un cobro de la suscripción: qué se le cobró a una empresa y por qué periodo.
 *
 * Guarda una **copia** del plan, el complemento y el importe en vez de
 * calcularlos: los precios cambian y un recibo tiene que decir lo que se cobró,
 * no lo que costaría hoy.
 *
 * Los crea `Suscripcion`, que es quien sabe alargar el periodo. Crear una fila a
 * pelo deja la `suscripcion_hasta` de la empresa sin mover, y entonces el cobro
 * existe pero no sirve para nada.
 */
class SuscripcionCobro extends Model
{
    protected $table = 'suscripcion_cobros';

    protected $fillable = [
        'company_id',
        'plan',
        'ia',
        'ciclo',
        'importe_usd',
        'periodo_desde',
        'periodo_hasta',
        'estado',
        'pagado_at',
        'referencia',
        'referencia_onepay',
        'nota',
        'creado_por',
    ];

    protected $casts = [
        'periodo_desde' => 'date',
        'periodo_hasta' => 'date',
        'pagado_at' => 'datetime',
        'importe_usd' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * Los tres estados de un cobro.
     *
     * `cubierto` no es un pagado con otro nombre: nadie transfirió nada y no
     * hay referencia que buscar. Es «este mes se prestó el servicio y ya estaba
     * pagado por otra vía». Distinguirlos es lo que evita que un recibo en cero
     * acabe contando como ingreso en el panel.
     */
    public const PENDIENTE = 'pendiente';

    public const PAGADO = 'pagado';

    public const CUBIERTO = 'cubierto';

    public function estaPagado(): bool
    {
        return $this->estado === self::PAGADO;
    }

    /** Va incluido en el paquete de Integra: ni se cobra ni se manda a la pasarela. */
    public function estaCubierto(): bool
    {
        return $this->estado === self::CUBIERTO;
    }

    /** ¿Queda algo por cobrar aquí? Lo que decide si se manda a OnePay. */
    public function hayQueCobrarlo(): bool
    {
        return $this->estado === self::PENDIENTE && $this->importe_usd > 0;
    }

    /**
     * Cómo se lee en una lista: «Pro + IA Esencial · trimestral».
     *
     * Sale de la copia guardada y no del catálogo actual, que es justo la gracia
     * de haberla guardado.
     */
    public function concepto(): string
    {
        $plan = config("planes.crm.{$this->plan}.nombre", $this->plan);
        $ia = $this->ia === 'ninguno' ? null : config("planes.ia.{$this->ia}.nombre", $this->ia);
        $ciclo = config("planes.ciclos.{$this->ciclo}.nombre", $this->ciclo);

        $texto = trim($plan.($ia ? ' + '.$ia : '')).' · '.mb_strtolower($ciclo);

        // Un recibo en cero sin explicación se lee como un error de facturación.
        return $this->estaCubierto()
            ? $texto.' · incluido en tu paquete Integra'
            : $texto;
    }
}
