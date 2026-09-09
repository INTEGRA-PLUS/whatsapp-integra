<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * El correo con el enlace para poner una contraseña nueva.
 *
 * Existe para no mandar el correo por defecto de Laravel, que llega en inglés y
 * firmado como "Laravel". El proyecto se escribe en español y el correo es lo
 * primero que ve un cliente cuando se ha quedado fuera: no es el sitio para que
 * asome el andamiaje.
 *
 * **No se encola a propósito.** Va por el canal `mail` de forma síncrona para
 * que un fallo de envío (SMTP mal configurado, credenciales caducadas) lo vea
 * quien pulsó el botón, en la misma pantalla. Encolada, el usuario leería
 * "te hemos enviado un correo" mientras el job muere en la cola y nadie se
 * enterara — que es exactamente la clase de fallo silencioso que este proyecto
 * ya ha pagado con los webhooks.
 */
class RestablecerContrasenaNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        // Los minutos de caducidad son los del broker (config/auth.php), no un
        // número escrito aquí: si allí se cambian, el correo no puede seguir
        // prometiendo una hora.
        $minutos = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Restablece tu contraseña de Integra CRM')
            ->greeting("Hola, {$notifiable->name}")
            ->line('Alguien pidió restablecer la contraseña de tu cuenta de Integra CRM. Si fuiste tú, usa el botón de abajo.')
            ->action('Poner una contraseña nueva', $url)
            ->line("El enlace caduca en {$minutos} minutos y sirve una sola vez.")
            ->line('Si no lo pediste, puedes ignorar este correo: tu contraseña sigue siendo la misma.')
            ->salutation('— Integra Colombia');
    }
}
