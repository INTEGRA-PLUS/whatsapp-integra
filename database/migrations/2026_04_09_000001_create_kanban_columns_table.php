<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanban_columns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name', 100);
            $table->string('color', 50)->default('bg-slate-500');
            $table->string('icon', 50)->default('Zap');
            $table->string('subtitle', 100)->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->index(['company_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanban_columns');
    }
};
