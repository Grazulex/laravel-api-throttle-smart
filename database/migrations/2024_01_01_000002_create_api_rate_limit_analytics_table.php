<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_rate_limit_analytics', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();
            $table->string('plan')->nullable();
            $table->string('endpoint')->nullable();
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('limited')->default(0);
            $table->timestamp('period')->index();
            $table->string('period_type')->default('hour');

            $table->index(['period', 'period_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_rate_limit_analytics');
    }
};
