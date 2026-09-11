<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Instance;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La portada.
 *
 * Lo que se protege aquí es sobre todo el aislamiento: la portada cruza cuatro
 * tablas para contar, y cada una se filtra por un camino distinto —las
 * conversaciones por su instancia, los contactos por la empresa—. Un `whereIn`
 * que se olvide es un cliente viendo el volumen de otro, y eso no se nota
 * mirando la pantalla porque los números salen igual de plausibles.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function empresaConUsuario(string $nombre): array
    {
        $company = Company::create(['name' => $nombre, 'slug' => Str::slug($nombre), 'active' => true]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => 'Dueño de '.$nombre,
            'email' => Str::slug($nombre).'@ejemplo.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ]);

        return [$company, $user];
    }

    private function instancia(Company $company): Instance
    {
        return Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Principal',
            'phone_number_id' => (string) random_int(100000, 999999),
            'waba_id' => (string) random_int(100000, 999999),
            'display_phone_number' => '+57 300 0000000',
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
        ]);
    }

    private function conversacionCon(Instance $instancia, array $atributos = []): WhatsAppConversation
    {
        return WhatsAppConversation::create(array_merge([
            'instance_id' => $instancia->id,
            'wa_id' => (string) random_int(1000000, 9999999),
            'phone_number' => '+57 300 1111111',
            'name' => 'Cliente',
            'status' => 'open',
        ], $atributos));
    }

    public function test_la_portada_cuenta_solo_lo_de_la_empresa_del_usuario(): void
    {
        [$mia, $usuario] = $this->empresaConUsuario('Mía');
        [$ajena] = $this->empresaConUsuario('Ajena');

        $miInstancia = $this->instancia($mia);
        $suInstancia = $this->instancia($ajena);

        $this->conversacionCon($miInstancia);
        $this->conversacionCon($suInstancia);
        $this->conversacionCon($suInstancia);

        $respuesta = $this->actingAs($usuario)->get('/');

        $respuesta->assertOk();
        $respuesta->assertInertia(fn ($page) => $page
            ->component('Dashboard/Index')
            ->where('metricas.abiertas', 1));
    }

    public function test_sin_asignar_solo_cuenta_las_abiertas_sin_agente(): void
    {
        [$company, $usuario] = $this->empresaConUsuario('Sin asignar');
        $instancia = $this->instancia($company);

        $this->conversacionCon($instancia);
        $this->conversacionCon($instancia, ['assigned_to' => $usuario->id]);
        $this->conversacionCon($instancia, ['status' => 'closed']);

        $this->actingAs($usuario)->get('/')->assertInertia(fn ($page) => $page
            ->where('metricas.abiertas', 2)
            ->where('metricas.sinAsignar', 1));
    }

    public function test_los_mensajes_de_hoy_se_separan_por_direccion(): void
    {
        [$company, $usuario] = $this->empresaConUsuario('Mensajes');
        $instancia = $this->instancia($company);
        $conversacion = $this->conversacionCon($instancia);

        foreach ([['inbound', 2], ['outbound', 3]] as [$direccion, $cuantos]) {
            for ($i = 0; $i < $cuantos; $i++) {
                WhatsAppMessage::create([
                    'conversation_id' => $conversacion->id,
                    'wamid' => 'wamid.'.Str::random(20),
                    'type' => 'text',
                    'content' => 'hola',
                    'direction' => $direccion,
                    'status' => 'sent',
                ]);
            }
        }

        // Uno viejo, que no debe contar en «hoy» pero sí existe en la tabla.
        WhatsAppMessage::create([
            'conversation_id' => $conversacion->id,
            'wamid' => 'wamid.'.Str::random(20),
            'type' => 'text',
            'content' => 'antiguo',
            'direction' => 'inbound',
            'status' => 'sent',
        ])->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->actingAs($usuario)->get('/')->assertInertia(fn ($page) => $page
            ->where('metricas.recibidosHoy', 2)
            ->where('metricas.enviadosHoy', 3));
    }

    public function test_la_actividad_devuelve_catorce_dias_aunque_no_haya_mensajes(): void
    {
        [$company, $usuario] = $this->empresaConUsuario('Actividad');
        $this->instancia($company);

        $this->actingAs($usuario)->get('/')->assertInertia(fn ($page) => $page
            ->count('actividad', 14));
    }

    public function test_conectar_whatsapp_se_marca_solo_cuando_hay_instancia_activa(): void
    {
        [$company, $usuario] = $this->empresaConUsuario('Puesta en marcha');

        $this->actingAs($usuario)->get('/')->assertInertia(fn ($page) => $page
            ->where('puestaEnMarcha.pasos.0.clave', 'whatsapp')
            ->where('puestaEnMarcha.pasos.0.hecho', false)
            ->where('puestaEnMarcha.hechos', 0));

        $this->instancia($company);
        Tag::create(['company_id' => $company->id, 'name' => 'Soporte', 'color' => '#fff']);

        // Dos pasos, no tres: la etiqueta arma su columna del tablero sola
        // (`TagObserver`), y por eso el tablero no figura como paso aparte.
        $this->actingAs($usuario)->get('/')->assertInertia(fn ($page) => $page
            ->where('puestaEnMarcha.pasos.0.hecho', true)
            ->where('puestaEnMarcha.pasos.1.hecho', true)
            ->where('puestaEnMarcha.hechos', 2)
            ->where('puestaEnMarcha.total', 5));
    }

    public function test_una_empresa_sin_instancias_no_rompe_la_portada(): void
    {
        [, $usuario] = $this->empresaConUsuario('Recién creada');

        $this->actingAs($usuario)->get('/')->assertOk();
    }
}
