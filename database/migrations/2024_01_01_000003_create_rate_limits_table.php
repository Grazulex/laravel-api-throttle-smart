<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_limits', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedBigInteger('count')->default(0);
            $table->unsignedBigInteger('reset_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limits');
    }
};
