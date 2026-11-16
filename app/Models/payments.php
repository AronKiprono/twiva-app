<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class payments extends Model
{
    protected $fillable = [
        'wallet_id',
        'reference',
        'amount_minor',
        'status',
    ];
}
