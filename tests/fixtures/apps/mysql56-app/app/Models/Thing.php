<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Thing extends Model
{
    public function tagged()
    {
        return static::whereJsonContains('meta->tags', 'x')->get();
    }

    public static function hasLegacyColumn(): bool { return Schema::hasColumn('things', 'legacy'); }
}
