<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class AccountManager extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'avatar',
        'is_active',
        'show_email',
        'show_phone',
        'show_whatsapp',
        'show_photo',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_email' => 'boolean',
            'show_phone' => 'boolean',
            'show_whatsapp' => 'boolean',
            'show_photo' => 'boolean',
        ];
    }

    public function merchants(): HasMany
    {
        return $this->hasMany(User::class, 'account_manager_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AccountManagerAssignment::class);
    }

    public function activeMerchantsCount(): int
    {
        if (! Schema::hasColumn('users', 'account_manager_id')) {
            return 0;
        }

        return $this->merchants()
            ->where('role', User::ROLE_INFOPRODUTOR)
            ->count();
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
