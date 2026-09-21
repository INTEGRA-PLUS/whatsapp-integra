<?php

namespace App\Http\Controllers;

use App\Extensions\Extension;
use App\Extensions\ExtensionRegistry;
use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Tag;
use App\Models\User;
use App\Services\Integra;
use App\Services\IntegraCapabilities;
use App\Support\IntegrationProvider;
use App\Support\PlanDeLaEmpresa;
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
    /**
     * Qué capacidad del token de Integra necesita cada extensión, cuando
     * necesita una concreta. La clave es la de `IntegraCapabilities`.
     */
    private const SCOPES_POR_EXTENSION = [
        'internet_diagnostic' => 'diagnostico',
    ];

    public function __construct(private ExtensionRegistry $registry) {}

    private function companyId(): int
    {
        return (int) auth()->user()->company_id;
    }

    private function plan(): PlanDeLaEmpresa
    {
        return PlanDeLaEmpresa::de(Company::findOrFail($this->companyId()));
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
                $this->present($extension, $instalada, conScope: true),
                ['schema' => $this->schemaFor($extension)]
            ),
        ]);
    }

    /** POST /api/extensions/{slug}/install */
    public function install(string $slug)
    {
        $extension = $this->requireExtension($slug);

        // El plan se comprueba ANTES que el permiso: un admin con todos los
        // permisos de su empresa sigue sin poder instalar lo que no contrató.
        // 402 y no 403 porque no es un problema de permisos sino de plan, y el
        // frontend tiene que poder distinguirlos para decir «mejora tu plan» en
        // vez de «no tienes acceso».
        if (! $this->plan()->permiteExtension($slug)) {
            abort(402, 'Esta extensión no está incluida en tu plan.');
        }

        // Y la dependencia dura, si la tiene. 409 y no 402 ni 403: no es el
        // plan ni son los permisos, es que falta conectar algo — y se arregla
        // en otra pantalla, así que el mensaje tiene que decir cuál. Instalarla
        // sin la conexión dejaría una extensión encendida que no puede hacer
        // nada y que parece rota.
        if ($proveedor = $extension->requiresIntegration()) {
            if (! $this->integracionConectada($proveedor)) {
                $nombre = IntegrationProvider::find($proveedor)['name'] ?? $proveedor;

                abort(409, 'Esta extensión funciona contra tu cuenta de '.$nombre
                    .', y todavía no está conectada. Conéctala en Integraciones y vuelve aquí.');
            }
        }

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

        $limpios = $extension->sanitizeSettings(is_array($entrada) ? $entrada : [], $this->companyId());

        // Un ajuste puede necesitar un plan superior aunque la extensión no: el
        // semáforo entra en Automatización pero «afinar con IA» es del plan
        // Inteligente. Sin esto, el candado de la extensión se saltaría por el
        // formulario, que es por donde nadie mira.
        $plan = $this->plan();

        foreach (array_keys($limpios) as $campo) {
            if (! $plan->permiteAjuste($slug, $campo)) {
                $limpios[$campo] = $extension->defaultSettings()[$campo] ?? null;
            }
        }

        $instalada->update(['settings' => $limpios]);

        return response()->json($this->present($extension, $instalada->fresh()));
    }

    /** Si el proveedor del que depende una extensión está conectado hoy. */
    private function integracionConectada(string $proveedor): bool
    {
        return match ($proveedor) {
            IntegrationProvider::INTEGRA => Integra::connected($this->companyId()),
            default => false,
        };
    }

    /**
     * La dependencia de la extensión, resuelta para la pantalla.
     *
     * Incluye si el token puede además lo que la extensión necesita. Son dos
     * fallos distintos y el usuario tiene que poder distinguirlos: «no has
     * conectado Integra» se arregla conectando, y «tu token no tiene
     * contratos.diagnostico» se arregla pidiéndole ese scope a quien administra
     * el ERP. Con un solo aviso genérico, el segundo caso manda a reconectar
     * una y otra vez una integración que ya está bien.
     *
     * @return array<string, mixed>|null
     */
    private function dependencia(Extension $extension, bool $conScope = false): ?array
    {
        $proveedor = $extension->requiresIntegration();

        if (! $proveedor) {
            return null;
        }

        $conectada = $this->integracionConectada($proveedor);
        $scope = self::SCOPES_POR_EXTENSION[$extension->slug()] ?? null;
        $puede = null;

        // El sondeo de scopes sólo en la ficha, nunca en el catálogo: son
        // cinco llamadas a Integra la primera vez, y el catálogo es la
        // pantalla que se abre para mirar, no para decidir.
        if ($conScope && $conectada && $scope) {
            $capacidades = IntegraCapabilities::for($this->companyId());
            $puede = ($capacidades['checked'] ?? false)
                ? (bool) ($capacidades['can'][$scope] ?? false)
                : null;
        }

        return [
            'id' => $proveedor,
            'nombre' => IntegrationProvider::find($proveedor)['name'] ?? $proveedor,
            'conectada' => $conectada,
            // null = no se pudo comprobar; no se pinta nada antes que mentir.
            'puede' => $puede,
            'scope' => $scope ? (IntegraCapabilities::SCOPES[$scope] ?? $scope) : null,
            'etiqueta' => $scope ? (IntegraCapabilities::LABELS_EXTRA[$scope] ?? $scope) : null,
        ];
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
    private function present(Extension $extension, ?CompanyExtension $instalada, bool $conScope = false): array
    {
        $plan = $this->plan();

        return array_merge($extension->manifest(), [
            // Lo que no entra en el plan se SIGUE enseñando en el catálogo, con
            // «Mejora tu plan» en vez de «Instalar»: enseñar lo que no tienes
            // vende mejor que esconderlo, y esconderlo hace que nadie sepa que
            // existe.
            'en_plan' => $plan->permiteExtension($extension->slug()),
            'ajustes_bloqueados' => $plan->ajustesDeIaBloqueados($extension->slug()),

            // Lo que le falta a esta empresa para poder usarla, si es que le
            // falta algo. null en casi todas.
            'dependencia' => $this->dependencia($extension, $conScope),

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
