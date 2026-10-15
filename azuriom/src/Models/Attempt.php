<?php

namespace Azuriom\Plugin\JangoPay\Models;

use Illuminate\Database\Eloquent\Model;

class Attempt extends Model
{
    protected $table = 'jangopay_attempts';
    protected $guarded = [];
    protected $casts = [
        'quoted_at' => 'datetime',
        'verified_at' => 'datetime',
        'delivery_started_at' => 'datetime',
        'delivered_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
