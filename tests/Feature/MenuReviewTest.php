<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Services\IntegraCapabilities;
use App\Support\MenuReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La revisión del menú: decirle al admin qué va a fallar antes de que lo
 * descubra un cliente.
 *
 * El módulo deja guardar casi cualquier cosa y casi todo falla en silencio: una
 * opción de Integra con un token sin el scope correcto se guarda igual, el
 * menú se enciende, y cada cliente que la toca acaba derivado a un asesor. El
 * error queda en los logs, que es donde el admin no mira.
 */
class MenuReviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Que el token conteste no significa que pueda: 401 y 403 son las únicas
     * respuestas que significan "no puedes". Un 404 —el contrato de prueba no
     * existe— o un 422 confirman que el permiso está y la ruta responde.
     */
    public function test_solo_el_401_y_el_403_cuentan_como_permiso_ausente(): void
    {
        $company = $this->companyWithIntegra();

        Http::fake([
            '*/contactos/buscar*' => Http::response(['success' => false, 'message' => 'q inválido'], 422),
            '*/facturas/pendientes*' => Http::response(['success' => true, 'data' => []], 200),
            '*/radicados/catalogos*' => Http::response(['success' => false, 'message' => 'sin permiso'], 403),
            '*/resumen*' => Http::response(['success' => false, 'message' => 'Contrato no encontrado.'], 404),
        ]);

        $can = IntegraCapabilities::for($company->id)['can'];

        $this->assertTrue($can['contactos'], 'Un 422 es validación, no falta de permiso.');
        $this->assertTrue($can['facturas']);
        $this->assertFalse($can['radicados'], 'El 403 es el único "no puedes" junto al 401.');
        $this->assertTrue($can['contratos'], 'Un 404 de contrato inexistente confirma que el scope está.');
    }

    /**
     * Un 401 en todo no es "le faltan permisos": es un token revocado. Pedir
     * scopes para un token que ya no vale no arregla nada.
     */
    public function test_un_token_muerto_se_distingue_de_uno_al_que_le_faltan_permisos(): void
    {
        $company = $this->companyWithIntegra();

        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Token inválido o revocado.'], 401)]);

        $capabilities = IntegraCapabilities::for($company->id);

        $this->assertStringContainsString('revocado o caducado', $capabilities['error']);
    }

    /** Sin Integra conectado no hay nada que sondear, y tampoco es un error. */
    public function test_sin_integra_conectado_no_se_sondea_nada(): void
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true]);

        $capabilities = IntegraCapabilities::for($company->id);

        $this->assertFalse($capabilities['connected']);
        $this->assertFalse($capabilities['checked']);
        $this->assertNull($capabilities['error']);
    }

    /**
     * El caso que de verdad pasa en producción: el token lee facturas pero no
     * contratos, así que el submenú entero de "mi servicio" no responde.
     */
    public function test_una_opcion_sin_el_scope_necesario_sale_como_bloqueo(): void
    {
        $company = $this->bareCompany();
        $menu = $this->menuWith($company, [
            ['title' => 'Mis facturas', 'action_type' => 'consultar_factura'],
            ['title' => 'Estado de mi servicio', 'action_type' => 'estado_servicio'],
        ]);

        $issues = MenuReview::build(
            WhatsAppMenu::where('company_id', $company->id)->with('options')->get(),
            $this->capabilities(['contactos' => true, 'facturas' => true, 'contratos' => false, 'radicados' => false])
        );

        $blockers = array_filter($issues, fn ($i) => $i['level'] === MenuReview::BLOCKER);

        $this->assertCount(1, $blockers);
        $issue = array_values($blockers)[0];
        $this->assertSame('Estado de mi servicio', $issue['option']);
        $this->assertStringContainsString('Leer el contrato', $issue['says']);
        // Y le dice qué scope pedir, que es lo que tiene que reenviarle a quien
        // administra su Integra.
        $this->assertStringContainsString('contratos.leer', $issue['fix']);

        $this->assertSame($menu->id, $issue['menu_id']);
    }

    public function test_reportar_falla_sin_tipo_de_falla_no_puede_crear_el_radicado(): void
    {
        $company = $this->bareCompany();
        $this->menuWith($company, [
            ['title' => 'Reportar falla', 'action_type' => 'reportar_falla'],
        ]);

        $issues = MenuReview::build(
            WhatsAppMenu::where('company_id', $company->id)->with('options')->get(),
            $this->capabilities(['contactos' => true, 'facturas' => true, 'contratos' => true, 'radicados' => true])
        );

        $this->assertCount(1, $issues);
        $this->assertSame(MenuReview::BLOCKER, $issues[0]['level']);
        $this->assertStringContainsString('tipo de falla', $issues[0]['says']);
    }

    /** Un submenú apagado no llega a nadie, y es invisible desde el menú padre. */
    public function test_un_submenu_apagado_sale_como_bloqueo(): void
    {
        $company = $this->bareCompany();

        $submenu = WhatsAppMenu::create([
            'company_id' => $company->id,
            'name' => 'Mi plan',
            'body_text' => '¿Qué revisamos?',
            'is_root' => false,
            'active' => false,
        ]);
        $submenu->options()->create([
            'position' => 0, 'title' => 'Plan', 'action_type' => 'reply_text', 'reply_text' => 'Tu plan',
        ]);

        $this->menuWith($company, [
            ['title' => 'Mi plan y contrato', 'action_type' => 'submenu', 'target_menu_id' => $submenu->id],
        ]);

        $issues = MenuReview::build(
            WhatsAppMenu::where('company_id', $company->id)->with('options')->get(),
            $this->capabilities()
        );

        $blockers = array_values(array_filter($issues, fn ($i) => $i['level'] === MenuReview::BLOCKER));

        $this->assertCount(1, $blockers);
        $this->assertStringContainsString('está apagado', $blockers[0]['says']);
    }

    /** Un menú sano no inventa avisos: una lista con ruido deja de leerse. */
    public function test_un_menu_sano_no_reporta_nada(): void
    {
        $company = $this->bareCompany();
        $this->menuWith($company, [
            ['title' => 'Mis facturas', 'action_type' => 'consultar_factura'],
            ['title' => 'Horarios', 'action_type' => 'reply_text', 'reply_text' => 'De 8 a 6.'],
            ['title' => 'Asesor', 'action_type' => 'handoff'],
        ]);

        $issues = MenuReview::build(
            WhatsAppMenu::where('company_id', $company->id)->with('options')->get(),
            $this->capabilities()
        );

        $this->assertSame([], $issues);
    }

    /**
     * Sin haber podido comprobar los permisos no se acusa a nadie: decir "te
     * falta un scope" cuando no se sabe es peor que callar, porque manda al
     * admin a pedir un permiso que quizá ya tiene.
     */
    public function test_sin_comprobar_los_permisos_no_se_inventan_bloqueos(): void
    {
        $company = $this->bareCompany();
        $this->menuWith($company, [
            ['title' => 'Estado de mi servicio', 'action_type' => 'estado_servicio'],
        ]);

        $issues = MenuReview::build(
            WhatsAppMenu::where('company_id', $company->id)->with('options')->get(),
            ['connected' => false, 'checked' => false, 'can' => [], 'error' => null]
        );

        // El único aviso es que Integra no está conectado, con su botón. Ni una
        // sola acusación de "te falta el permiso X".
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('no está conectado', $issues[0]['says']);
        $this->assertSame('integrations', $issues[0]['action']['kind']);
        $this->assertStringNotContainsString('permiso', $issues[0]['fix']);
    }

    /**
     * Un token revocado tumba todos los permisos a la vez. Sacar un aviso por
     * opción y por permiso llenaba la pantalla de ocho líneas que decían lo
     * mismo y escondían las que sí eran distintas: es una causa y una sola cosa
     * que hacer.
     */
    public function test_un_token_revocado_produce_un_solo_aviso_y_no_uno_por_opcion(): void
    {
        $company = $this->bareCompany();
        $this->menuWith($company, [
            ['title' => 'Mis facturas', 'action_type' => 'consultar_factura'],
            ['title' => 'Pagar en línea', 'action_type' => 'pagar_en_linea'],
            ['title' => 'Estado de mi servicio', 'action_type' => 'estado_servicio'],
        ]);

        $issues = MenuReview::build(
            WhatsAppMenu::where('company_id', $company->id)->with('options')->get(),
            [
                'connected' => true,
                'checked' => true,
                'can' => ['contactos' => false, 'facturas' => false, 'contratos' => false, 'radicados' => false],
                'error' => 'Integra rechaza el token: está revocado o caducado.',
            ]
        );

        $blockers = array_values(array_filter($issues, fn ($i) => $i['level'] === MenuReview::BLOCKER));

        $this->assertCount(1, $blockers, 'Tres opciones sin permisos, pero una sola causa que resolver.');
        $this->assertStringContainsString('revocado', $blockers[0]['says']);
        $this->assertSame('integrations', $blockers[0]['action']['kind']);
    }

    /**
     * Con el token vivo pero corto, los permisos que le faltan a una opción van
     * en UNA línea. Con una por permiso, "Reportar falla" salía tres veces
     * seguidas diciendo casi lo mismo, y lo repetido se deja de leer.
     */
    public function test_los_permisos_que_faltan_a_una_opcion_van_en_un_solo_aviso(): void
    {
        $company = $this->bareCompany();
        $this->menuWith($company, [
            ['title' => 'Reportar falla', 'action_type' => 'reportar_falla', 'config' => ['radicado_servicio' => 3]],
        ]);

        $issues = MenuReview::build(
            WhatsAppMenu::where('company_id', $company->id)->with('options')->get(),
            $this->capabilities(['contactos' => false, 'radicados' => false])
        );

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('«Buscar al cliente» ni «Crear radicados»', $issues[0]['says']);
        $this->assertStringContainsString('contactos.leer y radicados.leer + radicados.crear', $issues[0]['fix']);
    }

    /** @param array<string, bool> $can */
    private function capabilities(array $can = []): array
    {
        return [
            'connected' => true,
            'checked' => true,
            'can' => $can + ['contactos' => true, 'facturas' => true, 'contratos' => true, 'radicados' => true],
            'error' => null,
        ];
    }

    /**
     * Una empresa sin el menú de fábrica.
     *
     * CompanyObserver le siembra uno a toda empresa nueva, y aquí se revisa un
     * menú concreto: el de fábrica traería sus propios avisos —un "Reportar
     * falla" todavía sin tipo de falla— que no son lo que la prueba mira.
     */
    private function bareCompany(): Company
    {
        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true]);

        WhatsAppMenu::where('company_id', $company->id)->get()->each->delete();

        return $company;
    }

    /** @param list<array<string, mixed>> $options */
    private function menuWith(Company $company, array $options): WhatsAppMenu
    {
        $menu = WhatsAppMenu::create([
            'company_id' => $company->id,
            'name' => 'Menú principal',
            'body_text' => '¿En qué te ayudo?',
            'is_root' => true,
            'match_types' => ['welcome'],
            'active' => true,
        ]);

        foreach ($options as $position => $option) {
            $menu->options()->create($option + [
                'position' => $position,
                'config' => $option['action_type'] === 'handoff'
                    ? ['assign_strategy' => WhatsAppMenuOption::ASSIGN_LEAST_BUSY]
                    : null,
            ]);
        }

        return $menu->load('options');
    }

    private function companyWithIntegra(): Company
    {
        Cache::flush();

        $company = Company::create(['name' => 'Cmnet', 'slug' => 'cmnet', 'active' => true]);

        CompanyIntegration::create([
            'company_id' => $company->id,
            'key' => CompanyIntegration::KEY_INVOICE_PAYMENTS,
            'status' => 'connected',
            'base_url' => 'https://demo.integra.test',
            'access_token' => 'itg_pruebas',
        ]);

        return $company;
    }
}
