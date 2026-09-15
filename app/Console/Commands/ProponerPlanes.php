<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\CompanyExtension;
use App\Support\ContadorDeIa;
use App\Support\PlanDeLaEmpresa;
use Illuminate\Console\Command;

/**
 * Qué plan le tocaría a cada empresa según lo que usa de verdad.
 *
 * Las 55 empresas están hoy en `inteligente` + `cortesia`, que son los valores
 * por defecto de la tabla. Es decir: a nadie se le asignó un plan nunca, el
 * candado de extensiones no le aplica a nadie y `seFactura()` devuelve `false`
 * para todos. El sistema de planes está montado y apagado, que era la
 * transición que se quería — pero para encenderlo hay que decidir 55 veces.
 *
 * Esto **no decide nada**: mira qué extensiones tiene encendidas cada empresa y
 * dice cuál es el plan más barato que se las cubre. La decisión sigue siendo
 * comercial, pero se toma mirando datos en vez de a ojo.
 *
 * Por qué mira lo ENCENDIDO y no lo instalado: una extensión instalada y
 * apagada es alguien que la probó y no la quiso. Cobrarle el plan que la
 * incluye por haberla mirado una vez es la clase de factura que cuesta un
 * cliente.
 *
 * `--aplicar` lo guarda. Sin eso sólo enseña la tabla, que es como debería
 * usarse la primera vez.
 */
class ProponerPlanes extends Command
{
    protected $signature = 'planes:proponer
        {--aplicar : Guarda el plan propuesto en cada empresa}
        {--company= : Sólo esta empresa (id)}';

    protected $description = 'Propone a cada empresa el plan más barato que cubre las extensiones que tiene encendidas';

    public function handle(): int
    {
        $catalogo = config('planes.disponibles', []);

        $empresas = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')
            ->get();

        $filas = [];
        $cuenta = array_fill_keys(array_keys($catalogo), 0);

        foreach ($empresas as $company) {
            $plan = PlanDeLaEmpresa::de($company);

            // Las nuestras se quedan como están, con todo desbloqueado. No es
            // una excepción cosmética: `Meta App Review` necesita las cinco
            // extensiones encendidas para grabar los vídeos de las revisiones
            // de Meta, y bajarla a Esencial porque hoy no las tiene activas le
            // apagaría el permiso que estamos pidiendo. `PRUEBAS` y
            // `Master Admin` existen justo para probar lo que aún no vendemos.
            if ($company->interna) {
                $filas[] = [
                    $company->name,
                    number_format($plan->contactosReales()),
                    '—',
                    '—',
                    '<fg=gray>interna · sin tocar</>',
                    '—',
                ];

                continue;
            }

            $encendidas = CompanyExtension::where('company_id', $company->id)
                ->where('enabled', true)
                ->pluck('slug')
                ->all();

            $propuesto = $this->planMasBaratoQueCubre($encendidas, $catalogo);
            $cuenta[$propuesto]++;

            $precio = $company->contactos_contratados
                ? config("planes.precios.{$this->tramoDe($company)}.{$propuesto}")
                : null;

            // El consumo de IA del mes decide si el plan propuesto se queda
            // corto: una empresa sin extensiones de IA encendidas pero con
            // eventos apuntados es una que la usó y la apagó, o una a la que se
            // le encendió por otro lado. Conviene mirarla a mano.
            $uso = ContadorDeIa::delMes($company->id);
            $marca = ($propuesto !== 'inteligente' && $uso->conversaciones > 0) ? ' ⚠' : '';

            $filas[] = [
                $company->name,
                number_format($plan->contactosReales()),
                $company->contactos_contratados ? number_format($company->contactos_contratados) : '—',
                count($encendidas) ?: '—',
                $catalogo[$propuesto]['nombre'].$marca,
                $precio ? '$'.$precio : 'a cotizar',
            ];

            if ($this->option('aplicar')) {
                $company->update(['plan' => $propuesto]);
            }
        }

        $this->table(
            ['Empresa', 'Contactos', 'Tramo', 'Ext.', 'Plan propuesto', 'USD/mes'],
            $filas
        );

        $this->newLine();
        foreach ($cuenta as $slug => $n) {
            $this->line(sprintf('  %-16s %d empresas', $catalogo[$slug]['nombre'], $n));
        }

        $this->newLine();
        $this->line($this->option('aplicar')
            ? 'Guardado. El cobro NO se ha tocado: siguen todas en cortesía.'
            : 'Nada guardado. Con --aplicar se escribe el plan (el cobro no se toca).');

        $this->newLine();
        $this->warn('⚠ = no le tocaría plan con IA pero consumió IA este mes. Revisar a mano.');

        return self::SUCCESS;
    }

    /**
     * El plan más barato del catálogo que incluye todas estas extensiones.
     *
     * Recorre el catálogo en su orden, que va de menos a más. Si ninguno las
     * cubre —no debería pasar, `inteligente` lleva `'*'`— cae en el último,
     * que es el que más incluye.
     */
    private function planMasBaratoQueCubre(array $encendidas, array $catalogo): string
    {
        foreach ($catalogo as $slug => $datos) {
            $permitidas = $datos['extensiones'] ?? [];

            if ($permitidas === '*') {
                return $slug;
            }

            if (empty(array_diff($encendidas, (array) $permitidas))) {
                return $slug;
            }
        }

        return array_key_last($catalogo);
    }

    private function tramoDe(Company $company): int
    {
        $contratados = (int) $company->contactos_contratados;

        foreach (array_keys(config('planes.precios', [])) as $tope) {
            if ($contratados <= $tope) {
                return (int) $tope;
            }
        }

        return (int) array_key_last(config('planes.precios', [0]));
    }
}
