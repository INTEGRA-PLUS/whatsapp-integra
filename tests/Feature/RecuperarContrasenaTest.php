<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\RestablecerContrasenaNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Recuperar la contraseña sin recordarla.
 *
 * El enlace "¿Olvidaste tu contraseña?" del login llevaba `href="#"`: quien no
 * la recordaba no tenía salida ninguna, porque el formulario de ajustes exige
 * la actual. El 8-sep-2026 eso acabó en un UPDATE a mano contra la base de
 * datos de producción para poder entrar.
 *
 * Lo que se protege aquí son las dos decisiones que es fácil deshacer sin
 * darse cuenta: que la respuesta no revele si un correo tiene cuenta, y que a
 * las cuentas desactivadas no se les manden enlaces.
 */
class RecuperarContrasenaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pantalla_de_recuperar_se_abre_sin_estar_logueado(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    /** El correo sale, y con nuestra notificación en español. */
    public function test_manda_el_enlace_a_una_cuenta_activa(): void
    {
        Notification::fake();
        $user = $this->usuario();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, RestablecerContrasenaNotification::class);
    }

    /**
     * A una cuenta desactivada no.
     *
     * Sería darle una contraseña nueva para toparse igualmente con la puerta
     * cerrada, y de paso confirmaría a un desconocido que ese correo existe.
     */
    public function test_a_una_cuenta_desactivada_no_le_manda_nada(): void
    {
        Notification::fake();
        $user = $this->usuario(['active' => false]);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    /**
     * Y la respuesta es la misma exista el correo o no: si cambiara, esta
     * pantalla serviría para saber quién tiene cuenta en la plataforma.
     */
    public function test_un_correo_desconocido_recibe_la_misma_respuesta(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nadie@ejemplo.test'])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
    }

    /**
     * Si el SMTP no funciona, se dice en pantalla.
     *
     * Es el caso real de producción (mailer a 127.0.0.1, sin nada escuchando):
     * sin el catch del controlador, el usuario recibe la pantalla blanca de 500
     * —exactamente el fallo mudo que esta función viene a arreglar.
     */
    public function test_si_el_correo_no_se_puede_enviar_lo_dice_en_pantalla(): void
    {
        $user = $this->usuario();

        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('Connection refused'));

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasErrors('email');
    }

    /** El enlace del correo cambia la contraseña. */
    public function test_el_enlace_permite_poner_una_contrasena_nueva(): void
    {
        $user = $this->usuario();
        $token = Password::broker()->createToken($user);

        $this->get("/reset-password/{$token}?email=".urlencode($user->email))->assertOk();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nuevaclave123',
            'password_confirmation' => 'nuevaclave123',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        $this->assertTrue(Hash::check('nuevaclave123', $user->fresh()->password));
    }

    /** Un token inventado no cambia nada. */
    public function test_un_token_invalido_no_cambia_la_contrasena(): void
    {
        $user = $this->usuario();

        $this->post('/reset-password', [
            'token' => 'inventado',
            'email' => $user->email,
            'password' => 'nuevaclave123',
            'password_confirmation' => 'nuevaclave123',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('secreto123', $user->fresh()->password));
    }

    /** Y el mismo enlace no sirve dos veces. */
    public function test_el_enlace_solo_sirve_una_vez(): void
    {
        $user = $this->usuario();
        $token = Password::broker()->createToken($user);

        $datos = [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nuevaclave123',
            'password_confirmation' => 'nuevaclave123',
        ];

        $this->post('/reset-password', $datos)->assertRedirect(route('login'));

        $this->post('/reset-password', $datos + ['password' => 'otraclave456'])
            ->assertSessionHasErrors('email');
    }

    private function usuario(array $campos = []): User
    {
        $company = Company::create(['name' => 'Empresa', 'slug' => 'empresa', 'active' => true]);

        return User::create(array_merge([
            'company_id' => $company->id,
            'name' => 'Usuario',
            'email' => 'usuario@ejemplo.test',
            'password' => bcrypt('secreto123'),
            'role' => 'admin',
            'active' => true,
        ], $campos));
    }
}
