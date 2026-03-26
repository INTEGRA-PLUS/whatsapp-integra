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
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('whatsapp_messages', 'incoming_invoice_id')) {
                $table->integer('incoming_invoice_id')->nullable()->after('metadata');
            }
            if (!Schema::hasColumn('whatsapp_messages', 'incoming_contract_id')) {
                $table->integer('incoming_contract_id')->nullable()->after('incoming_invoice_id');
            }
            if (!Schema::hasColumn('whatsapp_messages', 'incoming_payment_id')) {
                $table->integer('incoming_payment_id')->nullable()->after('incoming_contract_id');
            }
            if (!Schema::hasColumn('whatsapp_messages', 'incoming_company_nit')) {
                $table->integer('incoming_company_nit')->nullable()->after('incoming_payment_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropColumn([
                'incoming_invoice_id',
                'incoming_contract_id',
                'incoming_payment_id',
                'incoming_company_nit'
            ]);
        });
    }
};
