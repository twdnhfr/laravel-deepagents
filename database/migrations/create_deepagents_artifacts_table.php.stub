<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deepagents_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('path')->unique();
            $table->longText('contents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deepagents_artifacts');
    }
};
