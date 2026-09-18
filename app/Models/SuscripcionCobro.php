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
        'crm_incluido',
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
        'crm_incluido' => 'boolean',
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

        // Cuando el CRM lo cubre Integra y hay algo que cobrar, lo que se cobra
        // es SÓLO el complemento: nombrar los dos hace que los 49 dólares de la
        // IA parezcan el precio del Pro más la IA, y no hay forma de explicar
        // el importe.
        if ($this->crm_incluido && $ia && ! $this->estaCubierto()) {
            return $ia.' · '.mb_strtolower($ciclo);
        }

        $texto = trim($plan.($ia ? ' + '.$ia : '')).' · '.mb_strtolower($ciclo);

        // Un recibo en cero sin explicación se lee como un error de facturación.
        return $this->estaCubierto()
            ? $texto.' · incluido en tu paquete Integra'
            : $texto;
    }

    /**
     * De dónde sale el importe, línea a línea.
     *
     * Es la respuesta a «¿y por qué pago 49?». Sin esto el recibo daba un total
     * y un nombre de plan, y el cliente tenía que preguntarlo.
     *
     * Los precios salen del catálogo de hoy, así que **sólo se enseña el
     * desglose si suma exactamente el importe cobrado**. Si no cuadra —una
     * tarifa negociada, un precio a medida, una subida posterior— se devuelve
     * vacío: un desglose que no suma el total es peor que no tenerlo.
     *
     * @return list<array{concepto: string, importe: ?int, nota: ?string}>
     */
    public function desglose(): array
    {
        $crm = (int) config("planes.crm.{$this->plan}.precio", 0);
        $ia = $this->ia === 'ninguno' ? 0 : (int) config("planes.ia.{$this->ia}.precio", 0);

        $lineas = [[
            'concepto' => 'CRM '.config("planes.crm.{$this->plan}.nombre", $this->plan),
            'importe' => $this->crm_incluido ? null : $crm,
            'nota' => $this->crm_incluido ? 'Incluido en tu paquete Integra' : null,
        ]];

        if ($this->ia !== 'ninguno') {
            $lineas[] = [
                'concepto' => config("planes.ia.{$this->ia}.nombre", $this->ia),
                'importe' => $this->estaCubierto() ? null : $ia,
                'nota' => $this->estaCubierto() ? 'Incluido en tu paquete Integra' : null,
            ];
        }

        $suma = collect($lineas)->sum(fn (array $l) => (int) $l['importe']);

        return $suma === (int) $this->importe_usd ? $lineas : [];
    }
}
