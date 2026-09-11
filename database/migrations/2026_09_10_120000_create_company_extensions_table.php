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
        // Qué extensiones tiene instaladas cada empresa.
        //
        // El catálogo NO está aquí: vive en código (config/extensions.php y las
        // clases de App\Extensions). Una extensión es comportamiento, y tener la
        // mitad en una clase y la otra mitad en una fila abre la puerta a que la
        // fila diga una cosa y el código haga otra. Además obligaría a una
        // migración y a sembrar cuarenta empresas por cada extensión nueva.
        //
        // Que no haya fila significa "no instalada". No se siembra nada al dar
        // de alta una empresa ni al publicar una extensión: el catálogo crece
        // solo, y desinstalar es borrar la fila con sus ajustes dentro.
        Schema::create('company_extensions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');

            // Clave del catálogo. String y no clave foránea a propósito: al otro
            // extremo hay una clase PHP, no una tabla. Una fila cuyo slug ya no
            // existe (extensión retirada) se ignora al resolver en vez de
            // romper el catálogo entero.
            $table->string('slug', 60);

            // Instalar y encender son decisiones distintas: una extensión se
            // instala, se configura con calma y se enciende cuando los ajustes
            // están listos. Apagarla no debe borrar esa configuración.
            $table->boolean('enabled')->default(true);

            // Ajustes ya saneados por la propia extensión. Null = los de fábrica.
            $table->json('settings')->nullable();

            $table->unsignedBigInteger('installed_by')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('installed_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['company_id', 'slug']);

            // El gancho programado recorre "todas las empresas que tienen X
            // encendida", que sin este índice es un escaneo de la tabla entera
            // cada cinco minutos.
            $table->index(['slug', 'enabled']);
        });

        $permissions = ['extensions.view', 'extensions.create', 'extensions.update', 'extensions.delete'];
        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $newPermissions = Permission::whereIn('name', $permissions)->get();
        if (Schema::hasTable('companies')) {
            DB::table('companies')->orderBy('id')->each(function ($company) use ($newPermissions) {
                setPermissionsTeamId($company->id);
                $adminRole = Role::where('name', 'admin')
                    ->where('company_id', $company->id)
                    ->where('guard_name', 'web')
                    ->first();
                if ($adminRole) {
                    $adminRole->givePermissionTo($newPermissions);
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_extensions');
    }
};
