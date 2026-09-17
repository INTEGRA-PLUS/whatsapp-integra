<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyIntegration;
use App\Models\Instance;
use App\Models\User;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Support\DefaultWhatsAppMenu;
use App\Support\PlanDeLaEmpresa;
use App\Services\WhatsAppChatAiClient;
use App\Support\OrdenDeLaConversacion;
use App\Support\UsaIntegra;
use App\Services\Integra;
use App\Services\IntegraCapabilities;
use App\Services\IntegraClient;
use App\Support\DefaultAiMenusIntegration;
use App\Support\MenuReview;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

        $company = Company::findOrFail($user->company_id);

        $menus = WhatsAppMenu::where('company_id', $user->company_id)
            ->with(['instance:id,name', 'options.assignee:id,name', 'options.targetMenu:id,name'])
            ->orderByDesc('is_root')
            ->orderByDesc('created_at')
            ->get()
            // El formato es lo primero que pregunta quien configura ("¿esto sale
            // como botones o como lista?"), y se deduce del número de opciones.
            ->each(fn (WhatsAppMenu $menu) => $menu->setAttribute('format', $menu->format()));

        $this->marcarQuienResponde($menus);

        // Integra es un extra para ISPs. Una farmacia o una barbería no debe
        // leer ni una palabra sobre él: ni permisos de IA que lo consultan, ni
        // avisos de si está conectado, ni la plantilla.
        //
        // **La señal es si la empresa es cliente de Integra**, no si tiene
        // puestas las opciones que lo consultan. Eso último se probó y no
        // servía: el menú de fábrica antiguo se las sembró a las 49 empresas,
        // así que la condición era verdadera siempre y no escondía nada a
        // nadie. Un cliente nuevo que no venga de Integra no ve nada aunque
        // herede un menú con esas opciones.
        $integraConectado = Integra::connected($user->company_id);
        $tieneAutoservicio = $this->yaTieneAutoservicio($menus);
        $usaIntegra = UsaIntegra::de($company);

        // De aquí sale también si la acción «Que responda la IA» se puede
        // elegir: es la misma condición que decide si la IA contesta de verdad
        // —encendida, dentro del plan y con el flujo configurado en el
        // servidor—. Calcularla dos veces con dos criterios es justo cómo
        // aparecen las opciones que al tocarlas no hacen lo que prometen.
        $orden = OrdenDeLaConversacion::de($user->company_id);

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
                'connected' => $integraConectado,
                // ¿Le serviría de algo la plantilla de ISP? Sólo si NO tiene ya
                // las opciones de autoservicio puestas. Ofrecérsela a quien ya
                // las tiene es un botón que no hace nada visible.
                'puede_aplicar_plantilla' => $usaIntegra && ! $tieneAutoservicio,
                // **La llave de todo lo que menciona Integra en esta pantalla.**
                //
                // Una barbería no tiene por qué enterarse de que existe un ERP
                // de ISPs, ni de si está conectado. Integra sólo aparece para
                // quien lo usa: lo tiene conectado, o tiene puestas en su menú
                // las opciones que lo consultan.
                'usa' => $usaIntegra,
            ],
            // Qué le pasa hoy a un cliente que escribe. La pregunta «¿sale
            // primero un menú o la IA?» no tenía respuesta en ninguna pantalla,
            // y la respuesta cambia según lo que la empresa tenga puesto.
            'orden' => $orden,
            // Para el desplegable de acciones: la de IA se enseña siempre, pero
            // sólo se puede elegir si la IA está de verdad disponible.
            'iaDisponible' => $orden['ia_chat'],
            // Y si no lo está, POR QUÉ. Mandar a otra pantalla «a encender un
            // interruptor» sin saber si esa empresa puede encenderlo acaba en un
            // viaje para encontrarse el interruptor en gris. Con esto, la propia
            // tarjeta ofrece el interruptor cuando se puede, y cuando no, dice
            // qué falta y quién puede resolverlo.
            'iaEstado' => [
                'disponible' => $orden['ia_chat'],
                'en_el_plan' => PlanDeLaEmpresa::de($company)->permiteFlujoIa('ai_chat'),
                'configurada' => WhatsAppChatAiClient::configured(),
                'desbloqueada' => $company->aiFlowUnlocked(),
                'complemento' => PlanDeLaEmpresa::de($company)->nombreIa(),
            ],
            // El interruptor de la IA. Vive aquí y no en Integraciones porque
            // es la IA DE LOS MENÚS: se enciende donde se configuran.
            'ai' => [
                'enabled' => (bool) $this->aiIntegration($user->company_id)?->enabled,
                // Sin el flujo configurado en el servidor no hay a quién
                // preguntar. No lo puede arreglar el admin de la empresa, así
                // que la pantalla lo dice en vez de dejar un botón que falla.
                'available' => filled(config('services.ai_menus.webhook_url')),
                // Hasta dónde llega la IA de esta empresa. El catálogo viaja
                // desde el modelo por lo mismo que el de tipos de acción: para
                // no repetir la lista en el front y que se desincronice.
                'permissions' => $this->aiIntegration($user->company_id)?->aiPermissions()
                    ?? CompanyIntegration::AI_PERMISSIONS_DEFAULT,
                'permissionCatalog' => self::AI_PERMISSION_LABELS,
            ],
        ]);
    }

    /**
     * Cómo se le explican los permisos de la IA al admin.
     *
     * En términos de lo que el cliente va a poder hacer, no en términos de
     * endpoints: quien decide esto es alguien que sabe si quiere que un modelo
     * abra averías a su nombre, no qué es `POST /api/v1/radicados`.
     */
    private const AI_PERMISSION_LABELS = [
        [
            'value' => CompanyIntegration::AI_READ,
            'label' => 'Consultar',
            'description' => 'Facturas pendientes, estado del servicio, plan y fecha de corte. Sólo lee.',
        ],
        [
            'value' => CompanyIntegration::AI_TICKETS,
            'label' => 'Radicar fallas',
            'description' => 'Crea el radicado en Integra cuando el cliente reporta una avería. Sin esto, la IA pasa el chat a un asesor.',
        ],
        [
            'value' => CompanyIntegration::AI_PAYMENTS,
            'label' => 'Gestionar pagos',
            'description' => 'Entrega el enlace de pago y avisa a tus sistemas para que generen el cobro. Sin esto, lo atiende un asesor.',
        ],
    ];

    private function aiIntegration(int $companyId): ?CompanyIntegration
    {
        return CompanyIntegration::where('company_id', $companyId)
            ->where('key', CompanyIntegration::KEY_AI_MENUS)
            ->first();
    }

    /**
     * POST /whatsapp-menus/ai — enciende o apaga la IA de esta empresa.
     *
     * Sólo enciende y apaga. Hasta dónde llega la IA se concede aparte
     * (updateAiPermissions): encenderla no puede significar autorizarle de una
     * vez a radicar fallas y a disparar cobros.
     */
    /**
     * ¿Alguno de sus menús ya usa las opciones de autoservicio de Integra?
     *
     * @param  \Illuminate\Support\Collection  $menus
     */
    /**
     * Cuál de los menús activos responde de verdad, y cuáles quedan tapados.
     *
     * Se pueden tener varios menús raíz encendidos a la vez, y la pantalla los
     * enseñaba todos con la misma etiqueta «Activo» — sin decir que, cuando
     * llega un mensaje, **se prueban en orden y responde el primero que
     * encaja**. Con dos menús de bienvenida activos, uno se dispara siempre y el
     * otro no se dispara nunca, y desde fuera los dos parecían funcionando.
     *
     * El orden es el mismo que usa el servicio al recibir el mensaje
     * (`WhatsAppMenu::ordenDeDisparo`), no una copia: si cambia allí, esto
     * cambia con él.
     *
     * Se marcan dos cosas distintas, porque son dos preguntas distintas:
     *   - `responde_al_saludo`: quién atiende el primer mensaje de un cliente.
     *     Sólo uno, y es el que la mayoría cree estar viendo.
     *   - `tapado_por`: el menú que le gana. Con palabras clave distintas los
     *     dos pueden dispararse —cada uno con lo suyo— así que esto sólo se
     *     pone cuando el conflicto es real: los dos atienden el saludo.
     */
    private function marcarQuienResponde($menus): void
    {
        $raices = $menus
            ->filter(fn (WhatsAppMenu $m) => $m->is_root && $m->active && $m->options->isNotEmpty())
            ->sort(WhatsAppMenu::ordenDeDisparo(...))
            ->values();

        // Los que atienden un saludo, en el orden en que se probarían. El
        // primero es el que responde; los demás, los que nadie verá.
        $deBienvenida = $raices->filter(
            fn (WhatsAppMenu $m) => in_array('welcome', $m->matchTypes(), true)
        )->values();

        $ganador = $deBienvenida->first();

        foreach ($menus as $menu) {
            $menu->setAttribute('responde_al_saludo', $ganador && $menu->id === $ganador->id);
            $menu->setAttribute(
                'tapado_por',
                $ganador && $deBienvenida->contains(fn (WhatsAppMenu $m) => $m->id === $menu->id)
                    && $menu->id !== $ganador->id
                        ? $ganador->name
                        : null
            );
        }
    }

    private function yaTieneAutoservicio($menus): bool
    {
        return $menus->contains(
            fn ($menu) => collect($menu['options'] ?? [])->contains(
                fn ($o) => array_key_exists(
                    (string) ($o['action_type'] ?? ''),
                    WhatsAppMenuOption::INTEGRA_ACTIONS
                )
            )
        );
    }

    /**
     * POST /whatsapp-menus/plantilla-isp
     *
     * Trae las opciones de autoservicio de Integra al menú principal.
     *
     * Existe porque toda empresa nueva nace con el menú **genérico** —el que
     * funciona para cualquier negocio sin conectar nada— y un ISP necesitaba
     * volver a escribir a mano las cuatro opciones que antes venían de fábrica.
     *
     * No toca los textos del menú (cabecera, cuerpo, pie): son de la empresa y
     * a menudo llevan su nombre y su tono. Sólo trae las opciones.
     */
    public function aplicarPlantillaIsp(Request $request)
    {
        $company = Company::findOrFail($request->user()->company_id);

        // Con menú principal se le añaden las opciones y se le respetan los
        // textos; sin ninguno —porque los borró— se crea el de ISP entero. Antes
        // esto respondía «crea uno primero», que es mandar a hacer a mano justo
        // lo que el botón existe para evitar.
        $resultado = DefaultWhatsAppMenu::applyTemplateInPlace($company)
            ?: (DefaultWhatsAppMenu::createFor($company) ? ['creadas' => 0] : null);

        if (! $resultado) {
            return back()->with('error', 'No se pudo aplicar la plantilla. Inténtalo de nuevo.');
        }

        Log::channel('whatsapp')->info('🧩 Plantilla de ISP aplicada al menú', [
            'company_id' => $company->id,
            'user_id' => $request->user()->id,
            'creadas' => $resultado['creadas'],
        ]);

        return back()->with(
            'success',
            'Listo: tu menú ya tiene las opciones de autoservicio. Revísalas antes de encenderlo.'
        );
    }

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

    /**
     * POST /whatsapp-menus/ai/permisos — hasta dónde llega la IA de esta empresa.
     *
     * Los permisos vivían en el flujo de n8n e iguales para toda la plataforma,
     * así que `radicados` y `pagos` aplicaban a cualquier empresa que encendiera
     * el interruptor. El flujo los sigue cruzando con los suyos: esto puede
     * restringir, nunca conceder más de lo que la plataforma permite.
     */
    public function updateAiPermissions(Request $request)
    {
        $data = $request->validate([
            'permissions' => 'present|array',
            'permissions.*' => ['string', Rule::in(CompanyIntegration::AI_PERMISSIONS)],
        ]);

        $user = auth()->user();
        $integration = $this->aiIntegration($user->company_id)
            ?? DefaultAiMenusIntegration::createFor($user->company);

        // El orden y los duplicados vienen del formulario; se guarda la lista
        // canónica para que dos empresas con los mismos permisos se lean igual.
        $integration->update([
            'abilities' => array_values(array_intersect(
                CompanyIntegration::AI_PERMISSIONS,
                $data['permissions']
            )),
        ]);

        return back()->with('success', 'Permisos de la IA actualizados.');
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $data = $this->validateData($request, $user->company_id);

        $queSaluda = $this->welcomeConflict($data, $user->company_id);

        if ($queSaluda && ! $request->boolean('reemplazar_bienvenida')) {
            return $this->pideConfirmarLaBienvenida($queSaluda);
        }

        DB::transaction(function () use ($data, $user, $queSaluda) {
            if ($queSaluda) {
                $this->cederLaBienvenida($queSaluda);
            }

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

        $queSaluda = $this->welcomeConflict($data, $user->company_id, $menu->id);

        if ($queSaluda && ! $request->boolean('reemplazar_bienvenida')) {
            return $this->pideConfirmarLaBienvenida($queSaluda);
        }

        DB::transaction(function () use ($menu, $data, $queSaluda) {
            if ($queSaluda) {
                $this->cederLaBienvenida($queSaluda);
            }

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
     *
     * Devuelve el menú que ya la tiene, para poder nombrarlo. Antes devolvía un
     * error y punto —«Ya existe un menú de bienvenida para esta instancia»— sin
     * decir cuál ni dejar salida: el admin que quería **cambiar** cuál saluda se
     * quedaba encerrado, porque para llegar a este formulario había que ir a
     * editar el otro menú y adivinar que lo que había que hacer allí era
     * desmarcarle una casilla.
     */
    private function welcomeConflict(array $data, int $companyId, $ignoreId = null): ?WhatsAppMenu
    {
        if (!($data['is_root'] ?? true) || !in_array('welcome', $data['match_types'] ?? [], true)) {
            return null;
        }

        // El filtro de «welcome» se hace en PHP y no con whereJsonContains:
        // con dos tipos guardados —`["welcome","contains"]`— la consulta no lo
        // encontraba, y el relevo se saltaba dejando DOS menús saludando, que
        // es justo lo que esta comprobación existe para impedir. Es además el
        // idiomático del proyecto (ModoDeAtencion hace lo mismo).
        return WhatsAppMenu::where('company_id', $companyId)
            ->where('instance_id', $data['instance_id'] ?? null)
            ->where('is_root', true)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->get()
            ->first(fn (WhatsAppMenu $m) => in_array('welcome', (array) $m->match_types, true));
    }

    /**
     * El error cuando no se confirmó el relevo.
     *
     * Nombra al menú que saluda hoy: «ya existe uno» obliga a salir a buscar
     * cuál de los seis es.
     */
    private function pideConfirmarLaBienvenida(WhatsAppMenu $queSaluda)
    {
        return back()->withErrors([
            'match_types' => '«'.$queSaluda->name.'» ya es el menú de bienvenida de esta instancia.'
                .' Marca la casilla de abajo si quieres que salude éste en su lugar.',
        ]);
    }

    /**
     * El menú que saludaba deja de hacerlo, para que salude otro.
     *
     * **No se apaga ni se borra**: sólo pierde el disparo de bienvenida. Si
     * tenía palabras clave sigue respondiendo a ellas, y si no, se queda como
     * un menú que sólo se abre desde otra opción — que es recuperable con una
     * casilla, mientras que apagarlo por nuestra cuenta no lo parece.
     */
    private function cederLaBienvenida(WhatsAppMenu $menu): void
    {
        $menu->update([
            'match_types' => array_values(array_diff((array) $menu->match_types, ['welcome'])),
        ]);

        Log::channel('whatsapp')->info('👋 La bienvenida cambia de menú', [
            'company_id' => $menu->company_id,
            'menu_id' => $menu->id,
            'nombre' => $menu->name,
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
            // El relevo de la bienvenida: sin esto el guardado se rechaza y se
            // explica de quién habría que quitarla.
            'reemplazar_bienvenida' => 'sometimes|boolean',
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
        $company = Company::findOrFail(auth()->user()->company_id);

        $capabilities = IntegraCapabilities::for($company->id, $request->boolean('fresh'));

        $menus = WhatsAppMenu::where('company_id', $company->id)->with('options')->get();

        return response()->json([
            'capabilities' => $capabilities,
            'labels' => IntegraCapabilities::LABELS,
            // Este panel carga por su cuenta, aparte de la pantalla, así que
            // necesita la misma llave o acabaría siendo el único sitio que le
            // habla de Integra a una farmacia. Ya pasó.
            'issues' => MenuReview::build(
                $menus,
                $capabilities,
                UsaIntegra::de($company),
                OrdenDeLaConversacion::de($company->id)['ia_chat'],
            ),
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
