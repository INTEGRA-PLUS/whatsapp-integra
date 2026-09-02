<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un criterio de selección de destinatarios con nombre, para no rehacerlo en
 * cada campaña.
 *
 * Guarda el criterio, no la lista: "los contactos con etiqueta morosos" debe
 * significar quienes lo sean el día que se lance la campaña, no quienes lo eran
 * el día que alguien guardó el segmento.
 */
class WhatsAppCampaignSegment extends Model
{
    protected $table = 'whatsapp_campaign_segments';

    protected $fillable = [
        'company_id',
        'created_by',
        'name',
        'source',
        'filters',
    ];

    protected $casts = [
        'filters' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
