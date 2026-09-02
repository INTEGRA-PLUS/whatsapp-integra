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
        'contact_id',
        'conversation_id',
        'message_id',
        'phone_number',
        'name',
        'variables',
        'status',
        'wamid',
        'error_message',
        'error_code',
        'error_details',
        'sent_at',
        'delivered_at',
        'read_at',
        'attempts',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'variables' => 'array',
    ];

    public function campaign()
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'campaign_id');
    }

    public function conversation()
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function message()
    {
        return $this->belongsTo(WhatsAppMessage::class, 'message_id');
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
