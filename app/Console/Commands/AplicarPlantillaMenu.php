<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\DefaultWhatsAppMenu;
use Illuminate\Console\Command;

/**
 * Trae las opciones nuevas a un menú que ya está en uso.
 *
 * La migración que pone al día la plantilla se salta a propósito a quien ya
 * encendió su menú, y con razón: sobrescribir por debajo el trabajo de alguien
 * es peor que dejarlo desactualizado. Pero cuando el propio dueño lo pide, hace
 * falta una vía — y que sea explícita, con nombre y apellidos, en vez de
 * relajar la regla para todos.
 */
class AplicarPlantillaMenu extends Command
{
    protected $signature = 'menus:aplicar-plantilla
                            {empresa* : Id de la empresa (uno o varios)}
                            {--force : No preguntar}';

    protected $description = 'Pone las opciones de fábrica en el menú de una empresa, sin borrar el menú';

    public function handle(): int
    {
        foreach ($this->argument('empresa') as $id) {
            $company = Company::find($id);

            if (! $company) {
                $this->error("Empresa {$id}: no existe.");

                continue;
            }

            $this->line("Empresa {$id} — {$company->name}");

            if (! $this->option('force') && ! $this->confirm('  ¿Reescribir las opciones de su menú?', true)) {
                continue;
            }

            $result = DefaultWhatsAppMenu::applyTemplateInPlace($company);

            if (! $result) {
                $this->warn('  No tiene menú principal: nada que hacer.');

                continue;
            }

            $this->info(sprintf(
                '  Listo: %d opciones (%d nuevas, %d actualizadas, %d borradas). El menú conserva su id.',
                $result['menu']->options->count(),
                $result['creadas'],
                $result['actualizadas'],
                $result['borradas']
            ));
        }

        return self::SUCCESS;
    }
}
