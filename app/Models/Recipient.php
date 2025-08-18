<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recipient extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'group',
        'notes',
    ];

    public function groups()
    {
        return $this->belongsToMany(Group::class, 'group_recipient', 'recipient_id', 'group_id')
            ->withTimestamps();
    }
}

