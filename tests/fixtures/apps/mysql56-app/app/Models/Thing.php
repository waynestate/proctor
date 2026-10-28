<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Thing extends Model
{
    public function tagged()
    {
        return static::whereJsonContains('meta->tags', 'x')->get();
    }
}
