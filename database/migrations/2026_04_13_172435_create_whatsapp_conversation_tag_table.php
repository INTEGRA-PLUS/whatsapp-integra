<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('whatsapp_conversation_tag', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('whatsapp_conversation_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamps();

            $table->foreign('whatsapp_conversation_id', 'wa_conv_tag_foreign')
                  ->references('id')->on('whatsapp_conversations')->onDelete('cascade');
            $table->foreign('tag_id', 'tag_wa_conv_foreign')
                  ->references('id')->on('tags')->onDelete('cascade');
            
            $table->unique(['whatsapp_conversation_id', 'tag_id'], 'wa_conv_tag_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversation_tag');
    }
};
