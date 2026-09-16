<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\User;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El candado de IA tiene que aguantar al EJECUTARSE, no sólo al instalar.
 *
 * `ExtensionController` impide instalar lo que no se contrató, pero no toca lo
 * que ya estaba instalado — a propósito, para no apagarle nada a nadie en una
 * migración. El efecto secundario era una fuga: una empresa que pierde el
 * complemento, o a la que se le apunta mal, seguía llamando al modelo y gastando
 * tokens que nadie paga.
 *
 * Se descubrió el 15-sep-2026 mirando la ficha de One Comunicaciones: `ia` en
 * `ninguno` y el resumen con IA encendido. Es un fallo que **no falla** —nadie ve
 * un error, todo funciona— y sólo se nota en la factura de Ollama.
 *
 * Por eso estos tests miran el candado y no el resultado: lo que hay que
 * proteger es que la puerta esté cerrada, no que la respuesta sea bonita.
 */
class CandadoDeIaEnEjecucionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El caso exacto que se encontró: extensión encendida, complemento retirado.
     *
     * Antes devolvía el resumen tan tranquilo. Ahora responde 402 — y 402 y no
     * 403 porque no es un problema de permisos sino de plan, y el frontend tiene
     * que poder decir «contrata el complemento» en vez de «no tienes acceso».
     */
    public function test_el_resumen_se_niega_si_perdio_el_complemento(): void
    {
        $company = $this->empresa(['ia' => 'ninguno']);
        $this->instalar($company, 'conversation_summary');
        $conversacion = $this->conversacion($company);

        $this->actingAs($this->admin($company))
            ->postJson("/api/chat/conversations/{$conversacion->id}/resumen")
            ->assertStatus(402);
    }

    /** Y con el complemento deja de ser un problema de plan. */
    public function test_con_el_complemento_el_resumen_ya_no_se_niega_por_plan(): void
    {
        $company = $this->empresa(['ia' => 'esencial']);
        $this->instalar($company, 'conversation_summary');
        $conversacion = $this->conversacion($company);

        // Lo que importa es que ya no sea 402: sin mensajes responderá otra
        // cosa, pero el plan deja de ser el motivo.
        $respuesta = $this->actingAs($this->admin($company))
            ->postJson("/api/chat/conversations/{$conversacion->id}/resumen");

        $this->assertNotSame(402, $respuesta->status());
    }

    /**
     * El semáforo con `usar_ia` encendido de antes deja de llamar al modelo.
     *
     * Y **no se apaga el semáforo**: la capa de léxico sigue coloreando sin
     * modelo, que es lo que hace que el cliente quiera la capa 2.
     */
    public function test_el_semaforo_deja_de_afinar_sin_complemento(): void
    {
        $company = $this->empresa(['ia' => 'ninguno']);
        $plan = PlanDeLaEmpresa::de($company);

        $this->assertFalse($plan->permiteAjuste('sentiment_traffic_light', 'usar_ia'));

        // Pero la extensión sigue siendo suya: va con el CRM.
        $this->assertTrue($plan->permiteExtension('sentiment_traffic_light'));
    }

    /**
     * Los dos flujos caros exigen el complemento Completa, no cualquiera.
     *
     * Una conversación de chat con IA cuesta trece veces un análisis de
     * semáforo. Si el complemento Esencial los abriera, el margen del nivel
     * barato se lo comería el flujo caro.
     */
    public function test_los_flujos_caros_exigen_el_complemento_completo(): void
    {
        $sin = PlanDeLaEmpresa::de($this->empresa(['ia' => 'ninguno']));
        $esencial = PlanDeLaEmpresa::de($this->empresa(['ia' => 'esencial']));
        $completa = PlanDeLaEmpresa::de($this->empresa(['ia' => 'completa']));

        foreach (['ai_menus', 'ai_chat'] as $flujo) {
            $this->assertFalse($sin->permiteFlujoIa($flujo), "Sin complemento no debería abrir {$flujo}.");
            $this->assertFalse($esencial->permiteFlujoIa($flujo), "Esencial no debería abrir {$flujo}.");
            $this->assertTrue($completa->permiteFlujoIa($flujo), "Completa tendría que abrir {$flujo}.");
        }
    }

    /**
     * Ninguna función con IA queda abierta para quien no tiene complemento.
     *
     * Recorre el catálogo en vez de enumerar a mano: una extensión con IA nueva
     * que alguien añada al catálogo entra sola en esta comprobación, que es
     * justo cuando se olvida el candado.
     */
    public function test_sin_complemento_no_se_abre_ninguna_funcion_de_ia(): void
    {
        $plan = PlanDeLaEmpresa::de($this->empresa(['ia' => 'ninguno']));

        $this->assertFalse($plan->tieneIa());
        $this->assertSame(0, $plan->creditoIa());

        foreach (config('planes.ia') as $slug => $nivel) {
            if ($slug === 'ninguno') {
                continue;
            }

            foreach ($nivel['extensiones'] ?? [] as $extension) {
                $this->assertFalse($plan->permiteExtension($extension), "«{$extension}» no debería estar abierta.");
            }

            foreach ($nivel['ajustes'] ?? [] as $extension => $campos) {
                foreach ((array) $campos as $campo) {
                    $this->assertFalse(
                        $plan->permiteAjuste($extension, $campo),
                        "El ajuste «{$campo}» de «{$extension}» no debería estar abierto."
                    );
                }
            }

            foreach ($nivel['flujos'] ?? [] as $flujo) {
                $this->assertFalse($plan->permiteFlujoIa($flujo), "El flujo «{$flujo}» no debería estar abierto.");
            }
        }
    }

    private function empresa(array $extra = []): Company
    {
        return Company::create(array_merge([
            'name' => 'Fibra '.uniqid(),
            'slug' => 'fibra-'.uniqid(),
            'active' => true,
        ], $extra));
    }

    /** Una conversación de verdad: el aislamiento se comprueba antes que el plan. */
    private function conversacion(Company $company): \App\Models\WhatsAppConversation
    {
        $instancia = \App\Models\Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea',
            'phone_number_id' => 'T-'.Str::random(10),
            'waba_id' => 'W-'.Str::random(10),
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
            'access_token' => '',
        ]);

        return \App\Models\WhatsAppConversation::create([
            'instance_id' => $instancia->id,
            'wa_id' => '573001112233',
            'phone_number' => '573001112233',
            'name' => 'Cliente',
            'status' => 'open',
        ]);
    }

    private function instalar(Company $company, string $slug): CompanyExtension
    {
        return CompanyExtension::create([
            'company_id' => $company->id,
            'slug' => $slug,
            'enabled' => true,
            'settings' => [],
            'installed_at' => now(),
        ]);
    }

    /**
     * Los permisos son de Spatie con teams: sin `setPermissionsTeamId` ni rol,
     * la ruta responde 403 y el test acaba midiendo el middleware en vez del
     * candado del plan.
     */
    private function admin(Company $company): User
    {
        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => Str::uuid().'@x.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);

        setPermissionsTeamId($company->id);

        $rol = Role::firstOrCreate(['name' => 'admin', 'company_id' => $company->id, 'guard_name' => 'web']);
        $rol->givePermissionTo(Permission::firstOrCreate(['name' => 'chat.view', 'guard_name' => 'web']));
        $user->assignRole($rol);

        return $user->fresh();
    }
}
