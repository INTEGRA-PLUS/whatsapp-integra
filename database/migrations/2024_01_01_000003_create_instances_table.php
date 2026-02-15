<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('uuid')->unique();
            $table->string('name')->nullable();
            $table->string('phone_number_id')->nullable(); // Meta Cloud API Phone Number ID
            $table->string('waba_id')->nullable(); // WhatsApp Business Account ID
            $table->string('display_phone_number')->nullable(); // Número visible +57 318...
            $table->enum('type', ['meta', 'vibio', 'other'])->default('meta');
            $table->enum('status', ['active', 'inactive', 'pending'])->default('pending');
            $table->boolean('active')->default(true);
            $table->json('meta')->nullable(); // Configuraciones adicionales
            $table->timestamps();
            
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->unique(['company_id', 'phone_number_id']);
            $table->index(['company_id', 'active']);
            $table->index('phone_number_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instances');
    }
};
