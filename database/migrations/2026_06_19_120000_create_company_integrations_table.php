<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_integrations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            // Identificador interno de la integración. Por ahora: 'invoice_payments'
            $table->string('key', 64);
            // disconnected | connected | error
            $table->string('status', 32)->default('disconnected');
            // URL base del software Integra y demás configuración pública (no secreta)
            $table->string('base_url')->nullable();
            // Token devuelto por Integra (cifrado en el modelo)
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            // Datos del usuario logueado en Integra (id, nombre, etc.)
            $table->json('account')->nullable();
            // Activación dentro del chat
            $table->boolean('enabled')->default(false);
            // 'slash' (/) o 'at' (@)
            $table->string('trigger_type', 16)->default('slash');
            // comando sin el símbolo, ej. 'pagos'
            $table->string('trigger_command', 64)->nullable();
            // último error de conexión para mostrarlo en la UI
            $table->string('last_error')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->unique(['company_id', 'key']);
        });
        // Los permisos integrations.* se crean en la migración
        // 2026_06_19_000005_add_integrations_permissions.
    }

    public function down(): void
    {
        Schema::dropIfExists('company_integrations');
    }
};
