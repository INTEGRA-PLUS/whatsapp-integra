<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ¿La marca «viene de Integra» se corresponde con lo que dicen los datos?
 *
 * La marca decide **a quién no se le factura el CRM**, así que equivocarla
 * cuesta dinero en las dos direcciones: de más, si se le cobra el CRM a alguien
 * que ya lo paga dentro de su ERP; de menos, si se le perdona a un cliente
 * directo. Y no lanza ningún error en ninguno de los dos casos.
 *
 * La sembró una migración con la única señal técnica que había —tener la
 * integración del ERP configurada— y el resto se puso a mano. Esto existe para
 * que esa decisión deje de ser una opinión de un martes y se pueda volver a
 * comprobar cuando alguien pregunte.
 *
 * ## Las señales, de más fuerte a más débil
 *
 * 1. **Mensajes con `incoming_company_nit`.** El ERP mandó mensajes por la API
 *    v1 usando esta empresa. Es lo más fuerte que hay: son datos que viajaron,
 *    no una casilla que alguien marcó.
 * 2. **Contactos con `external_id`.** El ERP sincronizó su base de abonados.
 * 3. **Integración del ERP configurada** (`invoice_payments`, `contacts_sync`).
 *    Alguien la preparó; no prueba que se llegara a usar.
 *
 * Ojo con lo que **no** vale: las acciones de menú que apuntan a Integra las
 * sembró para todas las empresas la migración
 * `2026_08_27_140000_seed_default_whatsapp_menu_for_companies`. Parecen una
 * señal y no lo son — casi se usaron como tal el 16-sep-2026.
 */
class AuditarIntegra extends Command
{
    protected $signature = 'empresas:auditar-integra {--dudosas : Sólo las que no cuadran}';

    protected $description = 'Coteja la marca «viene de Integra» contra lo que dicen los datos';

    public function handle(): int
    {
        $porNit = DB::table('whatsapp_messages')
            ->join('whatsapp_conversations', 'whatsapp_conversations.id', '=', 'whatsapp_messages.conversation_id')
            ->join('instances', 'instances.id', '=', 'whatsapp_conversations.instance_id')
            ->whereNotNull('whatsapp_messages.incoming_company_nit')
            ->select('instances.company_id', DB::raw('count(*) as c'))
            ->groupBy('instances.company_id')
            ->pluck('c', 'company_id');

        $contactos = DB::table('contacts')
            ->whereNotNull('external_id')
            ->select('company_id', DB::raw('count(*) as c'))
            ->groupBy('company_id')
            ->pluck('c', 'company_id');

        $conErp = DB::table('company_integrations')
            ->whereIn('key', ['invoice_payments', 'contacts_sync'])
            ->distinct()
            ->pluck('company_id')
            ->flip();

        $filas = [];
        $sinEvidencia = [];
        $sinMarcar = [];

        foreach (Company::where('interna', false)->orderBy('name')->get() as $empresa) {
            $mensajes = (int) ($porNit[$empresa->id] ?? 0);
            $sincronizados = (int) ($contactos[$empresa->id] ?? 0);
            $configurada = $conErp->has($empresa->id);

            $prueba = $mensajes > 0 || $sincronizados > 0;
            $indicio = $prueba || $configurada;
            $marcada = (bool) $empresa->viene_de_integra;

            $veredicto = match (true) {
                $marcada && $prueba => '✓ confirmada',
                $marcada && $configurada => '~ sólo configurada',
                $marcada => '⚠ sin evidencia',
                $indicio => '⚠ ¿falta marcarla?',
                default => '· no es de Integra',
            };

            if ($marcada && ! $indicio) {
                $sinEvidencia[] = $empresa->name;
            }

            if (! $marcada && $indicio) {
                $sinMarcar[] = $empresa->name;
            }

            if ($this->option('dudosas') && ! str_starts_with($veredicto, '⚠')) {
                continue;
            }

            $filas[] = [
                mb_substr($empresa->name, 0, 30),
                $marcada ? 'sí' : 'no',
                $mensajes ?: '·',
                $sincronizados ?: '·',
                $configurada ? 'sí' : '·',
                $veredicto,
            ];
        }

        $this->table(
            ['Empresa', 'Marcada', 'Mensajes ERP', 'Contactos', 'Integración', 'Veredicto'],
            $filas
        );

        if ($sinEvidencia !== []) {
            $this->newLine();
            $this->warn('Marcadas sin ninguna señal ('.count($sinEvidencia).'):');
            $this->line('  '.implode(', ', $sinEvidencia));
            $this->line('  No se les factura el CRM. Si no vienen de Integra, es dinero que no se cobra.');
        }

        if ($sinMarcar !== []) {
            $this->newLine();
            // Éste es el caro de los dos: se le está pasando una factura a
            // alguien que ya paga el CRM dentro de su ERP.
            $this->error('Con señal de Integra y SIN marcar ('.count($sinMarcar).'):');
            $this->line('  '.implode(', ', $sinMarcar));
            $this->line('  A éstas se les va a cobrar el CRM que quizá ya pagan. Revisar antes de emitir.');
        }

        if ($sinEvidencia === [] && $sinMarcar === []) {
            $this->newLine();
            $this->info('Todas las marcas cuadran con los datos.');
        }

        return self::SUCCESS;
    }
}
