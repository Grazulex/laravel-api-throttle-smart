<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_quotas', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();
            $table->unsignedBigInteger('used')->default(0);
            $table->unsignedBigInteger('bonus')->default(0);
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamps();

            $table->unique(['key', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_quotas');
    }
};
