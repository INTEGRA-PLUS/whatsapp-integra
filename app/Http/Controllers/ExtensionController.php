<?php

namespace App\Http\Controllers;

use App\Extensions\Extension;
use App\Extensions\ExtensionRegistry;
use App\Models\CompanyExtension;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * El marketplace: catálogo, instalación y ajustes de las extensiones.
 *
 * Un solo controlador para todas las extensiones —y dos pantallas para todas—
 * porque las pantallas se generan a partir del manifiesto. Una extensión nueva
 * no añade ni una ruta ni un componente: por eso el módulo existe.
 */
class ExtensionController extends Controller
{
    public function __construct(private ExtensionRegistry $registry) {}

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    /** GET /extensiones — el catálogo. */
    public function index()
    {
        $instaladas = $this->registry->installedFor($this->companyId());

        $extensiones = collect($this->registry->all())
            ->map(fn (Extension $extension) => $this->present($extension, $instaladas->get($extension->slug())))
            ->values();

        return Inertia::render('Extensions/Index', [
            'extensions' => $extensiones,
        ]);
    }

    /** GET /extensiones/{slug} — el detalle, con el formulario de ajustes. */
    public function show(string $slug)
    {
        $extension = $this->registry->find($slug);

        if (! $extension) {
            abort(404);
        }

        $instalada = CompanyExtension::where('company_id', $this->companyId())
            ->where('slug', $slug)
            ->first();

        return Inertia::render('Extensions/Show', [
            'extension' => array_merge(
                $this->present($extension, $instalada),
                ['schema' => $this->schemaFor($extension)]
            ),
        ]);
    }

    /** POST /api/extensions/{slug}/install */
    public function install(string $slug)
    {
        $extension = $this->requireExtension($slug);

        // firstOrCreate y no create: un doble clic en "Instalar" chocaría contra
        // el índice único (company_id, slug) y le devolvería un 500 a quien sólo
        // pulsó dos veces.
        $instalada = CompanyExtension::firstOrCreate(
            ['company_id' => $this->companyId(), 'slug' => $slug],
            [
                'enabled' => true,
                'settings' => $extension->defaultSettings(),
                'installed_by' => auth()->id(),
                'installed_at' => now(),
            ]
        );

        return response()->json($this->present($extension, $instalada));
    }

    /** DELETE /api/extensions/{slug} */
    public function uninstall(string $slug)
    {
        $extension = $this->requireExtension($slug);

        // Desinstalar borra los ajustes con la fila. Es lo que se espera de
        // "desinstalar" y es lo que hace que reinstalar empiece de cero; quien
        // sólo quiera parar la extensión un rato tiene el interruptor.
        CompanyExtension::where('company_id', $this->companyId())
            ->where('slug', $slug)
            ->delete();

        return response()->json($this->present($extension, null));
    }

    /** POST /api/extensions/{slug}/toggle */
    public function toggle(Request $request, string $slug)
    {
        $extension = $this->requireExtension($slug);

        $data = $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $instalada = $this->requireInstalled($slug);
        $instalada->update(['enabled' => $data['enabled']]);

        return response()->json($this->present($extension, $instalada));
    }

    /** PUT /api/extensions/{slug}/settings */
    public function updateSettings(Request $request, string $slug)
    {
        $extension = $this->requireExtension($slug);
        $instalada = $this->requireInstalled($slug);

        // El validate() de Laravel descarta lo que no esté en las reglas, y los
        // ajustes son distintos en cada extensión: la validación de verdad la
        // hace la propia extensión, que es la única que sabe qué campos tiene y
        // qué referencias hay que acotar a la empresa.
        $entrada = $request->input('settings');

        $instalada->update([
            'settings' => $extension->sanitizeSettings(is_array($entrada) ? $entrada : [], $this->companyId()),
        ]);

        return response()->json($this->present($extension, $instalada->fresh()));
    }

    private function requireExtension(string $slug): Extension
    {
        $extension = $this->registry->find($slug);

        if (! $extension) {
            abort(404, 'Esa extensión no existe.');
        }

        return $extension;
    }

    private function requireInstalled(string $slug): CompanyExtension
    {
        $instalada = CompanyExtension::where('company_id', $this->companyId())
            ->where('slug', $slug)
            ->first();

        if (! $instalada) {
            abort(404, 'Esa extensión no está instalada.');
        }

        return $instalada;
    }

    /** @return array<string, mixed> */
    private function present(Extension $extension, ?CompanyExtension $instalada): array
    {
        return array_merge($extension->manifest(), [
            'installed' => $instalada !== null,
            'enabled' => (bool) ($instalada?->enabled ?? false),
            'settings' => $instalada ? $instalada->settings() : $extension->defaultSettings(),
            'installed_at' => optional($instalada?->installed_at)->toIso8601String(),
            'installed_by' => $instalada?->installer?->name,
        ]);
    }

    /**
     * El esquema de ajustes con las opciones ya resueltas.
     *
     * Las etiquetas y los agentes se cargan una sola vez aunque los pidan varios
     * campos, y siempre acotados a la empresa de quien mira: es la lista que va
     * a viajar al navegador.
     *
     * @return list<array<string, mixed>>
     */
    private function schemaFor(Extension $extension): array
    {
        $companyId = $this->companyId();
        $fuentes = [];

        return collect($extension->settingsSchema())
            ->map(function (array $field) use (&$fuentes, $companyId) {
                if ($source = $field['source'] ?? null) {
                    $fuentes[$source] ??= $this->options($source, $companyId);
                    $field['options'] = $fuentes[$source];
                }

                // Un campo compuesto —el editor de reglas— necesita varias
                // listas a la vez (etiquetas Y agentes) y no una sola lista de
                // opciones. Se le entregan indexadas por fuente para que el
                // componente coja la que le toque en cada desplegable.
                foreach ((array) ($field['sources'] ?? []) as $fuente) {
                    $fuentes[$fuente] ??= $this->options($fuente, $companyId);
                    $field['optionsBySource'][$fuente] = $fuentes[$fuente];
                }

                return $field;
            })
            ->values()
            ->all();
    }

    /** @return list<array{value: string, label: string}> */
    private function options(string $source, int $companyId): array
    {
        return match ($source) {
            'tags' => Tag::where('company_id', $companyId)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Tag $t) => ['value' => (string) $t->id, 'label' => $t->name])
                ->all(),
            'agents' => User::where('company_id', $companyId)
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => (string) $u->id, 'label' => $u->name])
                ->all(),
            default => [],
        };
    }
}
