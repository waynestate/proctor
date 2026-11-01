<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hooks', function (Blueprint $table) {
            $table->id();
            $table->longText('payload');
            $table->string('url', 512);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hooks');
    }
};
