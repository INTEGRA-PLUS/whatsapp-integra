<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Macro extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'active',
        'actions',
    ];

    protected $casts = [
        'active' => 'boolean',
        'actions' => 'array',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
