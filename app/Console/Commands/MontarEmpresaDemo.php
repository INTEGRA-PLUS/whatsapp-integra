<?php

namespace App\Console\Commands;

use App\Models\AutoResponse;
use App\Models\BusinessHour;
use App\Models\Company;
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

        $this->newLine();
        $this->info('Empresa de demostración lista.');
        $this->table(['Dato', 'Valor'], [
            ['Empresa', $company->name],
            ['Entrar como', "admin@{$slug}.demo"],
            ['Contraseña', (string) $this->option('password')],
            ['Agentes', 'ana@, carlos@, lucia@ '."{$slug}.demo"],
        ]);
        $this->newLine();
        $this->warn('La línea de WhatsApp está inactiva y con credenciales falsas: la demo no puede enviar mensajes.');

        return self::SUCCESS;
    }

    private function montar(string $slug): Company
    {
        $company = Company::create([
            'name' => (string) $this->option('nombre'),
            'slug' => $slug,
            'active' => true,
        ]);

        // Inactiva y sin token real. Si algún día alguien la activa por error,
        // `isMetaConfigured()` sigue siendo falso y no sale nada.
        $instance = Instance::create([
            'company_id' => $company->id,
            'uuid' => (string) Str::uuid(),
            'name' => 'Línea de asociados',
            'phone_number_id' => 'DEMO-'.Str::random(12),
            'waba_id' => 'DEMO-'.Str::random(10),
            'display_phone_number' => '+57 312 602 1105',
            'type' => 'meta',
            'status' => 'active',
            'active' => false,
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

        $socios = $this->socios($company);
        $this->conversaciones($instance, $socios, $agentes, $etiquetas);
        $this->campana($company, $instance, $admin, $socios);

        return $company;
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
            DB::table('whatsapp_conversation_tag')->whereIn('conversation_id', $conversaciones)->delete();
            WhatsAppConversation::whereIn('id', $conversaciones)->delete();

            $campanas = WhatsAppCampaign::where('company_id', $company->id)->pluck('id');
            WhatsAppCampaignRecipient::whereIn('campaign_id', $campanas)->delete();
            WhatsAppCampaign::whereIn('id', $campanas)->delete();

            $menus = WhatsAppMenu::where('company_id', $company->id)->pluck('id');
            WhatsAppMenuOption::whereIn('menu_id', $menus)->delete();
            WhatsAppMenu::whereIn('id', $menus)->delete();

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
