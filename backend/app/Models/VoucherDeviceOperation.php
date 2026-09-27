<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoucherDeviceOperation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['target_secret'];

    protected $casts = ['target_secret' => 'encrypted'];
}
