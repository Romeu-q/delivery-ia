<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{

    protected $fillable = ['store_id', 'name', 'phone', 'first_order_at', 'last_order_at'];

    protected function casts(): array
    {
        return [
            'first_order_at' => 'datetime',
            'last_order_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Telefone mascarado: dado pessoal não é enviado para a IA nem exibido por completo. */
    public function maskedPhone(): string
    {
        $digits = preg_replace('/\D/', '', $this->phone);

        return '(**) *****-'.substr($digits, -4);
    }
}
