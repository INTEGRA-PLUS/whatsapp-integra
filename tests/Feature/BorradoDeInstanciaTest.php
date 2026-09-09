<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Borrar una instancia, que es irreversible.
 *
 * El 8-sep-2026 la papelera de esta pantalla se llevó por delante 11
 * conversaciones y 53 mensajes de un número recién sincronizado por
 * coexistencia. La única defensa era un `confirm()` del navegador que decía
 * "¿Eliminar esta instancia?" sin mencionar que arrastraba el historial, y no
 * hay `SoftDeletes` en el proyecto: todas las claves foráneas están en cascada.
 *
 * El agravante es que ese historial **no se puede volver a importar**: Meta
 * permite una sola sincronización por número, así que recuperarlo obliga a
 * desconectar el número desde la app del cliente y rehacer el registro entero.
 *
 * Y hay una incoherencia que esto viene a cerrar: borrar una conversación
 * suelta pasa por `ConversationDeletionRequest` y su aprobación, mientras que
 * borrar la instancia se llevaba todas de golpe sin preguntar.
 */
class BorradoDeInstanciaTest extends TestCase
{
    use RefreshDatabase;

    /** Sin escribir el nombre no se borra nada. */
    public function test_sin_confirmacion_no_borra_la_instancia(): void
    {
        [$user, $instance] = $this->instanciaConHistorial();

        $this->actingAs($user)
            ->delete(route('instances.destroy', $instance->id))
            ->assertSessionHasErrors('confirmacion');

        $this->assertDatabaseHas('instances', ['id' => $instance->id]);
        $this->assertSame(2, WhatsAppConversation::where('instance_id', $instance->id)->count());
    }

    /** Ni escribiendo cualquier otra cosa. */
    public function test_una_confirmacion_que_no_coincide_tampoco_borra(): void
    {
        [$user, $instance] = $this->instanciaConHistorial();

        $this->actingAs($user)
            ->delete(route('instances.destroy', $instance->id), ['confirmacion' => 'si, borrar'])
            ->assertSessionHasErrors('confirmacion');

        $this->assertDatabaseHas('instances', ['id' => $instance->id]);
    }

    /** Con el nombre exacto sí, y se lleva las conversaciones y los mensajes. */
    public function test_con_el_nombre_borra_la_instancia_y_su_historial(): void
    {
        [$user, $instance] = $this->instanciaConHistorial();

        $this->actingAs($user)
            ->delete(route('instances.destroy', $instance->id), ['confirmacion' => $instance->name])
            ->assertRedirect(route('instances.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('instances', ['id' => $instance->id]);
        $this->assertSame(0, WhatsAppConversation::where('instance_id', $instance->id)->count());
        $this->assertSame(0, WhatsAppMessage::count());
    }

    /** Mayúsculas y espacios de más no deberían frustrar a nadie. */
    public function test_la_confirmacion_no_distingue_mayusculas_ni_espacios(): void
    {
        [$user, $instance] = $this->instanciaConHistorial();

        $this->actingAs($user)
            ->delete(route('instances.destroy', $instance->id), ['confirmacion' => '  MI NÚMERO  '])
            ->assertRedirect(route('instances.index'));

        $this->assertDatabaseMissing('instances', ['id' => $instance->id]);
    }

    /** Desconectar apaga la instancia y no borra nada: es la salida por defecto. */
    public function test_desconectar_apaga_la_instancia_sin_borrar_nada(): void
    {
        [$user, $instance] = $this->instanciaConHistorial();

        $this->actingAs($user)
            ->post(route('instances.desconectar', $instance->id))
            ->assertSessionHas('success');

        $this->assertFalse($instance->fresh()->active);
        $this->assertSame(2, WhatsAppConversation::where('instance_id', $instance->id)->count());
        $this->assertSame(2, WhatsAppMessage::count());
    }

    /** Y reconectar la vuelve a encender. */
    public function test_reconectar_la_vuelve_a_encender(): void
    {
        [$user, $instance] = $this->instanciaConHistorial();
        $instance->update(['active' => false]);

        $this->actingAs($user)
            ->post(route('instances.reconectar', $instance->id))
            ->assertSessionHas('success');

        $this->assertTrue($instance->fresh()->active);
    }

    /**
     * Reconectar no puede dejar dos instancias activas con el mismo número: el
     * webhook identifica la instancia por `phone_number_id` y se queda con la
     * primera, así que la otra no recibiría ningún mensaje.
     *
     * La otra instancia vive en **otra empresa** a propósito: el índice único
     * de la tabla es `(company_id, phone_number_id)`, así que dentro de una
     * misma empresa la base de datos ya lo impide; el choque que hay que
     * comprobar es el que el índice deja pasar.
     */
    public function test_no_se_reconecta_si_otra_empresa_ya_tiene_ese_numero_activo(): void
    {
        [$user, $instance] = $this->instanciaConHistorial();
        $instance->update(['active' => false]);

        $otraEmpresa = Company::create(['name' => 'Vecina', 'slug' => 'vecina', 'active' => true]);

        Instance::create([
            'company_id' => $otraEmpresa->id,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'La otra',
            'phone_number_id' => $instance->phone_number_id,
            'waba_id' => 'waba-2',
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('instances.reconectar', $instance->id))
            ->assertSessionHasErrors('phone_number_id');

        $this->assertFalse($instance->fresh()->active);
    }

    /** El resumen dice en números qué se va a perder, y qué sobrevive. */
    public function test_el_resumen_cuenta_lo_que_se_perderia(): void
    {
        [$user, $instance] = $this->instanciaConHistorial();

        $this->actingAs($user)
            ->getJson(route('instances.resumen-borrado', $instance->id))
            ->assertOk()
            ->assertJson([
                'nombre' => 'Mi número',
                'conversaciones' => 2,
                'mensajes' => 2,
                'historial_importado' => false,
            ]);
    }

    /** Y nada de esto alcanza a la instancia de otra empresa. */
    public function test_no_se_puede_tocar_la_instancia_de_otra_empresa(): void
    {
        [, $ajena] = $this->instanciaConHistorial('otra');
        [$user] = $this->instanciaConHistorial('mia');

        $this->actingAs($user)->post(route('instances.desconectar', $ajena->id))->assertForbidden();
        $this->actingAs($user)->getJson(route('instances.resumen-borrado', $ajena->id))->assertForbidden();
        $this->actingAs($user)
            ->delete(route('instances.destroy', $ajena->id), ['confirmacion' => $ajena->name])
            ->assertNotFound();

        $this->assertDatabaseHas('instances', ['id' => $ajena->id]);
        $this->assertTrue($ajena->fresh()->active);
    }

    /**
     * @return array{0: User, 1: Instance}
     */
    private function instanciaConHistorial(string $sufijo = 'a'): array
    {
        $company = Company::create([
            'name' => 'Empresa '.$sufijo,
            'slug' => 'empresa-'.$sufijo,
            'active' => true,
        ]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => 'admin-'.$sufijo.'@ejemplo.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);

        setPermissionsTeamId($company->id);
        $role = Role::firstOrCreate(['name' => 'admin', 'company_id' => $company->id, 'guard_name' => 'web']);
        foreach (['instances.view', 'instances.update', 'instances.delete'] as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }
        $role->syncPermissions(Permission::all());
        $user->assignRole($role);

        $instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Mi número',
            'phone_number_id' => 'pnid-'.$sufijo,
            'waba_id' => 'waba-'.$sufijo,
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
        ]);

        foreach ([1, 2] as $n) {
            $conversation = WhatsAppConversation::create([
                'instance_id' => $instance->id,
                'wa_id' => '5730000000'.$n,
                'phone_number' => '5730000000'.$n,
                'status' => 'open',
            ]);

            WhatsAppMessage::create([
                'conversation_id' => $conversation->id,
                'instance_id' => $instance->id,
                'wamid' => 'wamid.'.$sufijo.$n,
                'direction' => 'inbound',
                'type' => 'text',
                'content' => 'hola',
                // 'received' no está en el enum de la columna: MySQL trunca y la
                // inserción revienta. Los entrantes los guarda el webhook como
                // 'delivered'.
                'status' => 'delivered',
            ]);
        }

        return [$user->fresh(), $instance];
    }
}
