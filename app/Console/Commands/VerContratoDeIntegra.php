<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Integra;
use Illuminate\Console\Command;

/**
 * Enseña, tal cual, lo que Integra responde de un contrato.
 *
 * Existe por una pregunta que se repite cada vez que alguien quiere pintar un
 * dato nuevo del ERP en la ficha: **¿ese campo existe?** La documentación de
 * Integra dice qué endpoints hay, pero no qué rellena de verdad cada instalación
 * —y no es la misma en todas—, así que la única respuesta honesta es mirar el
 * JSON de la empresa concreta.
 *
 * Lo que hay detrás de cada opción, y por qué son tres y no una:
 *
 *  - `/contratos/{nro}/estado` es el del panel interno: **es el que trae la
 *    infraestructura** —IP, MAC, serial de la ONU, MikroTik— y por tanto el
 *    primer sitio donde mirar si el tipo de conexión (PPPoE, DHCP, IP fija)
 *    está expuesto en alguna parte.
 *  - `/contactos/buscar` es el que ya alimenta la ficha del chat, y de él salen
 *    hoy `tecnologia` y `red.ip`. Sirve para ver si el campo que se busca viaja
 *    gratis en lo que ya se está descargando.
 *  - `/contratos/{nro}/diagnostico` es el de la extensión de diagnóstico. Sus
 *    19 códigos de veredicto están en el Swagger; esto enseña el resto de la
 *    respuesta, que no está documentada campo a campo.
 *
 * No escribe nada: son tres GET.
 */
class VerContratoDeIntegra extends Command
{
    protected $signature = 'integra:contrato
        {nro : Número de contrato (contracts.nro), no el id de la URL del software}
        {--empresa= : Id de la empresa. Por defecto, la única que tenga Integra conectado}
        {--buscar= : Además, la ficha del cliente por cédula/NIT o teléfono}
        {--diagnostico : Además, el diagnóstico de red (tarda hasta 20 s y gasta cupo)}';

    protected $description = 'Enseña el JSON crudo que Integra responde de un contrato';

    public function handle(): int
    {
        $empresa = $this->empresa();

        if (! $empresa) {
            return self::FAILURE;
        }

        $client = Integra::for($empresa->id);

        if (! $client) {
            $this->components->error("La empresa «{$empresa->name}» no tiene Integra conectado.");

            return self::FAILURE;
        }

        $nro = (string) $this->argument('nro');

        $this->components->info("Empresa: {$empresa->name} (id {$empresa->id}) · contrato #{$nro}");

        $this->volcar(
            'GET /contratos/'.$nro.'/estado  — el del panel interno: IP, MAC, ONU, MikroTik',
            fn () => $client->contractStatus($nro)
        );

        if ($criterio = $this->option('buscar')) {
            $this->volcar(
                'GET /contactos/buscar?q='.$criterio.'  — el que alimenta la ficha del chat',
                fn () => $client->searchContacts((string) $criterio, 3)
            );
        }

        if ($this->option('diagnostico')) {
            $this->volcar(
                'GET /contratos/'.$nro.'/diagnostico  — puede tardar hasta 20 s',
                fn () => $client->contractDiagnostic($nro)
            );
        }

        return self::SUCCESS;
    }

    /**
     * La empresa sobre la que preguntar.
     *
     * Sin `--empresa`, sólo se resuelve sola cuando hay una candidata: elegir
     * por el llamador entre varias sería consultar el ERP equivocado y enseñar
     * un JSON que no es el que se estaba mirando.
     */
    private function empresa(): ?Company
    {
        if ($id = $this->option('empresa')) {
            $empresa = Company::find($id);

            if (! $empresa) {
                $this->components->error("No hay ninguna empresa con id {$id}.");
            }

            return $empresa;
        }

        $conectadas = Company::all()->filter(fn (Company $c) => Integra::connected($c->id))->values();

        if ($conectadas->count() === 1) {
            return $conectadas->first();
        }

        if ($conectadas->isEmpty()) {
            $this->components->error('Ninguna empresa tiene Integra conectado.');

            return null;
        }

        $this->components->error('Hay varias empresas con Integra conectado; elige una con --empresa=');
        $this->table(
            ['id', 'empresa'],
            $conectadas->map(fn (Company $c) => [$c->id, $c->name])->all()
        );

        return null;
    }

    /**
     * Imprime la respuesta sin tocarla.
     *
     * Sin filtrar ni reordenar a propósito: lo que se busca aquí es un campo que
     * no sabemos cómo se llama, así que cualquier resumen se cargaría justo el
     * dato que se está buscando. `JSON_UNESCAPED_UNICODE` porque si no, un
     * «Fibra óptica» sale como `Fibra óptica` y no se lee.
     */
    private function volcar(string $titulo, callable $consulta): void
    {
        $this->newLine();
        $this->line("<fg=cyan>── {$titulo}</>");

        try {
            $datos = $consulta();
        } catch (\RuntimeException $e) {
            // El código de la excepción es el HTTP, y el 403 es el interesante:
            // significa que la ruta existe pero al token le falta ese scope.
            $this->components->warn(
                'No se pudo leer ('.($e->getCode() ?: 'sin código').'): '.$e->getMessage()
            );

            return;
        }

        if ($datos === null) {
            $this->components->warn('Integra no conoce ese contrato (404).');

            return;
        }

        $this->line(json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
