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
        // Columna por columna y comprobando antes, que es como se escriben las
        // migraciones en este repo desde que un despliegue se encontró la tabla
        // en un estado que nadie había previsto.
        //
        // Aquí pasó exactamente eso: en producción ya había una columna
        // `api_token`, sobrante del renombrado de julio de 2026 —cuya migración
        // se salía sin borrarla si `access_token` ya existía—. El ALTER murió
        // con «Duplicate column name», el contenedor entró en bucle de reinicio
        // y el despliegue se llevó por delante la aplicación (9-sep-2026).
        //
        // Las pruebas no lo vieron porque la base de test se construye desde
        // cero: no arrastra la historia que sí arrastra un servidor de tres años.
        Schema::table('instances', function (Blueprint $table) {
            if (! Schema::hasColumn('instances', 'api_token')) {
                $table->string('api_token', 64)->nullable()->unique()->after('access_token');
            }

            if (! Schema::hasColumn('instances', 'api_token_created_at')) {
                $table->timestamp('api_token_created_at')->nullable()->after('api_token');
            }

            if (! Schema::hasColumn('instances', 'api_token_last_used_at')) {
                $table->timestamp('api_token_last_used_at')->nullable()->after('api_token_created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            if (Schema::hasColumn('instances', 'api_token')) {
                $table->dropUnique(['api_token']);
            }

            $table->dropColumn(array_values(array_filter(
                ['api_token', 'api_token_created_at', 'api_token_last_used_at'],
                fn ($columna) => Schema::hasColumn('instances', $columna)
            )));
        });
    }
};
