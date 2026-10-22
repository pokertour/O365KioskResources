<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wayfinding displays: one screen in a lobby or a corridor listing several rooms.
        Schema::create('signboards', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('token_hash', 64)->index();
            $table->text('token')->nullable();
            $table->unsignedTinyInteger('rooms_per_page')->default(5);
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('room_signboard', function (Blueprint $table) {
            $table->id();
            $table->foreignId('signboard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('direction', 12)->nullable();
            $table->unique(['signboard_id', 'room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_signboard');
        Schema::dropIfExists('signboards');
    }
};
