<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $fillable = ['name'];

    public function recipients()
    {
        return $this->belongsToMany(Recipient::class, 'group_recipient', 'group_id', 'recipient_id')
            ->withTimestamps();
    }
}
