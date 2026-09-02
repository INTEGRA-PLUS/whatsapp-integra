<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $table = 'contacts';

    protected $fillable = [
        'company_id',
        'name',
        'phone_number',
        'phone_numbers',
        'email',
        'notes',
        'metadata',
        'source',
        'identificacion',
        'external_id',
        'opted_out_at',
        'opt_out_source',
        'opted_out_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'phone_numbers' => 'array',
        'opted_out_at' => 'datetime',
    ];

    protected $appends = ['all_numbers'];

    /**
     * Every number that belongs to this contact: the primary plus any extras.
     */
    public function getAllNumbersAttribute(): array
    {
        return array_values(array_unique(array_filter(array_merge(
            [$this->phone_number],
            $this->phone_numbers ?? []
        ))));
    }

    /**
     * Add a number to this contact's secondary list if it's not already known.
     */
    public function addNumber(?string $number): void
    {
        if (!$number || in_array($number, $this->all_numbers, true)) {
            return;
        }
        $this->phone_numbers = array_values(array_unique(array_merge($this->phone_numbers ?? [], [$number])));
        $this->save();
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function conversations()
    {
        return $this->hasMany(WhatsAppConversation::class, 'contact_id');
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Quien pidió no recibir campañas. Es la baja de los envíos masivos, no del
     * servicio: al cliente se le sigue respondiendo en el chat.
     */
    public function hasOptedOut(): bool
    {
        return $this->opted_out_at !== null;
    }

    public function scopeOptedOut($query)
    {
        return $query->whereNotNull('opted_out_at');
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('phone_number', 'like', "%{$search}%")
              ->orWhere('phone_numbers', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        });
    }
}
