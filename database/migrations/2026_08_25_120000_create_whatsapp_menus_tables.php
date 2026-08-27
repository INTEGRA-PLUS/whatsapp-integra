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
        // El menú en sí: el texto que acompaña a las opciones y cuándo se dispara.
        // Comparte la forma de AutoResponse (empresa + instancia opcional +
        // disparadores) para que un admin que ya configuró respuestas automáticas
        // no tenga que aprender un modelo mental nuevo.
        Schema::create('whatsapp_menus', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('instance_id')->nullable();
            $table->string('name');

            // Cuerpo del mensaje interactivo. El encabezado y el pie son
            // opcionales en la Cloud API y se omiten del payload si van vacíos.
            $table->string('header_text', 60)->nullable();
            $table->text('body_text');
            $table->string('footer_text', 60)->nullable();

            // Sólo se usa cuando el menú sale como lista: es el texto del botón
            // que abre el listado ("Ver opciones"). Con 3 opciones o menos el
            // menú sale como botones y este campo se ignora.
            $table->string('list_button_text', 20)->default('Ver opciones');

            // Un submenú NO se dispara solo: sólo se alcanza desde la opción de
            // otro menú. Sin esta bandera, un submenú con disparador "siempre"
            // competiría con su propio menú padre.
            $table->boolean('is_root')->default(true);

            // Disparadores, con la misma semántica que auto_responses.
            $table->string('trigger_text', 1000)->nullable();
            $table->json('match_types')->nullable();

            $table->boolean('active')->default(true);
            $table->unsignedInteger('cooldown_minutes')->default(60);
            $table->unsignedInteger('fires_count')->default(0);
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('instance_id')->references('id')->on('instances')->onDelete('cascade');
            $table->index(['company_id', 'active', 'is_root']);
        });

        // Cada fila del menú y qué hace al elegirla.
        Schema::create('whatsapp_menu_options', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('menu_id');
            $table->unsignedInteger('position')->default(0);

            // 24 caracteres es el límite de Meta para el título de una fila de
            // lista (los botones aceptan 20; se valida aparte según el formato).
            $table->string('title', 24);
            $table->string('description', 72)->nullable();

            // VARCHAR y no ENUM: los tipos de acción crecen cada vez que se
            // conecta una integración nueva, y con un ENUM cada uno de esos
            // pasos sería una migración que reescribe la tabla entera.
            $table->string('action_type', 32);
            $table->text('reply_text')->nullable();
            $table->unsignedBigInteger('target_menu_id')->nullable();
            $table->unsignedBigInteger('assign_to_user_id')->nullable();
            $table->timestamps();

            $table->foreign('menu_id')->references('id')->on('whatsapp_menus')->onDelete('cascade');
            // El submenú destino se pone a null en vez de arrastrar el menú padre
            // al borrarlo: perder una opción es preferible a perder el menú entero.
            $table->foreign('target_menu_id')->references('id')->on('whatsapp_menus')->nullOnDelete();
            $table->foreign('assign_to_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['menu_id', 'position']);
        });

        // Qué menú tiene delante el cliente ahora mismo.
        //
        // No hace falta para resolver el toque en un botón (el id de la opción
        // viaja de vuelta en el webhook), pero sí para el cliente que en lugar de
        // tocar escribe "1" o "consultar factura": sin esto ese mensaje cae en el
        // vacío, que es la queja clásica de todo menú de WhatsApp.
        Schema::create('whatsapp_menu_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('menu_id');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('whatsapp_conversations')->onDelete('cascade');
            $table->foreign('menu_id')->references('id')->on('whatsapp_menus')->onDelete('cascade');
            // Una conversación sólo puede estar esperando un menú a la vez.
            $table->unique('conversation_id');
        });

        $permissions = ['whatsapp_menus.view', 'whatsapp_menus.create', 'whatsapp_menus.update', 'whatsapp_menus.delete'];
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
        Schema::dropIfExists('whatsapp_menu_sessions');
        Schema::dropIfExists('whatsapp_menu_options');
        Schema::dropIfExists('whatsapp_menus');
    }
};
