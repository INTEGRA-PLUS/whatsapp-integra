<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Las migraciones tienen que aguantar una tabla con historia.
 *
 * El 9-sep-2026 un despliegue tumbó producción siete minutos: la migración que
 * añade `api_token` a `instances` se encontró con que esa columna ya existía
 * —sobrante del renombrado de julio— y murió con «Duplicate column name». El
 * entrypoint corre `migrate --force` antes de php-fpm, así que el contenedor
 * entró en bucle de reinicio y el sitio devolvió 502.
 *
 * Las pruebas no lo vieron porque la base de test se construye desde cero. Este
 * archivo existe para cerrar justo ese hueco: monta el estado raro a mano y
 * vuelve a correr la migración encima.
 */
class MigracionesIdempotentesTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = 'database/migrations/2026_09_09_120000_add_api_token_to_instances_table.php';

    private const RUTA_LIMPIEZA = 'database/migrations/2026_09_09_200000_drop_api_token_sobrante_from_instances_table.php';

    /**
     * El caso exacto de producción: la columna ya está y la migración se vuelve
     * a ejecutar encima.
     */
    public function test_la_migracion_del_token_no_falla_si_la_columna_ya_existe(): void
    {
        $this->assertTrue(Schema::hasColumn('instances', 'api_token'));

        // Correrla de nuevo sobre la tabla que ella misma creó es el escenario
        // que rompió: antes lanzaba «Duplicate column name».
        (require base_path(self::RUTA))->up();

        $this->assertTrue(Schema::hasColumn('instances', 'api_token'));
        $this->assertTrue(Schema::hasColumn('instances', 'api_token_created_at'));
        $this->assertTrue(Schema::hasColumn('instances', 'api_token_last_used_at'));
    }

    /**
     * Y el caso a medias, que es peor: la primera columna entró y las otras no.
     */
    public function test_completa_lo_que_falte_sin_tocar_lo_que_ya_esta(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->dropColumn(['api_token_created_at', 'api_token_last_used_at']);
        });

        $this->assertFalse(Schema::hasColumn('instances', 'api_token_created_at'));

        (require base_path(self::RUTA))->up();

        $this->assertTrue(Schema::hasColumn('instances', 'api_token_created_at'));
        $this->assertTrue(Schema::hasColumn('instances', 'api_token_last_used_at'));
    }

    public function test_la_limpieza_se_lleva_la_columna_sobrante(): void
    {
        Schema::table('instances', function (Blueprint $table) {
            $table->text('api_token_sobrante_jul2026')->nullable();
        });

        (require base_path(self::RUTA_LIMPIEZA))->up();

        $this->assertFalse(Schema::hasColumn('instances', 'api_token_sobrante_jul2026'));
    }

    /**
     * Donde nunca hubo sobrante —cualquier entorno nuevo— la limpieza no debe
     * hacer nada ni quejarse.
     */
    public function test_la_limpieza_no_hace_nada_si_no_hay_sobrante(): void
    {
        $this->assertFalse(Schema::hasColumn('instances', 'api_token_sobrante_jul2026'));

        (require base_path(self::RUTA_LIMPIEZA))->up();

        $this->assertFalse(Schema::hasColumn('instances', 'api_token_sobrante_jul2026'));
        $this->assertTrue(Schema::hasColumn('instances', 'access_token'), 'La limpieza tocó lo que no debía.');
    }
}
