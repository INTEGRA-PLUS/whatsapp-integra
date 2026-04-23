<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KanbanColumn extends Model
{
    protected $table = 'kanban_columns';

    protected $fillable = [
        'company_id',
        'tag_id',
        'name',
        'color',
        'icon',
        'subtitle',
        'position',
    ];

    public function tag()
    {
        return $this->belongsTo(Tag::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function conversations()
    {
        return $this->hasMany(WhatsAppConversation::class, 'kanban_column_id');
    }
}
