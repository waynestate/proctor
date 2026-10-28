<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('things', function (Blueprint $table) {
            $table->id();
            $table->json('meta');
            $table->string('email', 255)->unique();
            $table->date('day')->useCurrent();
            $table->timestamps();
        });

        Schema::table('things', function (Blueprint $table) {
            $table->renameColumn('a', 'b');
        });

        DB::statement('ALTER TABLE things ALGORITHM=INSTANT, ADD COLUMN x INT');
    }

    public function down(): void
    {
        Schema::dropIfExists('things');
    }
};
