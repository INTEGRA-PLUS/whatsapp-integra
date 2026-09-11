<?php

namespace Tests\Feature;

use App\Models\InstagramDeletionRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Desautorización y eliminación de datos.
 *
 * Son obligación legal y, muy en concreto, **son dos de las URL que el revisor
 * del App Review visita**: si no contestan, la solicitud se rechaza antes de
 * mirar el screencast. Y como disparan borrados y desconexiones, aceptar una
 * sin firmar dejaría que cualquiera desconecte la cuenta de un cliente con un
 * POST.
 */
class InstagramPrivacidadTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = '28822685693981719';

    private const SECRETO = 'secreto-de-la-app-de-instagram';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.instagram.app_id' => self::APP_ID,
            'services.meta.webhook_app_secrets' => '865904982715022:otro-secreto,'.self::APP_ID.':'.self::SECRETO,
        ]);
    }

    public function test_la_desautorizacion_firmada_se_acepta(): void
    {
        $this->post('/instagram/desautorizar', [
            'signed_request' => $this->firmar(['user_id' => '1089304012345678']),
        ])->assertOk();
    }

    public function test_la_desautorizacion_con_firma_ajena_se_rechaza(): void
    {
        $this->post('/instagram/desautorizar', [
            'signed_request' => $this->firmar(['user_id' => '1089304012345678'], 'clave-de-un-impostor'),
        ])->assertStatus(400);
    }

    public function test_el_borrado_devuelve_codigo_y_una_url_que_funciona(): void
    {
        $respuesta = $this->post('/instagram/eliminar-datos', [
            'signed_request' => $this->firmar(['user_id' => '1089304012345678']),
        ])->assertOk();

        $codigo = $respuesta->json('confirmation_code');
        $url = $respuesta->json('url');

        $this->assertNotEmpty($codigo);
        $this->assertStringContainsString($codigo, $url);

        $this->assertDatabaseHas('instagram_deletion_requests', [
            'instagram_user_id' => '1089304012345678',
            'confirmation_code' => $codigo,
            'status' => InstagramDeletionRequest::RECIBIDA,
        ]);

        // La URL tiene que servir de verdad: un código que no se puede consultar
        // después es motivo de rechazo.
        $this->get($url)->assertOk()->assertSee($codigo);
    }

    /**
     * Meta reintenta estos avisos. Si cada reintento abriera una petición nueva,
     * la tabla se llenaría de filas que dicen lo mismo y el usuario tendría
     * varios códigos vivos para un solo borrado.
     */
    public function test_reintentar_no_abre_una_peticion_nueva(): void
    {
        $firmada = $this->firmar(['user_id' => '1089304012345678']);

        $primero = $this->post('/instagram/eliminar-datos', ['signed_request' => $firmada])->json('confirmation_code');
        $segundo = $this->post('/instagram/eliminar-datos', ['signed_request' => $firmada])->json('confirmation_code');

        $this->assertSame($primero, $segundo);
        $this->assertSame(1, InstagramDeletionRequest::count());
    }

    public function test_el_borrado_con_firma_ajena_no_deja_rastro(): void
    {
        $this->post('/instagram/eliminar-datos', [
            'signed_request' => $this->firmar(['user_id' => '1089304012345678'], 'clave-de-un-impostor'),
        ])->assertStatus(400);

        $this->assertSame(0, InstagramDeletionRequest::count());
    }

    /**
     * El truco clásico: declarar un algoritmo que no es el de Meta para que la
     * comprobación se salte.
     */
    public function test_una_firma_con_otro_algoritmo_se_rechaza(): void
    {
        $carga = $this->base64url(json_encode(['algorithm' => 'none', 'user_id' => '1089304012345678']));

        $this->post('/instagram/eliminar-datos', [
            'signed_request' => $this->base64url('lo-que-sea').'.'.$carga,
        ])->assertStatus(400);
    }

    public function test_un_signed_request_mal_formado_se_rechaza(): void
    {
        $this->post('/instagram/eliminar-datos', ['signed_request' => 'esto-no-tiene-punto'])
            ->assertStatus(400);
    }

    /**
     * Sin el App ID de Instagram no se sabe con qué clave validar, y adivinar
     * sería aceptar avisos que quizá no son de Meta.
     */
    public function test_sin_app_id_configurado_no_se_acepta_nada(): void
    {
        config(['services.meta.instagram.app_id' => null]);

        $this->post('/instagram/desautorizar', [
            'signed_request' => $this->firmar(['user_id' => '1089304012345678']),
        ])->assertStatus(400);
    }

    /**
     * El revisor entra con un navegador, no con un POST firmado. Un 405 ahí es
     * un rechazo.
     */
    public function test_las_dos_urls_se_pueden_visitar_con_el_navegador(): void
    {
        $this->get('/instagram/desautorizar')->assertOk();
        $this->get('/instagram/eliminar-datos')->assertOk();
        $this->get('/instagram/callback')->assertOk();
    }

    public function test_un_codigo_que_no_existe_da_404(): void
    {
        $this->get('/instagram/eliminar-datos/inventado')->assertNotFound();
    }

    private function firmar(array $datos, ?string $secreto = null): string
    {
        $carga = $this->base64url(json_encode($datos + ['algorithm' => 'HMAC-SHA256']));
        $firma = hash_hmac('sha256', $carga, $secreto ?? self::SECRETO, true);

        return $this->base64url($firma).'.'.$carga;
    }

    private function base64url(string $valor): string
    {
        return rtrim(strtr(base64_encode($valor), '+/', '-_'), '=');
    }
}
