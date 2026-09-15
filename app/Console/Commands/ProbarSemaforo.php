<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\SentimientoIaClient;
use App\Support\Sentimiento\Analizador;
use App\Support\Sentimiento\Lectura;
use App\Support\Sentimiento\Semaforo;
use Illuminate\Console\Command;

/**
 * Prueba el semáforo de emociones de punta a punta, sin WhatsApp y sin cola.
 *
 * Recorre el camino real —la matriz primero, el flujo de n8n después— con una
 * batería de frases cuya respuesta correcta conocemos, y enseña las dos capas
 * en paralelo. No escribe en la base ni le manda nada a ningún cliente.
 *
 * Lo que de verdad comprueba no es "¿funciona?" sino **"¿está calibrado?"**. Los
 * casos no son ejemplos bonitos: son los nueve sitios por donde esto se rompe.
 * Cuatro de ellos tienen que salir VERDE, y ése es el punto —un semáforo que
 * pinta de rojo a todo el que tiene un problema deja de mirarse en una semana—.
 *
 * Los dos desacuerdos entre capas se leen distinto:
 *
 * - **La IA corrige a la matriz**: es para lo que está. "Me dijeron que eran
 *   unos ladrones pero a mí me atendieron bien" es el caso de manual.
 * - **La IA contradice lo esperado**: ahí hay que mirar el prompt del nodo
 *   `Preparar análisis`, porque la regla de calibración se ha separado de la de
 *   PHP y el color va a parpadear en producción.
 */
class ProbarSemaforo extends Command
{
    protected $signature = 'wa:semaforo-probar
        {mensaje? : Una frase suelta. Sin esto corre la batería de calibración}
        {--sin-ia : Sólo la matriz, sin llamar al flujo de n8n}
        {--sensibilidad=medio : bajo|medio|alto}';

    protected $description = 'Prueba el semáforo de emociones contra el flujo de n8n sin tocar nada';

    /**
     * Los nueve sitios por donde esto se rompe. `esperado` es lo correcto, no lo
     * que sale hoy: si cambias uno para que pase, has movido la prueba en vez de
     * arreglar el problema.
     *
     * El cuarto campo marca el caso que la matriz NO puede resolver: un léxico
     * no distingue a quién se refiere un insulto. Con --sin-ia no se cuenta como
     * fallo —sería exigirle al diccionario lo que no puede—, pero con IA sí:
     * es justo el caso que justifica la segunda capa.
     *
     * @var list<array{0: string, 1: string, 2: string, 3?: bool}>
     */
    private const BATERIA = [
        ['Buenos días, no tengo internet desde anoche', Lectura::VERDE, 'Avería con calma: tener un problema NO es estar enfadado'],
        ['quiero saber el valor de mi factura de este mes', Lectura::VERDE, 'Consulta normal'],
        ['BUENOS DIAS SENORES POR FAVOR NECESITO AYUDA', Lectura::VERDE, 'Mayúsculas por costumbre, no por gritar'],
        ['no tengo ninguna queja, todo excelente', Lectura::VERDE, 'La negación no cruza la coma'],
        ['Es la tercera vez que escribo y nadie me responde', Lectura::AMARILLO, 'Esfuerzo, escrito con educación'],
        ['son unos ladrones, esto es una estafa', Lectura::ROJO, 'Enfado explícito'],
        ['voy a poner una tutela y una queja en la SIC', Lectura::ROJO, 'Ya decidió escalar fuera'],
        ['quiero cancelar el servicio ya mismo', Lectura::ROJO, 'Riesgo de fuga'],
        ['me dijeron que eran unos ladrones pero a mi me atendieron muy bien', Lectura::VERDE, 'Cita a un tercero: aquí la IA debe corregir a la matriz', true],
    ];

    public function handle(Analizador $analizador, Semaforo $semaforo, SentimientoIaClient $ia): int
    {
        $sensibilidad = (string) $this->option('sensibilidad');
        $conIa = ! $this->option('sin-ia');

        $this->newLine();
        $this->line('  <fg=gray>Sensibilidad</>  '.$sensibilidad);

        if ($conIa) {
            $url = (string) config('services.sentimiento.webhook_url');

            if (! SentimientoIaClient::configured()) {
                $this->newLine();
                $this->error('  El flujo de IA no está configurado.');
                $this->line('  Falta <fg=yellow>SENTIMIENTO_WEBHOOK_URL</> o <fg=yellow>SENTIMIENTO_API_KEY</> en el .env.');
                $this->line('  Recuerda que el contenedor NO lee el .env del host: tienen que estar');
                $this->line('  en el bloque x-app-env de docker-compose.yml y hay que reconstruir.');
                $this->newLine();
                $this->line('  Con <fg=cyan>--sin-ia</> puedes probar sólo la matriz.');

                return self::FAILURE;
            }

            $this->line('  <fg=gray>Flujo</>         '.$url);
            $this->line('  <fg=gray>Timeout</>       '.config('services.sentimiento.timeout', 45).'s');
        } else {
            $this->line('  <fg=gray>Flujo</>         <fg=yellow>omitido (--sin-ia)</>');
        }

        $casos = $this->argument('mensaje')
            ? [[(string) $this->argument('mensaje'), null, 'Frase suelta']]
            : self::BATERIA;

        $this->newLine();

        $fallos = 0;
        $discrepancias = 0;

        foreach ($casos as $caso) {
            [$texto, $esperado, $porque] = $caso;
            $requiereIa = $caso[3] ?? false;
            $puntuacion = $analizador->mensaje($texto);
            $matriz = $semaforo->nivel($puntuacion->score, $sensibilidad);

            $veredicto = null;
            $ms = null;

            if ($conIa) {
                $arranque = microtime(true);
                $veredicto = $this->preguntar($ia, $texto, $matriz, $puntuacion->score);
                $ms = (int) ((microtime(true) - $arranque) * 1000);
            }

            // Manda la IA cuando se pronuncia, igual que en producción.
            $final = $veredicto?->nivel ?? $matriz;
            $ok = $esperado === null
                || $final === $esperado
                || ($requiereIa && ! $conIa);

            if (! $ok) {
                $fallos++;
            }

            if ($veredicto && $veredicto->nivel !== $matriz) {
                $discrepancias++;
            }

            $this->line(sprintf(
                '  %s %s  <fg=gray>%s</>',
                $ok ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $this->color($final),
                mb_strimwidth($texto, 0, 58, '…')
            ));

            $this->line(sprintf(
                '      <fg=gray>matriz</> %s <fg=gray>(%+.2f)</>   <fg=gray>ia</> %s%s',
                $this->color($matriz),
                $puntuacion->score,
                $conIa ? ($veredicto ? $this->color($veredicto->nivel).sprintf(' <fg=gray>(conf. %.2f)</>', $veredicto->confianza) : '<fg=yellow>descartada</>') : '<fg=gray>—</>',
                $ms !== null ? sprintf('   <fg=gray>%d ms</>', $ms) : ''
            ));

            if ($veredicto?->motivo) {
                $this->line('      <fg=gray>«'.mb_strimwidth($veredicto->motivo, 0, 70, '…').'»</>');
            }

            if (! $ok) {
                $this->line('      <fg=red>esperaba '.$esperado.'</> <fg=gray>· '.$porque.'</>');
            } elseif ($requiereIa && ! $conIa) {
                $this->line('      <fg=gray>sin comprobar: este caso sólo lo resuelve la IA</>');
            }

            $this->newLine();
        }

        return $this->resumen($fallos, $discrepancias, count($casos), $conIa);
    }

    /**
     * Llama al flujo por el camino real: el mismo cliente que usa el job.
     *
     * Con modelos sin guardar —no hace falta tocar la base para probar un
     * prompt—. El cliente sólo lee el id de empresa, el nombre y los mensajes.
     */
    private function preguntar(SentimientoIaClient $ia, string $texto, string $matriz, float $score): ?Lectura
    {
        $instancia = new Instance(['company_id' => 0, 'name' => 'prueba']);
        $conversacion = new WhatsAppConversation(['name' => 'Cliente de prueba']);
        $conversacion->id = 0;

        $mensaje = new WhatsAppMessage(['content' => $texto, 'direction' => 'inbound']);

        return $ia->analizar(
            $instancia,
            $conversacion,
            [$mensaje],
            new Lectura($matriz, $score, 'Según el diccionario de palabras')
        );
    }

    private function color(string $nivel): string
    {
        return match ($nivel) {
            Lectura::ROJO => '<fg=red>● rojo    </>',
            Lectura::AMARILLO => '<fg=yellow>● amarillo</>',
            default => '<fg=green>● verde   </>',
        };
    }

    private function resumen(int $fallos, int $discrepancias, int $total, bool $conIa): int
    {
        if ($fallos === 0) {
            $this->info("  {$total}/{$total} casos correctos.");
        } else {
            $this->error("  {$fallos} de {$total} casos fuera de lo esperado.");
            $this->line('  <fg=gray>Si falla un caso que esperaba VERDE, el semáforo está marcando');
            $this->line('  clientes que sólo tienen un problema, y eso hace que nadie lo mire.</>');
        }

        if ($conIa && $discrepancias > 0) {
            $this->newLine();
            $this->line("  <fg=gray>La IA discrepó de la matriz en {$discrepancias} caso(s).</>");
            $this->line('  <fg=gray>Corregirla es para lo que está; contradecir lo esperado no. Si pasa</>');
            $this->line('  <fg=gray>lo segundo, revisa el prompt del nodo «Preparar análisis»: la regla</>');
            $this->line('  <fg=gray>de calibración se ha separado de la de PHP y el color va a parpadear.</>');
        }

        $this->newLine();

        return $fallos === 0 ? self::SUCCESS : self::FAILURE;
    }
}
