<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppCampaignRecipient extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_campaign_recipients';

    protected $fillable = [
        'campaign_id',
        'phone_number',
        'name',
        'status',
        'wamid',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'campaign_id');
    }
}
