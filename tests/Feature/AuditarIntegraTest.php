<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * La auditoría de «viene de Integra».
 *
 * Esa marca decide a quién NO se le factura el CRM, así que equivocarla cuesta
 * dinero en las dos direcciones y no lanza ningún error en ninguna.
 *
 * Lo que se prueba aquí es sobre todo **qué cuenta como evidencia**, porque es
 * donde se falla: el 16-sep-2026 casi se usan como señal las acciones de menú
 * que apuntan a Integra, hasta descubrir que una migración se las siembra a
 * todas las empresas.
 */
class AuditarIntegraTest extends TestCase
{
    use RefreshDatabase;

    /** Marcada y con mensajes del ERP: cuadra y no se avisa de nada. */
    public function test_una_marcada_con_mensajes_del_erp_cuadra(): void
    {
        $this->empresa('Fibra con ERP', marcada: true, mensajesDelErp: 40);

        $this->artisan('empresas:auditar-integra')
            ->expectsOutputToContain('Todas las marcas cuadran con los datos.')
            ->assertSuccessful();
    }

    /**
     * Marcada sin una sola señal: se avisa, porque es dinero que no se cobra.
     *
     * No se corrige sola a propósito. Un ISP puede tener Integra contratado sin
     * haber conectado nunca nada en el CRM, así que desmarcarla por su cuenta
     * sería mandarle una factura por algo que ya paga.
     */
    public function test_una_marcada_sin_ninguna_senal_se_avisa(): void
    {
        $this->empresa('Fantasma SAS', marcada: true);

        $this->artisan('empresas:auditar-integra')
            ->expectsOutputToContain('Marcadas sin ninguna señal (1)')
            ->expectsOutputToContain('Fantasma SAS')
            ->assertSuccessful();
    }

    /**
     * Con señal y sin marcar: es el caro de los dos.
     *
     * A ésa se le va a emitir un cobro por el CRM que probablemente ya paga
     * dentro de su ERP.
     */
    public function test_con_senal_y_sin_marcar_se_avisa_mas_fuerte(): void
    {
        $this->empresa('Sin marcar SAS', marcada: false, mensajesDelErp: 5);

        $this->artisan('empresas:auditar-integra')
            ->expectsOutputToContain('Con señal de Integra y SIN marcar (1)')
            ->expectsOutputToContain('Sin marcar SAS')
            ->assertSuccessful();
    }

    /** La integración configurada cuenta, pero como indicio y no como prueba. */
    public function test_la_integracion_configurada_cuenta_como_indicio(): void
    {
        $empresa = $this->empresa('Configurada SAS', marcada: true);

        CompanyIntegration::create([
            'company_id' => $empresa->id,
            'key' => CompanyIntegration::KEY_CONTACTS_SYNC,
            'enabled' => true,
        ]);

        $this->artisan('empresas:auditar-integra')
            ->expectsOutputToContain('Todas las marcas cuadran con los datos.')
            ->assertSuccessful();
    }

    /** Las empresas internas no son clientes y no entran en la auditoría. */
    public function test_las_internas_no_se_auditan(): void
    {
        $this->empresa('Integra interna', marcada: false, interna: true);

        $this->artisan('empresas:auditar-integra')
            ->expectsOutputToContain('Todas las marcas cuadran con los datos.')
            ->assertSuccessful();
    }

    private function empresa(
        string $nombre,
        bool $marcada,
        int $mensajesDelErp = 0,
        bool $interna = false
    ): Company {
        $empresa = Company::create([
            'name' => $nombre,
            'slug' => Str::slug($nombre),
            'active' => true,
            'plan' => 'basico',
            'viene_de_integra' => $marcada,
            'interna' => $interna,
        ]);

        if ($mensajesDelErp === 0) {
            return $empresa;
        }

        $instancia = Instance::create([
            'company_id' => $empresa->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea',
            'phone_number_id' => (string) random_int(1000000, 9999999),
            'waba_id' => '1022301494026392',
            'type' => 'meta',
            'access_token' => 'token',
            'active' => true,
        ]);

        $conversacion = WhatsAppConversation::create([
            'instance_id' => $instancia->id,
            'wa_id' => '573007852081',
            'phone_number' => '573007852081',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        foreach (range(1, $mensajesDelErp) as $i) {
            WhatsAppMessage::create([
                'conversation_id' => $conversacion->id,
                'wamid' => 'wamid.'.Str::random(12),
                'type' => 'text',
                'content' => 'aviso del ERP',
                'direction' => 'outbound',
                'status' => 'sent',
                // La etiqueta que manda el ERP en el cuerpo de la API v1. No es
                // una credencial: es la huella de que Integra usó esta empresa.
                'incoming_company_nit' => '900123456',
                'sent_at' => now(),
            ]);
        }

        return $empresa;
    }
}
