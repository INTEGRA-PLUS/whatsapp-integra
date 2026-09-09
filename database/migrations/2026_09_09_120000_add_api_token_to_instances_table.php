<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La credencial de verdad para `/api/v1`.
     *
     * Hasta ahora el token era el `phone_number_id`, que no es un secreto: se
     * muestra en la pantalla de Instancias del propio producto y en el panel de
     * Meta. Quien lo conociera podía leer los mensajes de esa empresa y enviar
     * en su nombre.
     *
     * Se guarda el **hash**, nunca el token. Con SHA-256 y no bcrypt porque hay
     * que poder buscar la instancia *por* el token en cada petición: bcrypt
     * obligaría a recorrer la tabla entera comparando uno a uno. El token no es
     * una contraseña de persona —tiene 40 bytes aleatorios, no se puede
     * adivinar por fuerza bruta— así que el salt de bcrypt no aporta aquí.
     *
     * No se rellena nada: una instancia sin token sigue funcionando con el
     * esquema viejo mientras `whatsapp.api.allow_legacy_token` esté activo. La
     * migración de los clientes es una decisión de negocio, no de despliegue.
     */
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->string('api_token', 64)->nullable()->unique()->after('access_token');
            $table->timestamp('api_token_created_at')->nullable()->after('api_token');
            $table->timestamp('api_token_last_used_at')->nullable()->after('api_token_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropUnique(['api_token']);
            $table->dropColumn(['api_token', 'api_token_created_at', 'api_token_last_used_at']);
        });
    }
};
