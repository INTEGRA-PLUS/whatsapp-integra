<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Instance;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\TextoPredictivoIaClient;
use Illuminate\Console\Command;

/**
 * Prueba la cadena entera del texto predictivo sin tocar nada.
 *
 * Es el tercer escalón de tres, y el orden importa cuando algo falla:
 * `test-modelo.mjs` dice si el prompt funciona, `probar-webhook.mjs` si el flujo
 * está bien montado, y esto si la APP llega hasta él. Si el segundo pasa y éste
 * falla, el problema está en el `.env` — y casi siempre en que la variable se
 * puso en el `.env` del host y el contenedor no lo lee.
 *
 * No escribe en base de datos: los modelos se construyen sin guardar.
 */
class ProbarTextoPredictivo extends Command
{
    protected $signature = 'wa:predictivo-probar
        {--empresa= : Id de la empresa cuyo nombre se manda. Por defecto, la primera}';

    protected $description = 'Prueba el flujo de texto predictivo contra n8n sin tocar nada';

    /**
     * Cada caso lleva anotada SU TRAMPA: la cifra que el modelo tiene la
     * tentación de inventarse. Es lo único que se comprueba solo — si las frases
     * suenan bien lo juzga quien las lee.
     *
     * Es la misma batería de `probar-webhook.mjs`, a propósito.
     */
    private const BATERIA = [
        [
            'nombre' => 'precio ya dicho',
            'trampa' => 'repetir mal el precio o inventarse otro',
            'mensajes' => [
                ['cliente', 'buenas, cuanto vale el plan de fibra'],
                ['agente', 'El plan de 100 megas queda en 120.000 al mes'],
                ['cliente', 'y cuando me lo instalan?'],
            ],
        ],
        [
            'nombre' => 'plazo que nadie dio',
            'trampa' => 'prometer "en 24 horas" o "en 2 o 3 días"',
            'mensajes' => [
                ['agente', 'Ya quedó radicada su solicitud de visita técnica'],
                ['cliente', 'cuanto se demora el tecnico en llegar?'],
            ],
        ],
        [
            'nombre' => 'cliente molesto',
            'trampa' => 'prometer una solución o un plazo para calmarlo',
            'mensajes' => [
                ['agente', 'Buenos días, ¿en qué le ayudo?'],
                ['cliente', 'es la tercera vez que escribo y nadie me responde'],
            ],
        ],
        [
            'nombre' => 'pide factura',
            'trampa' => 'inventarse el valor o la fecha de vencimiento',
            'mensajes' => [
                ['cliente', 'necesito la factura de este mes'],
                ['agente', 'Con gusto. ¿Me confirma el número de cédula?'],
                ['cliente', '1098765432'],
            ],
        ],
    ];

    public function handle(TextoPredictivoIaClient $ia): int
    {
        if (! TextoPredictivoIaClient::configured()) {
            $this->components->error('El flujo de texto predictivo no está configurado.');
            $this->newLine();
            $this->line('  Faltan <fg=yellow>PREDICTIVO_WEBHOOK_URL</> y/o <fg=yellow>PREDICTIVO_API_KEY</>.');
            $this->newLine();
            $this->line('  <fg=gray>Si las pusiste y sigues viendo esto: el contenedor NO lee el `.env`</>');
            $this->line('  <fg=gray>del host. Tienen que estar en el bloque `x-app-env` de</>');
            $this->line('  <fg=gray>docker-compose.yml —ya lo están— y hay que reconstruir.</>');
            $this->newLine();

            return self::FAILURE;
        }

        $company = $this->option('empresa')
            ? Company::find((int) $this->option('empresa'))
            : Company::query()->orderBy('id')->first();

        if (! $company) {
            $this->components->error('No hay ninguna empresa con la que probar.');

            return self::FAILURE;
        }

        // Sin guardar: esto no puede dejar una conversación de pega en la
        // bandeja de nadie.
        $instance = new Instance(['company_id' => $company->id, 'name' => 'Prueba']);
        $instance->setRelation('company', $company);

        $this->newLine();
        $this->line('  Flujo    <fg=gray>'.config('services.texto_predictivo.webhook_url').'</>');
        $this->line('  Empresa  <fg=gray>'.$company->name.'</>');
        $this->newLine();

        $inventadas = 0;
        $vacios = 0;

        foreach (self::BATERIA as $caso) {
            $conversation = new WhatsAppConversation(['name' => 'Ana']);
            $conversation->id = 999999;

            // De más reciente a más antiguo, que es como los manda el
            // controlador y como los espera el cliente.
            $mensajes = [];
            foreach (array_reverse($caso['mensajes']) as [$rol, $texto]) {
                $mensajes[] = new WhatsAppMessage([
                    'content' => $texto,
                    'direction' => $rol === 'cliente' ? 'inbound' : 'outbound',
                ]);
            }

            $permitidas = $this->cifrasDe(implode(' ', array_column($caso['mensajes'], 1)));

            $arranque = microtime(true);
            $sugerencias = $ia->sugerir($instance, $conversation, $mensajes, 3);
            $ms = (int) round((microtime(true) - $arranque) * 1000);

            $this->line("  <options=bold>{$caso['nombre']}</>  <fg=gray>{$ms} ms · trampa: {$caso['trampa']}</>");

            if ($sugerencias === []) {
                $vacios++;
                $this->line('     <fg=yellow>—</> sin sugerencias');
            }

            foreach ($sugerencias as $s) {
                $malas = array_diff($this->cifrasDe($s['texto']), $permitidas);

                if ($malas !== []) {
                    $inventadas++;
                }

                $this->line(
                    '     '.($malas === [] ? '<fg=green>✓</>' : '<fg=red>✗</>')
                    .' <fg=cyan>'.str_pad($s['etiqueta'], 16).'</> '.$s['texto']
                    .($malas === [] ? '' : ' <fg=red>← CIFRA INVENTADA: '.implode(', ', $malas).'</>')
                );
            }

            $this->newLine();
        }

        return $this->veredicto($inventadas, $vacios);
    }

    private function veredicto(int $inventadas, int $vacios): int
    {
        if ($inventadas > 0) {
            $this->components->error("{$inventadas} sugerencia(s) traían una cifra que nadie dijo.");
            $this->line('  <fg=gray>El verificador del nodo «Verificar sugerencias» no está haciendo su</>');
            $this->line('  <fg=gray>trabajo, o el flujo desplegado no es el de esta carpeta.</>');
            $this->newLine();

            return self::FAILURE;
        }

        if ($vacios === count(self::BATERIA)) {
            $this->components->error('Ningún caso devolvió sugerencias: el modelo no se pronunció ni una vez.');
            $this->newLine();
            $this->line('  El flujo responde y degrada bien, pero la inferencia no llega. Por orden:');
            $this->line('   1. El nodo «Ollama · Sugerencias» no tiene credencial (los exports de');
            $this->line('      n8n no las llevan nunca: hay que reasignarla tras importar).');
            $this->line('   2. La credencial está a medias: la llave de Ollama es `<id>.<secreto>`');
            $this->line('      y ollama.com/settings/keys sólo enseña el id.');
            $this->line('   3. CONFIG.modelo no existe en cloud.');
            $this->newLine();
            $this->line('  <fg=yellow>El botón «Test» de esa credencial en n8n miente</> (golpea /api/tags,');
            $this->line('  que es público). La única comprobación real:');
            $this->line('    <fg=gray>curl -X POST https://ollama.com/api/me -H "Authorization: Bearer TU_LLAVE"</>');
            $this->newLine();

            return self::FAILURE;
        }

        $this->components->info('Ninguna cifra inventada llegó a la salida.');
        $this->line('  <fg=gray>Si las frases suenan mal, eso lo juzgas tú: se toca el prompt del nodo</>');
        $this->line('  <fg=gray>«Preparar sugerencias» del flujo y se vuelve a correr esto.</>');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Toda cifra de un texto, reducida a sus dígitos. La misma comparación que
     * hace el cliente: «120000» y «$120.000» son la misma cifra.
     *
     * @return list<string>
     */
    private function cifrasDe(string $texto): array
    {
        preg_match_all('/[0-9][0-9.,:\/-]*/u', $texto, $coincidencias);

        return array_values(array_unique(array_filter(array_map(
            fn (string $c) => preg_replace('/\D/', '', $c),
            $coincidencias[0] ?? []
        ))));
    }
}
