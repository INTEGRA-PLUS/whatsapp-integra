<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickReply extends Model
{
    use HasFactory;

    /** Pega un texto y ya está: lo que una respuesta rápida ha sido siempre. */
    public const TIPO_TEXTO = 'texto';

    /**
     * Manda la factura del cliente con la plantilla de Integra.
     *
     * No lleva `message`: lo que se envía es la plantilla `facturacion` con el
     * PDF que el ERP ya emitió. El asesor elige cuál, y el sistema propone la
     * última.
     */
    public const TIPO_FACTURA = 'factura';

    public const TIPOS = [self::TIPO_TEXTO, self::TIPO_FACTURA];

    protected $fillable = ['company_id', 'shortcut', 'tipo', 'message'];

    /** ¿Ésta manda un documento en vez de un texto? */
    public function mandaFactura(): bool
    {
        return $this->tipo === self::TIPO_FACTURA;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
