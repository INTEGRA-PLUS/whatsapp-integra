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

    public function estaPagado(): bool
    {
        return $this->estado === 'pagado';
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

        return trim($plan.($ia ? ' + '.$ia : '')).' · '.mb_strtolower($ciclo);
    }
}
