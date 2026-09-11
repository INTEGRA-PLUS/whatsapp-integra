<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Services\InstagramLoginService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Renueva los tokens de Instagram antes de que caduquen.
 *
 * Los de WhatsApp que usamos son de usuario del sistema y no expiran nunca. Los
 * de Instagram duran **60 días**. Sin esta tarea, cada cuenta conectada se cae
 * sola a los dos meses y sólo nos enteramos cuando el cliente llama diciendo que
 * dejaron de entrarle mensajes.
 *
 * Se renueva con margen y no el último día a propósito: la API sólo renueva
 * tokens **vivos**, así que si el día del vencimiento hay una caída de Meta o
 * del scheduler, el token muere y hay que rehacer el inicio de sesión con el
 * cliente delante.
 */
class RenovarTokensDeInstagram extends Command
{
    protected $signature = 'instagram:renovar-tokens {--dias=10 : Renovar las que caduquen dentro de menos de estos días}';

    protected $description = 'Renueva por otros 60 días los tokens de Instagram que están por caducar';

    public function handle(InstagramLoginService $instagram): int
    {
        $dias = max(1, (int) $this->option('dias'));

        $lineas = Instance::canal(Instance::CANAL_INSTAGRAM)
            ->whereNotNull('access_token')
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<', now()->addDays($dias))
            ->get();

        if ($lineas->isEmpty()) {
            $this->info("Ninguna cuenta de Instagram caduca en los próximos {$dias} días.");

            return self::SUCCESS;
        }

        $renovadas = 0;
        $fallidas = 0;

        foreach ($lineas as $linea) {
            $nuevo = $instagram->renovar($linea->access_token);

            if (! $nuevo) {
                $fallidas++;

                // Ruidoso a propósito: una renovación fallida es una cuenta que
                // va a dejar de funcionar en días, y en silencio nadie lo mira.
                Log::channel('instagram')->error('❌ No se pudo renovar el token de Instagram', [
                    'instancia' => $linea->id,
                    'empresa' => $linea->company_id,
                    'usuario' => $linea->usuarioDeInstagram(),
                    'caduca' => $linea->token_expires_at?->toDateString(),
                ]);

                $this->error("  {$linea->name}: no se pudo renovar (caduca el {$linea->token_expires_at?->toDateString()})");

                continue;
            }

            $linea->forceFill([
                'access_token' => $nuevo['access_token'],
                'token_expires_at' => now()->addSeconds($nuevo['expires_in']),
            ])->save();

            $renovadas++;
            $this->info("  {$linea->name}: renovado hasta el {$linea->token_expires_at->toDateString()}");
        }

        $this->line("Renovadas {$renovadas}, fallidas {$fallidas}.");

        // Si alguna falló se sale con error para que el scheduler lo note.
        return $fallidas > 0 ? self::FAILURE : self::SUCCESS;
    }
}
