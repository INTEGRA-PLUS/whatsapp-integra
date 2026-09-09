<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Recuperar la contraseña sin recordarla.
 *
 * El enlace "¿Olvidaste tu contraseña?" llevaba `href="#"` desde siempre: una
 * puerta pintada en la pared. Quien no recordaba su contraseña no tenía salida
 * ninguna —el formulario de ajustes exige la actual, y con razón— y acabábamos
 * escribiendo un UPDATE a mano contra producción (8-sep-2026).
 *
 * Dos decisiones que conviene no deshacer:
 *
 * - **Sólo cuentas activas.** El filtro `active` viaja en las credenciales del
 *   broker, así que a un usuario desactivado no se le manda enlace: darle uno
 *   sería dejarle poner contraseña nueva para después toparse con la puerta
 *   cerrada, y de paso confirmaría a un desconocido que ese correo existe.
 * - **Se responde siempre lo mismo**, exista el correo o no. Si la respuesta
 *   cambiara, esta pantalla serviría para averiguar qué correos tienen cuenta
 *   en la plataforma.
 */
class ContrasenaOlvidadaController extends Controller
{
    /** La pantalla donde se pide el correo. */
    public function solicitar()
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /** Manda el enlace. */
    public function enviarEnlace(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        try {
            $resultado = Password::sendResetLink([
                'email' => $request->email,
                'active' => true,
            ]);
        } catch (\Throwable $e) {
            // El correo del proyecto puede no estar configurado (el mailer
            // apuntaba a 127.0.0.1 y el remitente era el placeholder de
            // Laravel). Sin este catch, un SMTP caído devuelve al usuario la
            // pantalla blanca de 500 sin decirle nada, que es justo el fallo
            // que esta función viene a arreglar.
            Log::error('No se pudo enviar el correo de restablecimiento', [
                'email' => $request->email,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'email' => 'No se pudo enviar el correo. El envío de correo del servidor no está funcionando; '
                    .'avisa al administrador para que revise la configuración de SMTP.',
            ]);
        }

        // Con el correo desconocido el broker devuelve INVALID_USER, y con uno
        // que ya pidió enlace hace menos de un minuto, THROTTLED. Ninguno de los
        // dos se cuenta: la respuesta es la misma siempre.
        if ($resultado === Password::RESET_LINK_SENT) {
            Log::info('Enlace de restablecimiento enviado', ['email' => $request->email]);
        }

        return back()->with('status', 'Si ese correo tiene una cuenta activa, le acaba de llegar un enlace para poner una contraseña nueva. Revisa también la carpeta de spam.');
    }

    /** La pantalla del enlace del correo. */
    public function formulario(Request $request, string $token)
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    /** Y el cambio en sí. */
    public function restablecer(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $resultado = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token') + ['active' => true],
            function ($user) use ($request) {
                // El cast 'hashed' del modelo hace el bcrypt.
                $user->forceFill([
                    'password' => $request->password,
                    // Invalida el "mantener sesión iniciada" de cualquier
                    // dispositivo donde la cuenta siguiera abierta: si se
                    // restablece porque alguien más entró, dejar vivas esas
                    // cookies no arreglaría nada.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($resultado !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'El enlace ya no sirve: caducó o se usó antes. Pide uno nuevo.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Contraseña cambiada. Ya puedes entrar con la nueva.');
    }
}
