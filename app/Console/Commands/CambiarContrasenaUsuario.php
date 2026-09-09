<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Cambia la contraseña de un usuario desde la consola.
 *
 * Es la red de seguridad del sistema. Un agente que olvida su contraseña la
 * recupera por su admin, y el admin de una empresa por el master desde el panel
 * maestro; pero **por encima del master no hay nadie**, y hasta que el correo
 * salga de verdad tampoco hay recuperación por email. Sin esto, la única salida
 * era `php artisan tinker` escribiendo un UPDATE a mano contra producción
 * (8-sep-2026), que es tan fácil de equivocar como suena.
 *
 * La contraseña se teclea oculta y nunca viaja como argumento: un argumento
 * queda en el historial de la shell y en la lista de procesos del servidor.
 * Para las altas de soporte está `--generar`, que la inventa y la muestra una
 * sola vez.
 */
class CambiarContrasenaUsuario extends Command
{
    protected $signature = 'usuarios:contrasena
        {email : Correo del usuario al que cambiarle la contraseña}
        {--generar : Genera una contraseña aleatoria y la muestra, en vez de pedirla}';

    protected $description = 'Cambia la contraseña de un usuario preguntándola por consola (no queda en el historial)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No existe ningún usuario con el correo {$email}.");
            $this->line('Los usuarios con rol master son:');

            foreach (User::where('role', 'master')->get() as $master) {
                $this->line("  · {$master->email} (#{$master->id})");
            }

            return self::FAILURE;
        }

        // A quién se le va a cambiar, antes de cambiarla: en una plataforma
        // multiempresa hay correos parecidos, y un cambio de contraseña al
        // usuario equivocado deja fuera a alguien que no lo esperaba.
        $empresa = $user->company?->name ?? 'sin empresa';
        $this->line("Usuario: {$user->name} <{$user->email}> — rol {$user->role}, empresa {$empresa} (#{$user->company_id})");

        if (! $user->active) {
            $this->warn('Aviso: la cuenta está desactivada. Cambiarle la contraseña no la reactiva.');
        }

        $contrasena = $this->option('generar')
            ? Str::password(16, symbols: false)
            : $this->pedirContrasena();

        if ($contrasena === null) {
            $this->error('No se cambió nada.');

            return self::FAILURE;
        }

        // El cast 'hashed' del modelo se encarga del bcrypt.
        $user->update(['password' => $contrasena]);

        $this->info("✅ Contraseña actualizada para {$user->email}.");

        if ($this->option('generar')) {
            $this->newLine();
            $this->line('  Contraseña generada: '.$contrasena);
            $this->line('  Se muestra una sola vez; cópiala ahora y pide que la cambien al entrar.');
        }

        return self::SUCCESS;
    }

    /**
     * La pide dos veces y oculta. Se valida la longitud mínima del panel para
     * que no se pueda dejar por consola una contraseña que la propia pantalla
     * de ajustes rechazaría.
     */
    private function pedirContrasena(): ?string
    {
        $primera = $this->secret('Contraseña nueva (no se ve al escribir)');

        if ($primera === null || strlen($primera) < 8) {
            $this->error('La contraseña debe tener al menos 8 caracteres.');

            return null;
        }

        if ($primera !== $this->secret('Repítela')) {
            $this->error('Las dos contraseñas no coinciden.');

            return null;
        }

        return $primera;
    }
}
