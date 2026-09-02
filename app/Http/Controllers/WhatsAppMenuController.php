<?php

namespace App\Http\Controllers;

use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Services\Integra;
use App\Services\IntegraCapabilities;
use App\Services\IntegraClient;
use App\Support\DefaultAiMenusIntegration;
use App\Support\MenuReview;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WhatsAppMenuController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        if ($user->isMaster() && !session('impersonated_by')) {
            return redirect()->route('master.index');
        }

        $menus = WhatsAppMenu::where('company_id', $user->company_id)
            ->with(['instance:id,name', 'options.assignee:id,name', 'options.targetMenu:id,name'])
            ->orderByDesc('is_root')
            ->orderByDesc('created_at')
            ->get()
            // El formato es lo primero que pregunta quien configura ("¿esto sale
            // como botones o como lista?"), y se deduce del número de opciones.
            ->each(fn (WhatsAppMenu $menu) => $menu->setAttribute('format', $menu->format()));

        $instances = Instance::where('company_id', $user->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $agents = User::where('company_id', $user->company_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('WhatsAppMenus/Index', [
            'menus' => $menus,
            'instances' => $instances,
            'agents' => $agents,
            'limits' => [
                'max_buttons' => WhatsAppMenu::MAX_BUTTONS,
                'max_rows' => WhatsAppMenu::MAX_ROWS,
                'max_button_title' => WhatsAppMenu::MAX_BUTTON_TITLE,
                'max_row_title' => WhatsAppMenu::MAX_ROW_TITLE,
                'max_row_description' => WhatsAppMenu::MAX_ROW_DESCRIPTION,
                'max_body' => WhatsAppMenu::MAX_BODY,
            ],
            // El catálogo viaja desde el modelo: el formulario y la vista previa
            // se arman con él en vez de repetir la lista de tipos en el front.
            'actionTypes' => WhatsAppMenuOption::catalog(),
            // Qué parte del contrato puede mostrar cada opción de "Estado del
            // contrato": el select se arma con esto en vez de repetir la lista.
            'statusSegments' => collect(WhatsAppMenuOption::STATUS_SEGMENTS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values(),
            // Las acciones de negocio no sirven de nada sin Integra conectado:
            // el formulario lo avisa en vez de dejar que el admin arme un menú
            // que en producción sólo va a derivar chats a un asesor.
            'integra' => [
                'connected' => Integra::connected($user->company_id),
            ],
            // El interruptor de la IA. Vive aquí y no en Integraciones porque
            // es la IA DE LOS MENÚS: se enciende donde se configuran.
            'ai' => [
                'enabled' => (bool) $this->aiIntegration($user->company_id)?->enabled,
                // Sin el flujo configurado en el servidor no hay a quién
                // preguntar. No lo puede arreglar el admin de la empresa, así
                // que la pantalla lo dice en vez de dejar un botón que falla.
                'available' => filled(config('services.ai_menus.webhook_url')),
            ],
        ]);
    }

    private function aiIntegration(int $companyId): ?CompanyIntegration
    {
        return CompanyIntegration::where('company_id', $companyId)
            ->where('key', CompanyIntegration::KEY_AI_MENUS)
            ->first();
    }

    /**
     * POST /whatsapp-menus/ai — enciende o apaga la IA de esta empresa.
     *
     * Es lo único que la empresa decide sobre la IA: el servidor, el modelo y
     * los permisos son los mismos para toda la plataforma y viven en el flujo.
     */
    public function toggleAi(Request $request)
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $user = auth()->user();

        $integration = $this->aiIntegration($user->company_id);

        if (! $integration) {
            // Toda empresa nace con su fila (observer + migración), pero si
            // faltara se crea al vuelo antes que devolver un error que el admin
            // no puede resolver.
            $integration = DefaultAiMenusIntegration::createFor($user->company);
        }

        if ($data['enabled'] && blank(config('services.ai_menus.webhook_url'))) {
            return back()->withErrors([
                'ai' => 'Falta configurar el flujo de IA en el servidor. Avisa al equipo técnico.',
            ]);
        }

        $integration->update(['enabled' => $data['enabled']]);

        return back()->with('success', $data['enabled']
            ? 'IA activada. Ya atiende los mensajes que ningún menú reconozca.'
            : 'IA desactivada. Los menús siguen funcionando igual.');
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $data = $this->validateData($request, $user->company_id);

        if ($conflict = $this->welcomeConflict($data, $user->company_id)) {
            return $conflict;
        }

        DB::transaction(function () use ($data, $user) {
            $menu = WhatsAppMenu::create($this->menuAttributes($data) + ['company_id' => $user->company_id]);
            $this->syncOptions($menu, $data['options']);
        });

        return redirect()->route('whatsapp-menus.index')->with('success', 'Menú creado');
    }

    public function update(Request $request, $id)
    {
        $user = auth()->user();

        $menu = WhatsAppMenu::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        $data = $this->validateData($request, $user->company_id, $menu->id);

        if ($conflict = $this->welcomeConflict($data, $user->company_id, $menu->id)) {
            return $conflict;
        }

        DB::transaction(function () use ($menu, $data) {
            $menu->update($this->menuAttributes($data));
            $this->syncOptions($menu, $data['options']);
        });

        return redirect()->route('whatsapp-menus.index')->with('success', 'Menú actualizado');
    }

    public function destroy($id)
    {
        $user = auth()->user();

        $menu = WhatsAppMenu::where('id', $id)
            ->where('company_id', $user->company_id)
            ->firstOrFail();

        // Borrar un submenú deja a las opciones que apuntaban a él sin destino
        // (target_menu_id queda en null por la FK). Se avisa en vez de dejar que
        // el cliente descubra el agujero tocando una opción que no hace nada.
        $referencedBy = WhatsAppMenuOption::where('target_menu_id', $menu->id)
            ->whereHas('menu', fn ($q) => $q->where('company_id', $user->company_id))
            ->with('menu:id,name')
            ->get();

        $menu->delete();

        $warning = $referencedBy->isNotEmpty()
            ? ' Revisa: ' . $referencedBy->pluck('menu.name')->unique()->implode(', ')
                . ' tenía(n) opciones que llevaban a este menú y quedaron sin destino.'
            : '';

        return redirect()->route('whatsapp-menus.index')
            ->with('success', 'Menú eliminado.' . $warning);
    }

    private function menuAttributes(array $data): array
    {
        $isRoot = (bool) ($data['is_root'] ?? true);
        $types = $isRoot ? array_values(array_unique($data['match_types'] ?? [])) : [];
        $hasKeyword = count(array_intersect($types, WhatsAppMenu::KEYWORD_TYPES)) > 0;

        return [
            'instance_id' => $data['instance_id'] ?? null,
            'name' => $data['name'],
            'header_text' => $data['header_text'] ?? null,
            'body_text' => $data['body_text'],
            'footer_text' => $data['footer_text'] ?? null,
            'list_button_text' => $data['list_button_text'] ?: 'Ver opciones',
            'is_root' => $isRoot,
            'trigger_text' => $hasKeyword ? $data['trigger_text'] : null,
            'match_types' => $types,
            'active' => $data['active'] ?? true,
            'cooldown_minutes' => $data['cooldown_minutes'] ?? 60,
        ];
    }

    /**
     * Reescribe las opciones del menú.
     *
     * Se actualizan las que siguen existiendo en vez de borrar y recrear: el id
     * de la opción viaja dentro del menú que el cliente ya tiene en el móvil, y
     * recrearlas dejaría muertos todos los menús enviados hasta ahora.
     */
    private function syncOptions(WhatsAppMenu $menu, array $options): void
    {
        $keptIds = [];

        foreach (array_values($options) as $position => $option) {
            $attributes = [
                'position' => $position,
                'title' => $option['title'],
                'description' => $option['description'] ?? null,
                'action_type' => $option['action_type'],
                // Las acciones pendientes de integración guardan el texto como
                // aviso a medida; sin él sale el aviso por defecto del tipo.
                'reply_text' => WhatsAppMenuOption::carriesText($option['action_type'])
                    ? ($option['reply_text'] ?? null)
                    : null,
                'target_menu_id' => $option['action_type'] === 'submenu' ? $option['target_menu_id'] : null,
                'assign_to_user_id' => $option['action_type'] === 'handoff'
                    ? ($option['assign_to_user_id'] ?? null)
                    : null,
                'config' => $this->optionConfig($option),
            ];

            $existing = !empty($option['id'])
                ? $menu->options()->whereKey($option['id'])->first()
                : null;

            if ($existing) {
                $existing->update($attributes);
                $keptIds[] = $existing->id;
                continue;
            }

            $keptIds[] = $menu->options()->create($attributes)->id;
        }

        $menu->options()->whereNotIn('id', $keptIds ?: [0])->delete();
        $menu->load('options');
    }

    /**
     * Sólo un menú de bienvenida por instancia: si hubiera dos, el primer
     * mensaje del cliente dispararía uno u otro según el orden de creación, que
     * es justo el tipo de comportamiento que nadie logra explicarse después.
     */
    private function welcomeConflict(array $data, int $companyId, $ignoreId = null)
    {
        if (!($data['is_root'] ?? true) || !in_array('welcome', $data['match_types'] ?? [], true)) {
            return null;
        }

        $exists = WhatsAppMenu::where('company_id', $companyId)
            ->where('instance_id', $data['instance_id'] ?? null)
            ->where('is_root', true)
            ->whereJsonContains('match_types', 'welcome')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if (!$exists) {
            return null;
        }

        return back()->withErrors([
            'match_types' => 'Ya existe un menú de bienvenida para esta instancia.',
        ]);
    }

    private function validateData(Request $request, int $companyId, $menuId = null): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'instance_id' => 'nullable|integer|exists:instances,id',
            'header_text' => 'nullable|string|max:' . WhatsAppMenu::MAX_HEADER,
            'body_text' => 'required|string|max:' . WhatsAppMenu::MAX_BODY,
            'footer_text' => 'nullable|string|max:' . WhatsAppMenu::MAX_FOOTER,
            'list_button_text' => 'nullable|string|max:' . WhatsAppMenu::MAX_BUTTON_TITLE,
            'is_root' => 'boolean',
            'match_types' => 'array',
            'match_types.*' => 'in:' . implode(',', WhatsAppMenu::MATCH_TYPES),
            'trigger_text' => 'nullable|string|max:1000',
            'active' => 'boolean',
            'cooldown_minutes' => 'nullable|integer|min:0|max:10080',

            'options' => 'required|array|min:1|max:' . WhatsAppMenu::MAX_ROWS,
            'options.*.id' => 'nullable|integer',
            'options.*.title' => 'required|string|max:' . WhatsAppMenu::MAX_ROW_TITLE,
            'options.*.description' => 'nullable|string|max:' . WhatsAppMenu::MAX_ROW_DESCRIPTION,
            'options.*.action_type' => 'required|in:' . implode(',', WhatsAppMenuOption::ACTION_TYPES),
            'options.*.reply_text' => 'nullable|string|max:4096',
            'options.*.target_menu_id' => 'nullable|integer',
            'options.*.assign_to_user_id' => 'nullable|integer',
            'options.*.config' => 'nullable|array',
            'options.*.config.assign_strategy' => 'nullable|in:' . implode(',', WhatsAppMenuOption::ASSIGN_STRATEGIES),
            'options.*.config.radicado_servicio' => 'nullable|integer',
            'options.*.config.radicado_prioridad' => 'nullable|integer|in:1,2,3',
            'options.*.config.radicado_tecnico' => 'nullable|integer',
            'options.*.config.payment_url' => 'nullable|string|max:500',
            'options.*.config.segmento' => 'nullable|in:' . implode(',', array_keys(WhatsAppMenuOption::STATUS_SEGMENTS)),
            // Meta descarga la imagen desde esta URL al enviar, así que tiene
            // que ser pública y absoluta: una ruta relativa se guarda sin
            // protestar y falla en el primer cliente que toque la opción.
            'options.*.config.image_url' => 'nullable|url|max:2048',
            'options.*.config.dias_consumo' => 'nullable|integer|min:1|max:90',
        ]);

        $isRoot = (bool) ($validated['is_root'] ?? true);

        if ($isRoot && empty($validated['match_types'])) {
            throw ValidationException::withMessages([
                'match_types' => 'Indica cuándo debe aparecer el menú, o márcalo como submenú.',
            ]);
        }

        $hasKeyword = count(array_intersect($validated['match_types'] ?? [], WhatsAppMenu::KEYWORD_TYPES)) > 0;

        if ($isRoot && $hasKeyword && trim((string) ($validated['trigger_text'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'trigger_text' => 'Indica las palabras clave que disparan el menú.',
            ]);
        }

        if (!empty($validated['instance_id'])) {
            Instance::where('id', $validated['instance_id'])
                ->where('company_id', $companyId)
                ->firstOrFail();
        }

        $this->validateOptions($validated['options'], $companyId, $menuId);

        return $validated;
    }

    /**
     * Ajustes que guarda cada acción.
     *
     * Se conservan sólo las claves que su tipo entiende: si el admin configura
     * un radicado y luego cambia la opción a "Pasar a un asesor", el servicio y
     * la prioridad se van con ella. Arrastrarlos dejaría configuraciones que no
     * se ven en el formulario pero sí en la base, y que reaparecen al volver
     * atrás sin que nadie las haya escrito.
     */
    private function optionConfig(array $option): ?array
    {
        $config = is_array($option['config'] ?? null) ? $option['config'] : [];

        $keys = match ($option['action_type']) {
            'handoff' => ['assign_strategy'],
            // El enlace de pago también lo usa "Reportar falla": cuando la
            // falla resulta ser un corte por mora, el cliente necesita pagar,
            // no un radicado.
            'reportar_falla' => ['radicado_servicio', 'radicado_prioridad', 'radicado_tecnico', 'payment_url'],
            'pagar_en_linea' => ['payment_url'],
            'estado_servicio' => ['segmento'],
            default => [],
        };

        $kept = array_filter(
            Arr::only($config, $keys),
            fn ($v) => $v !== null && $v !== ''
        );

        return $kept ?: null;
    }

    /**
     * GET /whatsapp-menus/integra-catalogs — tipos de falla, prioridades y
     * técnicos del entorno Integra de la empresa, para los selects del
     * formulario de la opción "Reportar falla".
     */
    public function integraCatalogs()
    {
        return Integra::respond(
            auth()->user()->company_id,
            fn (IntegraClient $client) => $client->radicadoCatalogs(),
            'Conecta tu software Integra desde Integraciones para configurar esta acción.'
        );
    }

    /**
     * Lo que le va a fallar al menú antes de que lo toque un cliente.
     *
     * Va aparte de index() y no dentro porque comprueba los permisos reales del
     * token contra el servidor de Integra: son cuatro llamadas HTTP a otra
     * máquina, y si ese servidor tarda no puede retrasar la carga de la página.
     */
    public function review(Request $request)
    {
        $companyId = auth()->user()->company_id;

        $capabilities = IntegraCapabilities::for($companyId, $request->boolean('fresh'));

        $menus = WhatsAppMenu::where('company_id', $companyId)->with('options')->get();

        return response()->json([
            'capabilities' => $capabilities,
            'labels' => IntegraCapabilities::LABELS,
            'issues' => MenuReview::build($menus, $capabilities),
        ]);
    }

    /**
     * Guarda la imagen de una opción y devuelve su URL pública.
     *
     * Se sube al elegir el archivo, no al guardar el menú: Meta descarga la
     * imagen desde esta URL en el momento de enviar, así que si no es
     * alcanzable desde fuera el fallo aparece con el primer cliente que toque
     * la opción. Subirla ya deja al admin verla en la vista previa antes de
     * encender nada.
     *
     * El disco s3_media va con 'throw' => false: si el bucket falla,
     * storePublicly() devuelve false en vez de lanzar, y sin esta guarda se
     * devolvía una URL rota que se guardaba tan tranquila en la opción.
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            // WhatsApp acepta jpeg y png en mensajes de imagen, con tope de 5 MB.
            'image' => 'required|image|mimes:jpeg,jpg,png|max:5120',
        ], [
            'image.mimes' => 'WhatsApp sólo envía imágenes JPG o PNG.',
            'image.max' => 'La imagen no puede pasar de 5 MB.',
        ]);

        $path = $request->file('image')->storePublicly('whatsapp/menus', 's3_media');

        if (!$path) {
            \Illuminate\Support\Facades\Log::channel('whatsapp')->error('❌ No se pudo subir la imagen del menú', [
                'company_id' => auth()->user()->company_id,
                'original_name' => $request->file('image')->getClientOriginalName(),
            ]);

            return response()->json([
                'message' => 'No se pudo guardar la imagen en el almacenamiento. Intenta de nuevo.',
            ], 500);
        }

        return response()->json([
            'url' => \Illuminate\Support\Facades\Storage::disk('s3_media')->url($path),
        ]);
    }

    private function validateOptions(array $options, int $companyId, $menuId): void
    {
        foreach ($options as $i => $option) {
            $type = $option['action_type'];

            if ($type === 'reply_text' && trim((string) ($option['reply_text'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "options.$i.reply_text" => 'Escribe la respuesta que recibirá el cliente.',
                ]);
            }

            $config = is_array($option['config'] ?? null) ? $option['config'] : [];

            // Una opción de imagen sin imagen manda al cliente el pie de foto
            // suelto, o nada si tampoco lo hay. Es el fallo que sólo se
            // descubre cuando ya lo tocó un cliente.
            if ($type === WhatsAppMenuOption::ACTION_IMAGE && trim((string) ($config['image_url'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    "options.$i.config.image_url" => 'Sube la imagen que recibirá el cliente.',
                ]);
            }

            // Un handoff "a un asesor concreto" sin asesor elegido no asigna
            // nada y el chat se queda en la bandeja, que es justo lo contrario
            // de lo que el admin creyó configurar.
            if ($type === 'handoff'
                && ($config['assign_strategy'] ?? null) === WhatsAppMenuOption::ASSIGN_FIXED
                && empty($option['assign_to_user_id'])) {
                throw ValidationException::withMessages([
                    "options.$i.assign_to_user_id" => 'Elige el asesor que recibirá el chat.',
                ]);
            }

            $paymentUrl = trim((string) ($config['payment_url'] ?? ''));

            if ($paymentUrl !== '' && !str_starts_with($paymentUrl, 'https://') && !str_starts_with($paymentUrl, 'http://')) {
                throw ValidationException::withMessages([
                    "options.$i.config.payment_url" => 'El enlace de pago debe empezar por https://',
                ]);
            }

            if ($type !== 'submenu') {
                continue;
            }

            if (empty($option['target_menu_id'])) {
                throw ValidationException::withMessages([
                    "options.$i.target_menu_id" => 'Elige el submenú al que lleva esta opción.',
                ]);
            }

            // Un menú que se apunta a sí mismo devuelve al cliente al mismo sitio
            // del que venía. No cuelga nada, pero no hay forma de salir de ahí.
            if ($menuId && (int) $option['target_menu_id'] === (int) $menuId) {
                throw ValidationException::withMessages([
                    "options.$i.target_menu_id" => 'Un menú no puede llevar a sí mismo.',
                ]);
            }

            WhatsAppMenu::where('id', $option['target_menu_id'])
                ->where('company_id', $companyId)
                ->firstOrFail();
        }
    }
}
