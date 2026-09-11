# Extensiones

Plan del módulo de Extensiones: un catálogo interno, al estilo de la tienda de
extensiones de Chrome, donde cada empresa instala, enciende y configura piezas de
comportamiento que hoy habría que pedirle a desarrollo.

## Por qué un módulo y no otra pantalla de ajustes

El CRM ya tiene cinco sitios donde se configura "qué hace el sistema solo":
menús, respuestas automáticas, horario de atención, macros y la IA. Cada uno
nació como su propia tabla, su propio controlador y su propia pantalla. Todas
comparten exactamente la misma forma —*una empresa enciende algo, lo configura y
el sistema lo ejecuta en un momento del ciclo de vida de un mensaje*— y ninguna
la comparte de verdad.

El coste real no es la duplicación: es que **añadir un comportamiento nuevo
cuesta una migración, un modelo, un controlador, una ruta, un permiso y una
pantalla**. Una funcionalidad que sólo le sirve a tres empresas de cuarenta no
llega a escribirse nunca, porque no compensa.

Las extensiones invierten eso. El catálogo vive en código (una clase por
extensión), lo único que va a base de datos es el estado por empresa, y las
pantallas son **una sola**, generada a partir del manifiesto. Añadir una
extensión nueva es escribir una clase y registrarla en `config/extensions.php`.

## Arquitectura

### Catálogo en código, estado en base de datos

```
CATÁLOGO (código, igual para toda la plataforma)     ESTADO (BD, por empresa)
┌──────────────────────────────────────────┐        ┌────────────────────────┐
│ config/extensions.php                    │        │ company_extensions     │
│   → App\Extensions\FollowUpExtension     │◀──────▶│  company_id            │
│   → App\Extensions\KeywordRoutingExt…    │ slug   │  slug                  │
│   → App\Extensions\AgentSignatureExt…    │        │  enabled               │
│                                          │        │  settings (json)       │
│ cada clase declara: slug, nombre,         │        │  installed_by / at     │
│ descripción, icono, categoría, permisos,  │        └────────────────────────┘
│ esquema de ajustes y en qué hooks entra   │
└──────────────────────────────────────────┘
```

El catálogo **no** va a base de datos. Una extensión es código ejecutable: su
comportamiento, su validación y sus ajustes viven en la misma clase, y que un
registro de BD pueda quedar desincronizado de la clase que dice ejecutar es una
fuente de fallos sin contrapartida. Un catálogo en BD además obligaría a una
migración por cada extensión nueva y a sembrarla en las cuarenta empresas.

La fila de `company_extensions` sólo existe cuando la empresa **instala**. "No
instalada" no es una fila con `installed = false`: es la ausencia de fila. Así el
catálogo puede crecer sin sembrar nada, y desinstalar es un `delete` que se lleva
los ajustes con él.

### Tabla

```sql
company_extensions
  id
  company_id      FK companies, cascade
  slug            string(60)          -- clave del catálogo, NO FK: el catálogo es código
  enabled         boolean default true
  settings        json nullable       -- ajustes ya saneados por la extensión
  installed_by    FK users nullOnDelete
  installed_at    timestamp
  timestamps
  UNIQUE (company_id, slug)
  INDEX (slug, enabled)               -- para "todas las empresas con X encendida"
```

`slug` es un string y no una clave foránea a propósito: el otro extremo es una
clase PHP. Una fila cuyo slug ya no está en el catálogo (extensión retirada) se
ignora al resolver, no rompe nada, y se puede limpiar cuando convenga.

Separar `enabled` de la existencia de la fila importa: **instalar y encender son
decisiones distintas**. Una extensión se instala, se configura con calma y se
enciende cuando los ajustes están listos; apagarla temporalmente no debe borrar
la configuración que costó media hora.

### Contrato de una extensión

Clase abstracta `App\Extensions\Extension`. El manifiesto son métodos, no un
array, porque las descripciones y las opciones de los ajustes dependen de datos
de la empresa (etiquetas, agentes) y un array estático no puede resolverlos.

| Método | Qué declara |
| --- | --- |
| `slug()` | Clave estable. Es lo que se guarda en BD: no se cambia nunca. |
| `name()` / `description()` | Lo que se lee en la tarjeta del catálogo. |
| `detail()` | Texto largo del detalle: qué hace, cuándo se dispara, qué NO hace. |
| `icon()` | Nombre de un icono de `lucide-react`. El front lo resuelve por mapa. |
| `category()` | `conversaciones` / `productividad` / `automatizacion`. Agrupa el catálogo. |
| `permissions()` | Permisos de spatie que la extensión **pide** para trabajar. Se muestran en el detalle antes de instalar, como los permisos de una extensión de Chrome. |
| `settingsSchema()` | Campos configurables (ver abajo). |
| `defaultSettings()` | Con qué ajustes nace al instalar. |
| `sanitizeSettings()` | Valida y limpia lo que llega del formulario, comprobando que toda referencia (etiqueta, agente) sea de la empresa. |
| `hooks()` | Etiquetas legibles de dónde se engancha, para el detalle. |

Los ganchos son **interfaces opcionales**, no métodos del contrato base: una
extensión implementa sólo los que usa, y el runner pregunta con `instanceof`.

| Interfaz | Método | Cuándo corre |
| --- | --- | --- |
| `HandlesInboundMessage` | `onInboundMessage()` | Justo después de guardar un mensaje entrante, antes de las respuestas automáticas. |
| `FiltersOutboundText` | `filterOutboundText()` | En `DeliverWhatsAppMessage`, sobre el texto que sale hacia Meta. |
| `RunsOnSchedule` | `runScheduled()` | Cada 5 minutos, desde `extensions:run`. |

### Esquema de ajustes

Un campo del esquema es un array declarativo que el frontend sabe pintar solo:

```php
['key' => 'minutes', 'type' => 'number', 'label' => '…', 'help' => '…', 'min' => 5, 'max' => 1440]
```

Tipos soportados: `text`, `textarea`, `number`, `boolean`, `select`, `multiselect`
y `rules` (el editor de reglas de palabra clave, que es lo bastante particular
como para tener su propio componente).

Los `select`/`multiselect` pueden traer `options` estáticas o declarar
`source: 'tags' | 'agents'`, que el controlador resuelve **filtrado por
`company_id`** al renderizar. Así el formulario no necesita saber nada del
dominio y una extensión nueva no toca el frontend.

### Resolución y ejecución

`ExtensionRegistry` (singleton) lee `config/extensions.php`, instancia cada clase
y las indexa por slug. `ExtensionRunner` es el único punto por el que se ejecuta
algo:

```php
Extensions::onInbound($conversation, $message);          // webhook
Extensions::filterOutboundText($text, $message);         // job de entrega
```

El runner resuelve la empresa, pide sólo las extensiones **instaladas y
encendidas** de esa empresa, y ejecuta cada una **envuelta en su propio
try/catch**. Una extensión que falla se registra en el log y no impide que corra
la siguiente ni tumba el mensaje del cliente, que es la regla que ya sigue el
webhook con las respuestas automáticas: los efectos secundarios no se llevan por
delante lo que ya se guardó.

### Aislamiento por empresa

- Toda fila de `company_extensions` lleva `company_id`.
- El controlador nunca acepta un `company_id` del request: sale de `auth()->user()`.
- `sanitizeSettings()` vuelve a comprobar en el servidor que cada etiqueta y cada
  agente referenciados sean de la empresa, aunque el `<select>` sólo ofreciera los
  suyos. El formulario es una sugerencia, no una garantía.
- El runner parte siempre de la conversación → instancia → `company_id`, nunca de
  la sesión: corre en colas y en comandos, donde no hay usuario.

## Permisos

Se añade `extensions` a la lista de módulos de `InitPermissionsSeeder`, que ya
genera `view/create/update/delete` por módulo. El mapeo respeta el patrón del
repo sin inventar acciones nuevas:

| Permiso | Acción |
| --- | --- |
| `extensions.view` | Ver el catálogo y el detalle. |
| `extensions.create` | Instalar. |
| `extensions.update` | Encender, apagar y guardar ajustes. |
| `extensions.delete` | Desinstalar. |

## Rutas

```
GET    /extensiones                       extensions.index    permission:extensions.view
GET    /extensiones/{slug}                extensions.show     permission:extensions.view
POST   /api/extensions/{slug}/install                         permission:extensions.create
DELETE /api/extensions/{slug}                                 permission:extensions.delete
POST   /api/extensions/{slug}/toggle                          permission:extensions.update
PUT    /api/extensions/{slug}/settings                        permission:extensions.update
```

## Pantallas

- **`Extensions/Index`** — catálogo en tarjetas, buscador y filtro por categoría,
  con las instaladas arriba. Cada tarjeta lleva icono, nombre, descripción corta,
  estado (instalada / encendida) e interruptor.
- **`Extensions/Show`** — detalle: qué hace, dónde se engancha, qué permisos pide,
  botones de instalar/desinstalar y encender/apagar, y el formulario de ajustes
  generado a partir del esquema.

Entrada nueva en la navegación lateral, grupo **Configuración**: "Extensiones"
(icono `Blocks`), visible con `extensions.view`.

## Las tres extensiones

Criterio: que resuelvan un dolor real de este CRM, que se puedan implementar de
verdad con lo que ya hay, y que **cada una estrene un tipo de gancho distinto**
—entrante, saliente y programado— para que el sistema quede probado por los tres
lados y no sólo por uno.

### 1. Seguimiento de conversaciones sin respuesta (`follow_up`) — gancho programado

Cada 5 minutos busca conversaciones **abiertas cuyo último mensaje es del
cliente** y lleva más de N minutos sin respuesta, y avisa por la campana al
agente asignado (o a los administradores si no hay nadie asignado). Opcional:
aplicar una etiqueta para que salten a la vista en el CRM.

Por qué: es el agujero más caro del producto. Hoy nada vigila que un chat se
quede sin contestar; si el agente asignado no mira la bandeja, el cliente se
queda esperando y nadie se entera. `AgentAssignmentService` reparte por carga,
pero una vez repartido no hay seguimiento. Se implementa entero con lo que ya
existe: la tabla de mensajes, `SystemNotification` y la campana.

Ajustes: minutos de espera, avisar sólo en horario de atención, a quién avisar
(agente asignado / administradores / ambos), etiqueta a aplicar, no repetir el
aviso antes de X minutos.

### 2. Enrutado por palabra clave (`keyword_routing`) — gancho entrante

Reglas *palabra clave → acción* sobre cada mensaje entrante: aplicar etiqueta,
asignar a un agente concreto o al menos cargado, y marcar la conversación como
prioritaria. La primera regla que casa manda.

Por qué: "garantía", "reclamo", "quiero comprar" y "cancelar" no deberían caer en
la misma bandeja indiferenciada. Es el enrutado que hoy se hace a ojo. Cubre
además la intención de compra —la candidata que pedía IA— sin depender del flujo
de n8n ni de Ollama, que es de otro equipo y hoy monopoliza la cola `default`.
Los menús de WhatsApp ya reconocen texto, pero **responden**; esto no responde:
clasifica y reparte, que es lo que falta.

Ajustes: lista de reglas (palabras separadas por coma, etiqueta, asignación,
prioridad), coincidencia por palabra completa o por contenido, aplicar sólo en el
primer mensaje del cliente o en todos.

### 3. Firma automática del agente (`agent_signature`) — gancho saliente

Antepone o añade una firma configurable al texto que sale hacia Meta, con
sustituciones `{agente}`, `{empresa}` y `{firma}`.

Por qué: hoy `DeliverWhatsAppMessage` antepone `*Nombre:*` a **todos** los
mensajes salientes de **todas** las empresas, con el formato fijo en el código.
No hay forma de quitarlo, de poner el cargo, de firmar al final ni de añadir la
razón social. Es una decisión de producto congelada en una línea de un job.
La extensión la saca de ahí sin cambiar el comportamiento de quien no la instale:
sin extensión, el texto sigue saliendo exactamente igual que hoy.

Ajustes: posición (antes / después), plantilla del texto, y si aplicar también a
los pies de foto y documento.

## Ganchos en archivos compartidos

Tres inserciones mínimas, sin reescribir nada:

| Archivo | Cambio |
| --- | --- |
| `WhatsAppWebhookController::processInboundMessage` | Una llamada a `Extensions::onInbound()` antes del bloque de respuestas automáticas. |
| `DeliverWhatsAppMessage::handle` | El prefijo fijo del agente pasa por `Extensions::filterOutboundText()`. |
| `routes/console.php` | `Schedule::command('extensions:run')->everyFiveMinutes()`. |
| `app-sidebar.jsx` | Una entrada en el grupo Configuración. |
| `InitPermissionsSeeder` | `'extensions'` en la lista de módulos. |

## Qué no entra

- **Extensiones de terceros.** El catálogo es código nuestro. Cargar clases de
  fuera implicaría aislamiento, versiones y firma de paquetes, y no hay ningún
  caso que lo pida.
- **Planes y facturación por extensión.** El módulo no sabe de precios; si algún
  día hay extensiones de pago, el gancho natural es el plan de la empresa.
- **Ganchos nuevos "por si acaso".** Los tres que hay son los que usan las tres
  extensiones. Un gancho sin consumidor es una interfaz que nadie ha probado.
