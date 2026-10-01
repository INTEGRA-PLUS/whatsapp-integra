<?php

namespace App\Console\Commands;

use App\Models\Instance;
use App\Services\MetaWhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Qué sabe Meta del gasto y del medio de pago de una empresa.
 *
 * Existe para responder con datos y no con suposiciones a una pregunta que se
 * repite: cuánto lleva gastado un cliente en su portafolio y si tiene la
 * tarjeta bien puesta.
 *
 * Lo primero sí lo sabemos —Meta lo devuelve en la analítica de conversaciones
 * y el CRM ya lo suma—. Lo segundo hay que averiguarlo campo a campo, porque
 * Graph **tumba la consulta entera si un solo campo no existe** para esa
 * versión o ese tipo de cuenta: por eso los candidatos se piden de uno en uno
 * en vez de todos juntos.
 *
 * Es de sólo lectura.
 */
class ConsumoDeMeta extends Command
{
    /** Campos del WABA que podrían hablar de facturación. Se prueban uno a uno. */
    private const CANDIDATOS = [
        'primary_funding_id',
        'account_review_status',
        'business_verification_status',
        'owner_business_info',
        'on_behalf_of_business_info',
        'health_status',
        'currency',
        'purchase_order_number',
        'is_enabled_for_insights',
    ];

    protected $signature = 'whatsapp:consumo
        {instancia : Id de la instancia}
        {--dias=30 : Cuántos días atrás mirar el gasto}';

    protected $description = 'Enseña el gasto de WhatsApp de una empresa y qué dice Meta de su facturación';

    public function __construct(private MetaWhatsAppService $meta)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $instance = Instance::find($this->argument('instancia'));

        if (! $instance || ! $instance->waba_id || ! $instance->access_token) {
            $this->error('Esa instancia no existe o no tiene WABA y token.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  <options=bold>'.$instance->name.'</> · WABA '.$instance->waba_id);

        $this->gasto($instance);
        $this->camposDeFacturacion($instance);

        return self::SUCCESS;
    }

    /** Lo que Meta dice que ha costado conversar, que es lo que el cliente paga. */
    private function gasto(Instance $instance): void
    {
        $dias = max(1, (int) $this->option('dias'));
        $desde = now()->subDays($dias)->timestamp;
        $hasta = now()->timestamp;

        $res = $this->meta->conversationAnalytics($instance->waba_id, $instance->access_token, [
            'start' => $desde,
            'end' => $hasta,
            'granularity' => 'DAILY',
            'dimensions' => ['CONVERSATION_CATEGORY'],
        ]);

        $this->newLine();
        $this->line('  <options=bold>Gasto de los últimos '.$dias.' días</>');

        if (! ($res['success'] ?? false)) {
            $this->line('    <fg=red>Meta no contestó:</> '.json_encode($res['error'] ?? null));

            return;
        }

        $puntos = $res['data']['conversation_analytics']['data'][0]['data_points'] ?? [];

        if ($puntos === []) {
            $this->line('    <fg=gray>Sin datos. Puede ser que las analíticas no estén activadas en esa cuenta.</>');

            return;
        }

        $conversaciones = 0;
        $coste = 0.0;
        $porCategoria = [];

        foreach ($puntos as $p) {
            $conversaciones += (int) ($p['conversation'] ?? 0);
            $coste += (float) ($p['cost'] ?? 0);
            $cat = $p['conversation_category'] ?? 'SIN CATEGORÍA';
            $porCategoria[$cat] = ($porCategoria[$cat] ?? 0) + (int) ($p['conversation'] ?? 0);
        }

        $this->line('    Conversaciones: <fg=cyan>'.number_format($conversaciones).'</>');
        $this->line('    Coste que reporta Meta: <fg=cyan>'.number_format($coste, 2).'</>');

        foreach ($porCategoria as $cat => $n) {
            $this->line('      <fg=gray>'.$cat.': '.number_format($n).'</>');
        }

        // El dato que de verdad importa comprobar: desde que Meta cobra por
        // mensaje y no por conversación, `cost` puede volver en cero aunque el
        // cliente esté pagando. Si pasa, este número no sirve para facturar.
        if ($coste == 0.0 && $conversaciones > 0) {
            $this->newLine();
            $this->line('    <fg=yellow>Ojo: hay conversaciones pero el coste viene en cero.</>');
            $this->line('    <fg=yellow>Es lo que pasa con las cuentas que ya cobran por mensaje.</>');
        }
    }

    /** Qué campos del WABA acepta Meta, probados de uno en uno. */
    private function camposDeFacturacion(Instance $instance): void
    {
        $this->newLine();
        $this->line('  <options=bold>Campos del WABA, uno a uno</>');

        foreach (self::CANDIDATOS as $campo) {
            $res = Http::withToken($instance->access_token)
                ->timeout(20)
                ->get('https://graph.facebook.com/v21.0/'.$instance->waba_id, ['fields' => $campo]);

            if ($res->successful()) {
                $valor = $res->json($campo);
                $this->line(sprintf(
                    '    <fg=green>%-30s</> %s',
                    $campo,
                    $valor === null ? '<fg=gray>(vacío)</>' : json_encode($valor, JSON_UNESCAPED_UNICODE),
                ));

                continue;
            }

            $this->line(sprintf(
                '    <fg=red>%-30s</> <fg=gray>%s</>',
                $campo,
                mb_substr((string) $res->json('error.message'), 0, 90),
            ));
        }

        $this->newLine();
    }
}
