<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class wallet_transactions extends Model
{
    protected $fillable = [
        'wallet_id',
        'payment_id',
        'amount_minor',
        'type',
    ];
}
