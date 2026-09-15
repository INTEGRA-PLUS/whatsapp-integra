<?php

namespace App\Console\Commands;

use App\Models\AutoResponse;
use App\Models\BusinessHour;
use App\Models\Company;
use App\Models\CompanyExtension;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\KanbanColumn;
use App\Models\Macro;
use App\Models\QuickReply;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Models\WhatsAppCampaignRecipient;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMenu;
use App\Models\WhatsAppMenuOption;
use App\Models\WhatsAppMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Monta una empresa de demostración con datos que parecen reales.
 *
 * Una demo con "Cliente 1" y "Hola qué tal" no vende: quien la mira tiene que
 * imaginarse su propio negocio encima, y eso es trabajo que no va a hacer en
 * una reunión de media hora. Esta monta una cooperativa de ahorro y crédito
 * completa —sus sedes, sus líneas de crédito, las preguntas que de verdad le
 * hacen los socios— para que el cliente se vea a sí mismo.
 *
 * **No puede enviar nada.** La instancia queda inactiva y con credenciales
 * falsas a propósito: una demo que por accidente le escriba a alguien es
 * exactamente el incidente que no queremos.
 */
class MontarEmpresaDemo extends Command
{
    protected $signature = 'demo:montar
        {--slug=cootramed-demo : Identificador de la empresa de demostración}
        {--nombre=Cootramed (DEMO) : Nombre visible}
        {--password=demo1234 : Contraseña de los usuarios de demostración}
        {--rehacer : Borra la empresa de demostración y la vuelve a crear}';

    protected $description = 'Crea una empresa de demostración con socios, conversaciones, menús y campañas realistas';

    /** Las sedes reales de la cooperativa: la demo se reconoce o no vende. */
    private const SEDES = ['Medellín', 'Caucasia', 'Tarso', 'Chigorodó', 'Arboletes'];

    public function handle(): int
    {
        $slug = (string) $this->option('slug');

        if ($this->option('rehacer')) {
            $this->borrar($slug);
        }

        if (Company::where('slug', $slug)->exists()) {
            $this->error("Ya existe una empresa con slug «{$slug}». Usa --rehacer para rehacerla.");

            return self::FAILURE;
        }

        $company = DB::transaction(fn () => $this->montar($slug));

        // Fuera de la transacción: el semáforo lee las conversaciones que
        // acaban de crearse, y dentro de la transacción no existen todavía para
        // nadie más. Va aquí y no a mano porque una demo con las caritas en
        // gris enseña la función apagada — la extensión sólo colorea lo que
        // llega DESPUÉS de encenderse, y aquí todo llegó antes.
        $this->call('wa:semaforo-recalcular', [
            '--empresa' => $company->id,
            '--todas' => true,
        ]);

        $this->newLine();
        $this->info('Empresa de demostración lista.');
        $this->table(['Dato', 'Valor'], [
            ['Empresa', $company->name],
            ['Entrar como', "admin@{$slug}.demo"],
            ['Contraseña', (string) $this->option('password')],
            ['Agentes', 'ana@, carlos@, lucia@ '."{$slug}.demo"],
        ]);
        $instancia = Instance::where('company_id', $company->id)->first();
        $conversaciones = WhatsAppConversation::where('instance_id', $instancia?->id)->get();
        $largas = $conversaciones->filter(
            fn (WhatsAppConversation $c) => WhatsAppMessage::where('conversation_id', $c->id)->count() >= 8
        );

        $this->newLine();
        $this->line('  Extensiones encendidas: <fg=green>'
            .CompanyExtension::where('company_id', $company->id)->where('enabled', true)->count().' de 5</>');
        $this->line('  Conversaciones: <fg=green>'.$conversaciones->count().'</>'
            .', de ellas <fg=green>'.$largas->count().'</> con 8+ mensajes (botón «Resumir» visible)');
        $this->line('  Semáforo pintado en: <fg=green>'
            .$conversaciones->whereNotNull('sentiment_level')->count().'</> conversaciones');

        $this->newLine();
        $this->warn('La línea no tiene token: se puede navegar y abrir el chat, pero la demo no puede enviarle nada a nadie.');

        return self::SUCCESS;
    }

    private function montar(string $slug): Company
    {
        $company = Company::create([
            'name' => (string) $this->option('nombre'),
            'slug' => $slug,
            'active' => true,

            // Interna y en cortesía: una demo no se factura nunca, y sin esta
            // marca aparecería en la lista de cobro del panel maestro el día
            // que se exporte. Plan Inteligente porque la demo tiene que poder
            // enseñar TODO, incluidas las extensiones con IA — que es
            // justamente lo que se va a vender.
            'interna' => true,
            'plan' => 'inteligente',
            'cobro' => 'cortesia',
            'contactos_contratados' => 500,
            'nota_de_cobro' => 'Cuenta de demostración. No facturar.',
        ]);

        // Activa para que aparezca en el selector —una demo con la línea
        // apagada no deja abrir el chat, que es justo lo que se quiere
        // enseñar— y **sin token**, que es lo que de verdad impide enviar:
        // `isMetaConfigured()` exige el access_token, así que sin él ningún
        // camino de salida llega a Meta.
        $instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea de asociados',
            'phone_number_id' => 'DEMO-'.Str::random(12),
            'waba_id' => 'DEMO-'.Str::random(10),
            'display_phone_number' => '+57 312 602 1105',
            'type' => 'meta',
            'status' => 'active',
            'active' => true,
            'access_token' => '',
        ]);

        [$admin, $agentes] = $this->personas($company, $slug);
        $etiquetas = $this->etiquetas($company);
        $this->kanban($company, $etiquetas);
        $this->respuestasRapidas($company);
        $this->macros($company);
        $this->horario($company, $instance);
        $this->autorespuesta($company, $instance);
        $this->menus($company, $instance);

        $this->extensiones($company, $admin);

        $socios = $this->socios($company);
        $this->conversaciones($instance, $socios, $agentes, $etiquetas);
        $this->campana($company, $instance, $admin, $socios);

        return $company;
    }


    /**
     * Las cinco extensiones instaladas y encendidas.
     *
     * Sin esto la demo enseña un catálogo lleno de botones de «Instalar», que es
     * exactamente lo contrario de lo que se quiere mostrar: el cliente tiene que
     * ver las funciones funcionando sobre sus propias conversaciones, no la
     * promesa de que existen.
     *
     * Importa el orden respecto a `conversaciones()`: el semáforo sólo colorea
     * lo que llega después de encenderse, así que se instala primero y luego se
     * repinta la bandeja con `wa:semaforo-recalcular`, que es lo que hace el
     * comando al terminar.
     */
    private function extensiones(Company $company, User $admin): void
    {
        foreach (app(\App\Extensions\ExtensionRegistry::class)->all() as $extension) {
            $ajustes = $extension->defaultSettings();

            // El semáforo con IA encendido: el plan de la demo es Inteligente y
            // el flujo de n8n responde en un par de segundos. Es la diferencia
            // entre enseñar caritas de colores y enseñar por qué están puestas.
            if ($extension->slug() === 'sentiment_traffic_light') {
                $ajustes['usar_ia'] = true;
            }

            CompanyExtension::updateOrCreate(
                ['company_id' => $company->id, 'slug' => $extension->slug()],
                [
                    'enabled' => true,
                    'settings' => $ajustes,
                    'installed_by' => $admin->id,
                    'installed_at' => now(),
                ]
            );
        }
    }

    /** @return array{0: User, 1: array<int, User>} */
    private function personas(Company $company, string $slug): array
    {
        setPermissionsTeamId($company->id);

        $permisos = [
            'users', 'roles', 'instances', 'chat', 'crm', 'quick_replies',
            'auto_responses', 'campaigns', 'reports', 'templates', 'integrations',
        ];

        $todos = [];
        foreach ($permisos as $recurso) {
            foreach (['view', 'create', 'update', 'delete'] as $accion) {
                $todos[] = "{$recurso}.{$accion}";
            }
        }
        $todos[] = 'notifications.send';

        foreach ($todos as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => 'web']);
        }

        $rolAdmin = Role::firstOrCreate(['name' => 'admin', 'company_id' => $company->id, 'guard_name' => 'web']);
        $rolAdmin->syncPermissions($todos);

        $rolAgente = Role::firstOrCreate(['name' => 'agent', 'company_id' => $company->id, 'guard_name' => 'web']);
        $rolAgente->syncPermissions(['chat.view', 'chat.create', 'chat.update', 'crm.view', 'crm.update']);

        $clave = bcrypt((string) $this->option('password'));

        $admin = User::create([
            'name' => 'Dirección Cootramed',
            'email' => "admin@{$slug}.demo",
            'password' => $clave,
            'company_id' => $company->id,
            'active' => true,
            'role' => 'admin',
        ]);
        $admin->assignRole($rolAdmin);

        $agentes = [];
        foreach ([['Ana Restrepo', 'ana'], ['Carlos Múnera', 'carlos'], ['Lucía Zapata', 'lucia']] as [$nombre, $usuario]) {
            $agente = User::create([
                'name' => $nombre,
                'email' => "{$usuario}@{$slug}.demo",
                'password' => $clave,
                'company_id' => $company->id,
                'active' => true,
                'role' => 'agent',
            ]);
            $agente->assignRole($rolAgente);
            $agentes[] = $agente;
        }

        return [$admin, $agentes];
    }

    /** @return array<string, Tag> */
    private function etiquetas(Company $company): array
    {
        $definicion = [
            'Crédito' => '#7c3aed',
            'Ahorro' => '#0891b2',
            'PQRSF' => '#dc2626',
            'Cartera' => '#ea580c',
            'Asociación' => '#16a34a',
        ];

        $etiquetas = [];
        foreach ($definicion as $nombre => $color) {
            $etiquetas[$nombre] = Tag::create([
                'company_id' => $company->id,
                'name' => $nombre,
                'color' => $color,
            ]);
        }

        return $etiquetas;
    }

    /** @param array<string, Tag> $etiquetas */
    private function kanban(Company $company, array $etiquetas): void
    {
        $columnas = [
            ['Por atender', '#64748b', null],
            ['En gestión', '#0891b2', 'Crédito'],
            ['Esperando al socio', '#ea580c', 'Cartera'],
            ['Resuelto', '#16a34a', null],
        ];

        foreach ($columnas as $i => [$nombre, $color, $etiqueta]) {
            KanbanColumn::create([
                'company_id' => $company->id,
                'tag_id' => $etiqueta ? $etiquetas[$etiqueta]->id : null,
                'name' => $nombre,
                'color' => $color,
                'position' => $i,
            ]);
        }
    }

    private function respuestasRapidas(Company $company): void
    {
        $respuestas = [
            '/horario' => 'Nuestro horario de atención es de lunes a viernes de 8:00 a.m. a 5:00 p.m. y sábados de 8:00 a.m. a 12:00 m.',
            '/requisitos' => "Para asociarte necesitas:\n• Cédula ampliada al 150%\n• Certificado laboral no mayor a 30 días\n• Últimos 3 desprendibles de pago\n• Aporte inicial",
            '/pse' => 'Puedes pagar en línea por Redcoopagos PSE desde nuestra página, o acercarte a cualquiera de nuestras agencias.',
            '/sedes' => 'Estamos en Medellín (C.C. Sandiego), Caucasia, Tarso, Chigorodó y Arboletes, y tenemos extensiones de caja en Alpujarra y La Pintada.',
            '/pqrsf' => 'Tu PQRSF quedó radicada. Tenemos 15 días hábiles para responderte y te avisaremos por este mismo medio.',
        ];

        foreach ($respuestas as $atajo => $mensaje) {
            QuickReply::create([
                'company_id' => $company->id,
                'shortcut' => $atajo,
                'message' => $mensaje,
            ]);
        }
    }

    private function macros(Company $company): void
    {
        Macro::create([
            'company_id' => $company->id,
            'name' => 'Cerrar como resuelto',
            'active' => true,
            'actions' => [
                ['type' => 'reply', 'value' => '¿Te ayudo con algo más? Si no, cierro tu solicitud. ¡Gracias por escribirnos!'],
                ['type' => 'close'],
            ],
        ]);

        Macro::create([
            'company_id' => $company->id,
            'name' => 'Derivar a cartera',
            'active' => true,
            'actions' => [
                ['type' => 'tag', 'value' => 'Cartera'],
                ['type' => 'reply', 'value' => 'Voy a pasar tu caso al área de cartera. Te contactan hoy mismo.'],
            ],
        ]);
    }

    private function horario(Company $company, Instance $instance): void
    {
        $laboral = ['inicio' => '08:00', 'fin' => '17:00', 'activo' => true];

        BusinessHour::create([
            'company_id' => $company->id,
            'instance_id' => $instance->id,
            'name' => 'Horario de agencias',
            'active' => true,
            'timezone' => 'America/Bogota',
            'schedule_days' => [
                'mon' => $laboral, 'tue' => $laboral, 'wed' => $laboral,
                'thu' => $laboral, 'fri' => $laboral,
                'sat' => ['inicio' => '08:00', 'fin' => '12:00', 'activo' => true],
                'sun' => ['activo' => false],
            ],
            'out_of_hours_message' => 'Gracias por escribirnos. En este momento estamos fuera de horario; '
                .'te respondemos el siguiente día hábil desde las 8:00 a.m. '
                .'Si es una urgencia con tu tarjeta, llama al 018000 413131.',
            'cooldown_minutes' => 120,
        ]);
    }

    private function autorespuesta(Company $company, Instance $instance): void
    {
        AutoResponse::create([
            'company_id' => $company->id,
            'instance_id' => $instance->id,
            'name' => 'Saludo de bienvenida',
            'trigger_text' => 'hola, buenas, buenos días, buenas tardes',
            'match_type' => 'contains',
            'response_message' => '¡Hola! Soy el asistente de Cootramed. Cuéntame en qué te ayudo '
                .'y, si prefieres, escribe *menú* para ver las opciones.',
            'active' => true,
            'cooldown_minutes' => 240,
        ]);
    }

    private function menus(Company $company, Instance $instance): void
    {
        // Toda empresa nueva nace con un menú por defecto, cortesía de
        // CompanyObserver. Para la demo estorba: dos menús raíz compitiendo por
        // la misma palabra hacen que el bot conteste cualquier cosa, y lo que
        // se quiere enseñar es el de la cooperativa.
        $previos = WhatsAppMenu::where('company_id', $company->id)->pluck('id');
        WhatsAppMenuOption::whereIn('menu_id', $previos)->delete();
        WhatsAppMenu::whereIn('id', $previos)->delete();

        $raiz = WhatsAppMenu::create([
            'company_id' => $company->id,
            'instance_id' => $instance->id,
            'name' => 'Menú principal',
            'header_text' => 'Cootramed',
            'body_text' => "Hola 👋 Soy el asistente de tu cooperativa.\n\n¿Con qué te ayudo hoy?",
            'footer_text' => 'Una cooperativa para ti',
            'list_button_text' => 'Ver opciones',
            'is_root' => true,
            'trigger_text' => 'menu, menú, opciones, ayuda',
            'match_types' => ['contains'],
            'active' => true,
            'cooldown_minutes' => 5,
        ]);

        $credito = WhatsAppMenu::create([
            'company_id' => $company->id,
            'instance_id' => $instance->id,
            'name' => 'Crédito',
            'header_text' => 'Crédito',
            'body_text' => '¿Qué necesitas saber sobre tu crédito?',
            'list_button_text' => 'Ver opciones',
            'is_root' => false,
            'active' => true,
        ]);

        $opciones = [
            [$raiz, 0, 'Mi crédito', 'Cuota, saldo y certificados', 'submenu', null, $credito->id],
            [$raiz, 1, 'Mis ahorros', 'Saldo y productos de ahorro', 'reply_text',
                "Para consultar el saldo de tus ahorros entra a Portal Natural con tu cédula.\n\n".
                'Si no lo tienes activo, un asesor te ayuda a habilitarlo.', null],
            [$raiz, 2, 'Quiero asociarme', 'Requisitos y proceso', 'reply_text',
                "¡Qué bueno tenerte! Para asociarte necesitas:\n\n".
                "• Cédula ampliada al 150%\n• Certificado laboral reciente\n".
                "• Últimos 3 desprendibles de pago\n• Aporte inicial\n\n".
                'Puedes iniciar en cualquiera de nuestras agencias.', null],
            [$raiz, 3, 'Horarios y sedes', 'Dónde y cuándo atendemos', 'reply_text',
                "Atendemos de lunes a viernes de 8:00 a.m. a 5:00 p.m. y sábados hasta el mediodía.\n\n".
                "📍 Medellín · C.C. Sandiego\n📍 Caucasia\n📍 Tarso\n📍 Chigorodó\n📍 Arboletes\n".
                '📍 Extensiones de caja: Alpujarra y La Pintada', null],
            [$raiz, 4, 'Radicar una PQRSF', 'Petición, queja, reclamo o sugerencia', 'handoff',
                'Cuéntame con detalle tu solicitud y la radico ahora mismo. '.
                'Tenemos 15 días hábiles para responderte.', null],
            [$raiz, 5, 'Hablar con un asesor', 'Te paso con una persona', 'handoff',
                'Te comunico con un asesor. En horario de atención responde en pocos minutos.', null],

            [$credito, 0, 'Cuota del mes', 'Cuánto y cuándo pagar', 'reply_text',
                'Para conocer el valor exacto de tu cuota entra a Portal Natural, o dime tu número de cédula '.
                'y un asesor te lo confirma.', null],
            [$credito, 1, 'Pagar en línea', 'Redcoopagos PSE', 'reply_text',
                'Puedes pagar por Redcoopagos PSE desde nuestra página web, o en cualquier agencia y corresponsal solidario.', null],
            [$credito, 2, 'Certificado de crédito', 'Para trámites y declaraciones', 'handoff',
                'Con gusto. Dime tu número de cédula y te lo hacemos llegar por este medio.', null],
            [$credito, 3, 'Volver', 'Menú principal', 'submenu', null, $raiz->id],
        ];

        foreach ($opciones as [$menu, $posicion, $titulo, $descripcion, $accion, $texto, $destino]) {
            WhatsAppMenuOption::create([
                'menu_id' => $menu->id,
                'position' => $posicion,
                'title' => $titulo,
                'description' => $descripcion,
                'action_type' => $accion,
                'reply_text' => $texto,
                'target_menu_id' => $destino,
            ]);
        }
    }

    /** @return array<int, Contact> */
    private function socios(Company $company): array
    {
        $nombres = [
            ['Beatriz Elena Ospina', '3104458821', '43256789', 'Caucasia'],
            ['Jhon Fredy Cardona', '3125589043', '71234567', 'Medellín'],
            ['María Eugenia Zapata', '3013347765', '32115498', 'Tarso'],
            ['Wilson Alberto Úsuga', '3156672290', '98765432', 'Chigorodó'],
            ['Luz Dary Montoya', '3202215567', '43998877', 'Arboletes'],
            ['Óscar Iván Bedoya', '3117789043', '70998123', 'Medellín'],
            ['Gloria Patricia Ríos', '3184456702', '39887654', 'La Pintada'],
            ['Héctor Mario Gallego', '3009987231', '71556644', 'Medellín'],
            ['Yuliana Andrea Correa', '3145523398', '1037889912', 'Caucasia'],
            ['Ramiro Antonio Pérez', '3167784412', '8320114', 'Tarso'],
        ];

        $socios = [];
        foreach ($nombres as [$nombre, $telefono, $cedula, $sede]) {
            $socios[] = Contact::create([
                'company_id' => $company->id,
                'name' => $nombre,
                'phone_number' => '57'.$telefono,
                'identificacion' => $cedula,
                'source' => 'demo',
                'notes' => "Asociado de la agencia de {$sede}.",
                'metadata' => ['agencia' => $sede],
            ]);
        }

        return $socios;
    }

    /**
     * Las conversaciones son el corazón de la demo.
     *
     * Se escriben a mano y no al azar: quien mira la pantalla tiene que
     * reconocer las preguntas que de verdad le hacen sus socios.
     *
     * @param  array<int, Contact>  $socios
     * @param  array<int, User>  $agentes
     * @param  array<string, Tag>  $etiquetas
     */
    private function conversaciones(Instance $instance, array $socios, array $agentes, array $etiquetas): void
    {
        $guiones = [
            [
                'socio' => 0, 'agente' => 0, 'estado' => 'open', 'etiqueta' => 'Cartera', 'minutos' => 14,
                'mensajes' => [
                    ['in', 'Buenas tardes, quiero saber cuánto tengo que pagar este mes de la cuota', 180],
                    ['out', 'Buenas tardes Beatriz, con gusto. ¿Me confirmas tu número de cédula?', 176],
                    ['in', '43256789', 170],
                    ['out', "Gracias. Tu cuota de septiembre es de $312.400 y vence el 15.\n\nPuedes pagarla por Redcoopagos PSE o en la agencia de Caucasia.", 162],
                    ['in', 'Perfecto, muchas gracias 🙏', 158],
                ],
            ],
            [
                'socio' => 1, 'agente' => null, 'estado' => 'open', 'etiqueta' => 'PQRSF', 'minutos' => 3,
                'mensajes' => [
                    ['in', 'Buenos días', 45],
                    ['out', '¡Hola! Soy el asistente de Cootramed. Cuéntame en qué te ayudo y, si prefieres, escribe *menú* para ver las opciones.', 45],
                    ['in', 'Quiero poner una queja, llevo tres semanas esperando un certificado', 40],
                    ['out', 'Lamento la demora, Jhon Fredy. Cuéntame con detalle tu solicitud y la radico ahora mismo. Tenemos 15 días hábiles para responderte.', 39],
                    ['in', 'Pedí el certificado de crédito el 18 de agosto en la agencia de Medellín y todavía nada', 35],
                ],
            ],
            [
                'socio' => 2, 'agente' => 1, 'estado' => 'closed', 'etiqueta' => 'Asociación', 'minutos' => 0,
                'mensajes' => [
                    ['in', 'Hola, mi hija quiere asociarse, ella tiene 16 años, ¿se puede?', 1450],
                    ['out', "Hola María Eugenia. Sí se puede: desde los 14 años, con autorización del representante legal.\n\nNecesita cédula o tarjeta de identidad, y el aporte inicial.", 1442],
                    ['in', 'Ah qué bueno, ¿y en Tarso la pueden atender?', 1438],
                    ['out', 'Claro que sí, en la agencia de Tarso la atendemos de lunes a viernes de 8 a 5 y sábados hasta el mediodía.', 1435],
                    ['in', 'Muchas gracias, allá vamos el sábado', 1430],
                    ['out', '¡Los esperamos! ¿Te ayudo con algo más? Si no, cierro tu solicitud. ¡Gracias por escribirnos!', 1428],
                ],
            ],
            [
                'socio' => 3, 'agente' => null, 'estado' => 'open', 'etiqueta' => 'Crédito', 'minutos' => 52,
                'mensajes' => [
                    ['in', 'Buenas, necesito un crédito para arreglar la casa, ¿qué requisitos piden?', 60],
                    ['out', "Hola Wilson. Para crédito por libranza necesitas certificado laboral reciente y los últimos 3 desprendibles.\n\n¿Trabajas con empresa que tenga convenio con nosotros?", 55],
                    ['in', 'Sí, en la alcaldía de Chigorodó', 52],
                ],
            ],
            [
                'socio' => 4, 'agente' => 2, 'estado' => 'open', 'etiqueta' => 'Ahorro', 'minutos' => 8,
                'mensajes' => [
                    ['in', 'Quiero abrir un CDAT, ¿qué tasa están dando a 180 días?', 95],
                    ['out', 'Hola Luz Dary. Con gusto te paso las tasas vigentes. ¿Qué monto tienes pensado?', 90],
                    ['in', 'Unos 12 millones', 86],
                    ['out', 'Perfecto, para ese monto a 180 días te puedo ofrecer una tasa preferencial. Te paso la simulación en un momento.', 84],
                ],
            ],

            // ── Los tres hilos largos ───────────────────────────────────────
            //
            // Los cinco de arriba tienen entre 3 y 6 mensajes, que es lo normal
            // en una bandeja real pero deja fuera la función que más vende: el
            // botón «Resumir» sólo aparece a partir de 8 mensajes, a propósito
            // —en un hilo de tres estorba más de lo que ayuda—.
            //
            // Estos tres están escritos para que el resumen tenga algo que
            // decir: en los tres se le PROMETE algo al socio y en los tres
            // queda algo pendiente. Un hilo largo donde no pasa nada produce un
            // resumen correcto y aburrido, que es la peor demostración posible.
            //
            // Y llevan emoción distinta a propósito, para que el semáforo se
            // vea en sus tres colores sobre datos de verdad: Óscar acaba
            // molesto, Gloria acaba contenta, Héctor va neutro.
            [
                'socio' => 5, 'agente' => 0, 'estado' => 'open', 'etiqueta' => 'PQRSF', 'minutos' => 6,
                'mensajes' => [
                    ['in', 'Buenos días, necesito ayuda con un descuento que me hicieron mal en la nómina', 240],
                    ['out', 'Buenos días Óscar Iván. Con gusto lo reviso. ¿Me confirmas tu cédula y de qué mes hablamos?', 236],
                    ['in', '70998123, el descuento de agosto', 232],
                    ['out', 'Gracias. Veo un descuento de $486.200 por libranza en agosto. ¿Cuál es el valor que esperabas?', 225],
                    ['in', 'La cuota mía es de 312 mil, no 486. Me descontaron 174 mil de más', 220],
                    ['out', 'Tienes razón, la diferencia está. Voy a pedir la revisión al área de cartera hoy mismo.', 214],
                    ['in', 'Es que ya van dos meses seguidos con lo mismo, en julio también pasó', 208],
                    ['out', 'Entiendo la molestia y me disculpo. Radico la solicitud como reclamo formal para que quede el soporte.', 200],
                    ['in', 'Por favor, porque necesito ese dinero. Tengo el arriendo pendiente', 196],
                    ['out', "Radicado con el número PQR-4471. El área de cartera responde en máximo 5 días hábiles.\n\nSi procede la devolución, se abona a tu cuenta de ahorros.", 188],
                    ['in', 'Cinco días es mucho, yo necesito saber ya si me lo devuelven', 182],
                    ['out', 'Voy a marcarlo como prioritario y te escribo por acá apenas cartera me confirme. No tienes que volver a llamar.', 175],
                    ['in', 'Bueno, quedo pendiente entonces. Pero si en julio también pasó, ¿no van a revisar ese también?', 168],
                    ['out', 'Sí, pido la revisión de los dos meses en el mismo radicado. Te confirmo el total apenas lo tenga.', 160],
                    ['in', 'Listo, gracias. Espero que esta vez sí se solucione', 155],
                ],
            ],
            [
                'socio' => 6, 'agente' => 2, 'estado' => 'open', 'etiqueta' => 'Crédito', 'minutos' => 21,
                'mensajes' => [
                    ['in', 'Buenas tardes, quiero pedir un crédito para la universidad de mi hijo', 320],
                    ['out', 'Buenas tardes Gloria Patricia. ¡Qué buena noticia! ¿Ya tienes el valor de la matrícula?', 315],
                    ['in', 'Son 4 millones 800 por semestre, en la de Medellín', 310],
                    ['out', '¿Trabajas con alguna entidad que tenga convenio de libranza con nosotros?', 305],
                    ['in', 'Sí, en el hospital de La Pintada, llevo 9 años', 300],
                    ['out', "Perfecto, ahí sí aplica libranza, que es la mejor tasa que tenemos: 1,1% mensual.\n\nA 24 meses la cuota quedaría en unos $228.000.", 292],
                    ['in', '¿Y eso incluye el seguro?', 288],
                    ['out', 'Sí, incluye seguro de vida deudores. No hay costos adicionales ni estudio de crédito.', 283],
                    ['in', 'Me sirve mucho. ¿Qué papeles necesito llevar?', 278],
                    ['out', "Certificado laboral no mayor a 30 días, los últimos 3 desprendibles y cédula ampliada al 150%.\n\nEn la agencia de La Pintada te reciben todo.", 270],
                    ['in', 'Los desprendibles los tengo en PDF, ¿sirven impresos?', 265],
                    ['out', 'Sí, impresos sirven perfectamente. También los puedes enviar por acá y adelantamos el estudio.', 258],
                    ['in', 'Ah buenísimo, se los mando esta noche entonces', 252],
                    ['out', "Quedo atenta. Apenas los reciba te doy respuesta del preaprobado en 24 horas.\n\nMatrículas cierran el 30, así que vamos con tiempo.", 245],
                    ['in', 'Mil gracias, me quitas un peso de encima 🙏 Excelente atención de verdad', 240],
                    ['out', 'Con muchísimo gusto, Gloria. Para eso estamos. ¡Que le vaya muy bien a tu hijo!', 236],
                ],
            ],
            [
                'socio' => 7, 'agente' => null, 'estado' => 'open', 'etiqueta' => 'Ahorro', 'minutos' => 2,
                'mensajes' => [
                    ['in', 'Buenas, tengo una duda con el CDAT que abrí en marzo', 95],
                    ['out', '¡Hola! Soy el asistente de Cootramed. Cuéntame tu duda y te ayudo o te paso con un asesor.', 94],
                    ['in', 'Quiero saber cuándo se vence y si se renueva solo', 90],
                    ['out', 'Para consultar tu CDAT necesito pasarte con un asesor. ¿Me confirmas tu número de cédula?', 89],
                    ['in', '71556644', 85],
                    ['out', 'Gracias Héctor Mario. Un asesor toma tu caso en un momento.', 84],
                    ['in', 'Ok. Y de una vez, si lo renuevo, ¿me mantienen la misma tasa?', 78],
                    ['in', 'Es que la abrí al 9,2% y vi que ahora están dando menos', 76],
                    ['out', 'Buena pregunta. La renovación toma la tasa vigente del día, no la anterior. Un asesor te confirma la de hoy.', 70],
                    ['in', '¿Y si prefiero no renovar, en cuánto me consignan?', 65],
                    ['out', 'Al vencimiento se abona a tu cuenta de ahorros el mismo día, capital más rendimientos.', 60],
                    ['in', 'Perfecto. Entonces quedo esperando que me digan la fecha exacta y la tasa', 55],
                ],
            ],
        ];

        foreach ($guiones as $guion) {
            $socio = $socios[$guion['socio']];
            $agente = $guion['agente'] !== null ? $agentes[$guion['agente']] : null;

            $conversacion = WhatsAppConversation::create([
                'instance_id' => $instance->id,
                'contact_id' => $socio->id,
                'wa_id' => $socio->phone_number,
                'phone_number' => $socio->phone_number,
                'name' => $socio->name,
                'status' => $guion['estado'],
                'assigned_to' => $agente?->id,
                'closed_by' => $guion['estado'] === 'closed' ? $agente?->id : null,
                'closed_at' => $guion['estado'] === 'closed' ? now()->subMinutes(1428) : null,
                'unread_count' => $guion['minutos'] > 0 && ! $agente ? 1 : 0,
                'last_message_at' => now()->subMinutes($guion['minutos'] ?: 1428),
            ]);

            $ultimo = '';
            foreach ($guion['mensajes'] as [$direccion, $texto, $haceMinutos]) {
                $cuando = now()->subMinutes($haceMinutos);

                $mensaje = WhatsAppMessage::create([
                    'conversation_id' => $conversacion->id,
                    'wamid' => 'wamid.demo.'.Str::random(16),
                    'type' => 'text',
                    'content' => $texto,
                    'direction' => $direccion === 'in' ? 'inbound' : 'outbound',
                    'status' => $direccion === 'in' ? 'delivered' : 'read',
                    'sent_by' => $direccion === 'out' ? $agente?->id : null,
                    'sent_at' => $cuando,
                ]);

                // `created_at` no es asignable en masa: sin esto todos los
                // mensajes nacerían con la hora de ahora y el hilo se leería
                // como si hubiera pasado en un segundo.
                WhatsAppMessage::whereKey($mensaje->id)->update(['created_at' => $cuando]);
                $ultimo = $texto;
            }

            $conversacion->update(['last_message' => Str::limit($ultimo, 120)]);
            $conversacion->tags()->attach($etiquetas[$guion['etiqueta']]->id);
        }
    }

    /**
     * Una campaña ya terminada, con resultados repartidos.
     *
     * Sin esto la pantalla de campañas está vacía y hay que explicar con las
     * manos lo que se ve solo: cuántos llegaron, cuántos se leyeron y por qué
     * falló el que falló.
     *
     * @param  array<int, Contact>  $socios
     */
    private function campana(Company $company, Instance $instance, User $admin, array $socios): void
    {
        $campana = WhatsAppCampaign::create([
            'company_id' => $company->id,
            'instance_id' => $instance->id,
            'created_by' => $admin->id,
            'name' => 'Recordatorio de cuota · septiembre',
            'message' => null,
            'message_type' => 'template',
            'template_name' => 'recordatorio_cuota',
            'template_language' => 'es',
            'template_components' => [[
                'type' => 'BODY',
                'text' => 'Hola {{1}}, te recordamos que tu cuota de {{2}} vence el día 15. '
                    .'Puedes pagarla por Redcoopagos PSE o en cualquiera de nuestras agencias.',
            ]],
            'variable_map' => ['body' => [
                ['source' => 'field', 'field' => 'name'],
                ['source' => 'fixed', 'value' => 'septiembre'],
            ]],
            'rate_per_minute' => 60,
            'status' => 'completed',
            'schedule_type' => 'manual',
            'total_recipients' => count($socios),
            'started_at' => now()->subDays(4)->setTime(9, 0),
            'completed_at' => now()->subDays(4)->setTime(9, 12),
        ]);

        // Resultados repartidos como en una campaña de verdad: la mayoría
        // entregada, varias leídas, y un número que ya no existe.
        $estados = ['read', 'read', 'read', 'delivered', 'delivered', 'delivered', 'read', 'delivered', 'sent', 'failed'];

        foreach ($socios as $i => $socio) {
            $estado = $estados[$i] ?? 'delivered';
            $cuando = now()->subDays(4)->setTime(9, 0)->addSeconds($i * 6);

            WhatsAppCampaignRecipient::create([
                'campaign_id' => $campana->id,
                'contact_id' => $socio->id,
                'phone_number' => $socio->phone_number,
                'name' => $socio->name,
                'variables' => ['identificacion' => $socio->identificacion],
                'status' => $estado,
                'wamid' => $estado === 'failed' ? null : 'wamid.demo.'.Str::random(14),
                'sent_at' => $estado === 'failed' ? null : $cuando,
                'delivered_at' => in_array($estado, ['delivered', 'read'], true) ? $cuando->copy()->addSeconds(4) : null,
                'read_at' => $estado === 'read' ? $cuando->copy()->addMinutes(random_int(3, 90)) : null,
                'error_code' => $estado === 'failed' ? '131026' : null,
                'error_message' => $estado === 'failed' ? 'El número no tiene WhatsApp o dejó de existir.' : null,
                'attempts' => 1,
            ]);
        }

        $campana->refreshCounters();
    }

    private function borrar(string $slug): void
    {
        $company = Company::where('slug', $slug)->first();

        if (! $company) {
            return;
        }

        DB::transaction(function () use ($company) {
            // Las conversaciones cuelgan de la instancia y los mensajes de la
            // conversación: se borran de dentro hacia fuera porque no hay
            // borrado en cascada para todo.
            $instancias = Instance::where('company_id', $company->id)->pluck('id');
            $conversaciones = WhatsAppConversation::whereIn('instance_id', $instancias)->pluck('id');

            WhatsAppMessage::whereIn('conversation_id', $conversaciones)->delete();

            // `whatsapp_conversation_id`, no `conversation_id`: la pivote lleva
            // el nombre largo del modelo. Con el nombre corto, `--rehacer`
            // reventaba con un «Unknown column» en cuanto la demo tenía alguna
            // conversación etiquetada — o sea, siempre.
            DB::table('whatsapp_conversation_tag')
                ->whereIn('whatsapp_conversation_id', $conversaciones)
                ->delete();

            WhatsAppConversation::whereIn('id', $conversaciones)->delete();

            $campanas = WhatsAppCampaign::where('company_id', $company->id)->pluck('id');
            WhatsAppCampaignRecipient::whereIn('campaign_id', $campanas)->delete();
            WhatsAppCampaign::whereIn('id', $campanas)->delete();

            $menus = WhatsAppMenu::where('company_id', $company->id)->pluck('id');
            WhatsAppMenuOption::whereIn('menu_id', $menus)->delete();
            WhatsAppMenu::whereIn('id', $menus)->delete();

            // Desde que la demo instala las cinco extensiones, estas filas
            // quedaban apuntando a una empresa borrada.
            CompanyExtension::where('company_id', $company->id)->delete();
            DB::table('company_ai_usage')->where('company_id', $company->id)->delete();
            DB::table('conversation_sentiment_events')->where('company_id', $company->id)->delete();

            AutoResponse::where('company_id', $company->id)->delete();
            BusinessHour::where('company_id', $company->id)->delete();
            QuickReply::where('company_id', $company->id)->delete();
            Macro::where('company_id', $company->id)->delete();
            KanbanColumn::where('company_id', $company->id)->delete();
            Tag::where('company_id', $company->id)->delete();
            Contact::where('company_id', $company->id)->delete();
            Instance::where('company_id', $company->id)->delete();

            Role::where('company_id', $company->id)->delete();
            User::where('company_id', $company->id)->delete();

            $company->delete();
        });

        $this->warn("Empresa de demostración «{$slug}» borrada.");
    }
}
