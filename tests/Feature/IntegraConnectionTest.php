<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Services\Integra;
use App\Services\IntegraClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La conexión con Integra, resuelta en un solo sitio.
 *
 * Lo que se protege aquí es la promesa que justificó el gateway: que la
 * respuesta a "¿hay conexión?" sea la misma la pregunte quien la pregunte. Antes
 * cada función la resolvía por su cuenta mirando sólo la tarjeta de pagos, así
 * que una empresa con Integra conectado desde Contactos tenía medio sistema
 * diciendo que sí y la otra mitad diciendo que no.
 */
class IntegraConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_ninguna_tarjeta_conectada_no_hay_conexion(): void
    {
        $company = $this->company();

        $this->assertFalse(Integra::connected($company->id));
        $this->assertNull(Integra::for($company->id));
    }

    public function test_una_tarjeta_conectada_basta(): void
    {
        $company = $this->company();
        $this->connect($company, CompanyIntegration::KEY_INVOICE_PAYMENTS);

        $this->assertTrue(Integra::connected($company->id));
        $this->assertInstanceOf(IntegraClient::class, Integra::for($company->id));
    }

    /**
     * Integra es un solo entorno por empresa; que el admin pulsara "Conectar"
     * desde la tarjeta de contactos y no desde la de pagos no debería dejar los
     * menús a oscuras.
     */
    public function test_vale_la_conexion_de_contactos_aunque_pagos_no_este(): void
    {
        $company = $this->company();
        $this->connect($company, CompanyIntegration::KEY_CONTACTS_SYNC);

        $this->assertTrue(Integra::connected($company->id));
        $this->assertSame(
            CompanyIntegration::KEY_CONTACTS_SYNC,
            Integra::connection($company->id)->key
        );
    }

    /**
     * Con las dos conectadas manda la MÁS RECIENTE, y no la de pagos.
     *
     * Es el fallo que dejó a varias empresas con todo en 401 mientras el panel
     * mostraba las dos tarjetas en verde: Integra desactiva los tokens
     * anteriores al emitir uno nuevo con el mismo nombre, y las dos tarjetas se
     * conectan con el mismo. Así que conectar Contactos revoca el token que
     * guardó Pagos, y preferir siempre Pagos era quedarse justo con el muerto.
     */
    public function test_con_las_dos_conectadas_manda_la_ultima_en_conectarse(): void
    {
        $company = $this->company();
        $this->connect($company, CompanyIntegration::KEY_INVOICE_PAYMENTS, 'https://pagos.test')
            ->update(['connected_at' => now()->subMonths(2)]);
        $this->connect($company, CompanyIntegration::KEY_CONTACTS_SYNC, 'https://contactos.test')
            ->update(['connected_at' => now()]);

        $this->assertSame('https://contactos.test', Integra::connection($company->id)->base_url);
    }

    /** Y al revés, para que no sea la tarjeta la que decide sino la fecha. */
    public function test_si_pagos_se_conecto_despues_manda_pagos(): void
    {
        $company = $this->company();
        $this->connect($company, CompanyIntegration::KEY_CONTACTS_SYNC, 'https://contactos.test')
            ->update(['connected_at' => now()->subMonths(2)]);
        $this->connect($company, CompanyIntegration::KEY_INVOICE_PAYMENTS, 'https://pagos.test')
            ->update(['connected_at' => now()]);

        $this->assertSame('https://pagos.test', Integra::connection($company->id)->base_url);
    }

    /**
     * Al emitir el token se piden los scopes explícitamente.
     *
     * `POST /api/v1/tokens` sin `abilities` NO da todos los permisos: da sólo
     * los cuatro del flujo de pagos. Como el wizard nunca los mandaba, ninguna
     * empresa conectada podía leer contratos ni crear radicados, y el menú de
     * WhatsApp derivaba a un asesor cada vez que alguien preguntaba por su
     * servicio — sin un solo error visible en el panel.
     */
    public function test_al_conectar_se_piden_todos_los_scopes_que_usa_la_integracion(): void
    {
        Http::fake([
            '*/api/v1/tokens' => Http::response([
                'success' => true,
                'data' => ['token' => 'itg_nuevo', 'abilities' => IntegraClient::ABILITIES],
            ], 201),
        ]);

        IntegraClient::connectWithLogin('https://demo.test', 'admin@empresa.test', 'secreto');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/api/v1/tokens')
                && $request['abilities'] === IntegraClient::ABILITIES;
        });

        // Los dos que faltaban en producción, por si alguien recorta la lista.
        $this->assertContains('contratos.leer', IntegraClient::ABILITIES);
        $this->assertContains('radicados.crear', IntegraClient::ABILITIES);
    }

    /**
     * Conectar una función conecta todas las del mismo proveedor.
     *
     * Es un solo entorno de Integra y un solo token: pedir la URL dos veces
     * sólo servía para escribirla mal en una de las dos, y para que el segundo
     * token revocara al primero dejando las dos tarjetas en verde con una
     * muerta.
     */
    public function test_conectar_una_funcion_conecta_las_del_mismo_proveedor(): void
    {
        Http::fake([
            '*/api/v1/tokens' => Http::response([
                'success' => true,
                'data' => ['token' => 'itg_nuevo'],
            ], 201),
            '*' => Http::response(['success' => true, 'data' => []], 200),
        ]);

        $company = $this->company();
        $user = $this->admin($company);

        $this->actingAs($user)
            ->postJson('/api/integrations/' . CompanyIntegration::KEY_INVOICE_PAYMENTS . '/connect', [
                'base_url' => 'https://demo.test',
                'email' => 'admin@empresa.test',
                'password' => 'secreto',
            ])
            ->assertOk();

        $rows = CompanyIntegration::where('company_id', $company->id)->get();

        $this->assertCount(2, $rows, 'Las dos funciones del proveedor quedan conectadas.');

        foreach ($rows as $row) {
            $this->assertTrue($row->isConnected(), $row->key . ' debería quedar conectada.');
            $this->assertSame('itg_nuevo', $row->access_token, 'Comparten el token: sólo hay uno vivo.');
        }

        // Y la misma URL, resuelta una sola vez: escribirla dos veces era la
        // forma de tener una de las dos mal.
        $this->assertCount(1, $rows->pluck('base_url')->unique());
    }

    /**
     * Lo que NO se comparte: cada función guarda si está activada en el chat y
     * con qué comando. Eso es suyo, no de la conexión.
     */
    public function test_conectar_no_pisa_los_ajustes_propios_de_cada_funcion(): void
    {
        Http::fake([
            '*/api/v1/tokens' => Http::response(['success' => true, 'data' => ['token' => 'itg_nuevo']], 201),
            '*' => Http::response(['success' => true, 'data' => []], 200),
        ]);

        $company = $this->company();
        $this->connect($company, CompanyIntegration::KEY_CONTACTS_SYNC)
            ->update(['enabled' => true, 'trigger_command' => 'clientes']);

        $this->actingAs($this->admin($company))
            ->postJson('/api/integrations/' . CompanyIntegration::KEY_INVOICE_PAYMENTS . '/connect', [
                'base_url' => 'https://demo.test',
                'email' => 'admin@empresa.test',
                'password' => 'secreto',
            ])
            ->assertOk();

        $contacts = CompanyIntegration::where('company_id', $company->id)
            ->where('key', CompanyIntegration::KEY_CONTACTS_SYNC)
            ->first();

        $this->assertTrue($contacts->enabled);
        $this->assertSame('clientes', $contacts->trigger_command);
        $this->assertSame('itg_nuevo', $contacts->access_token);
    }

    /** Y desconectar desconecta el proveedor entero, por lo mismo: es un token. */
    public function test_desconectar_apaga_todas_las_funciones_del_proveedor(): void
    {
        $company = $this->company();
        $this->connect($company, CompanyIntegration::KEY_INVOICE_PAYMENTS);
        $this->connect($company, CompanyIntegration::KEY_CONTACTS_SYNC);

        $this->actingAs($this->admin($company))
            ->postJson('/api/integrations/' . CompanyIntegration::KEY_INVOICE_PAYMENTS . '/disconnect')
            ->assertOk();

        $this->assertFalse(Integra::connected($company->id));
        $this->assertSame(
            0,
            CompanyIntegration::where('company_id', $company->id)->whereNotNull('access_token')->count()
        );
    }

    /** Una tarjeta en estado de error no cuenta como conexión. */
    public function test_una_conexion_con_error_no_cuenta(): void
    {
        $company = $this->company();
        $this->connect($company, CompanyIntegration::KEY_INVOICE_PAYMENTS)
            ->update(['status' => 'error', 'last_error' => 'Token inválido']);

        $this->assertFalse(Integra::connected($company->id));

        // Pero sus credenciales siguen ahí para poder reintentar: es lo que hace
        // el botón "Verificar" sin obligar a reescribir la URL y el token.
        $this->assertInstanceOf(
            IntegraClient::class,
            CompanyIntegration::where('company_id', $company->id)->first()->client()
        );
    }

    /** La conexión de una empresa no vale para otra. */
    public function test_la_conexion_no_se_cruza_entre_empresas(): void
    {
        $conectada = $this->company('Conectada', 'conectada');
        $sinConexion = $this->company('Sin conexión', 'sin-conexion');

        $this->connect($conectada, CompanyIntegration::KEY_INVOICE_PAYMENTS);

        $this->assertTrue(Integra::connected($conectada->id));
        $this->assertFalse(Integra::connected($sinConexion->id));
    }

    // ------------------------------------------------------------------

    private function company(string $name = 'Cmnet', string $slug = 'cmnet'): Company
    {
        return Company::create(['name' => $name, 'slug' => $slug, 'active' => true]);
    }

    /**
     * El primer usuario de una empresa recibe el rol admin con los permisos que
     * existan en ese momento (User::booted), así que hay que crearlos antes.
     */
    private function admin(Company $company): \App\Models\User
    {
        foreach (['integrations.view', 'integrations.update'] as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        return \App\Models\User::create([
            'company_id' => $company->id,
            'name' => 'Admin',
            'email' => 'admin-' . $company->id . '@example.test',
            'password' => bcrypt('secret'),
            'role' => 'admin',
            'active' => true,
        ]);
    }

    private function connect(Company $company, string $key, string $baseUrl = 'https://demo.test/software'): CompanyIntegration
    {
        return CompanyIntegration::create([
            'company_id' => $company->id,
            'key' => $key,
            'status' => 'connected',
            'base_url' => $baseUrl,
            'access_token' => 'itg_token',
            'enabled' => true,
        ]);
    }
}
