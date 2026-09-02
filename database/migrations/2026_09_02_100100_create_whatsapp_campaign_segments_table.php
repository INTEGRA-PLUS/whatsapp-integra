<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segmentos: el criterio con el que se eligieron unos destinatarios, guardado
 * con un nombre para poder repetirlo en la siguiente campaña.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_campaign_segments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name', 120);
            // conversations | contacts
            $table->string('source', 20)->default('conversations');
            $table->json('filters')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_campaign_segments');
    }
};
