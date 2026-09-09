# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

Integra CRM: CRM de WhatsApp multi-tenant sobre la Cloud API de Meta. Laravel 12 + Inertia + React 19.
El proyecto se escribe y se documenta **en español**: código, commits y docs.

## La regla que lo condiciona todo: el aislamiento entre empresas es manual

**No hay ni un solo global scope, ni trait de tenant, ni middleware que filtre por empresa.** El aislamiento
son ~315 `where('company_id', ...)` escritos a mano en 30 controladores. Olvidarlo no lanza ningún error:
devuelve datos de otra empresa en silencio.

Diez modelos **no tienen `company_id`** y se aíslan indirectamente, saltando por la instancia:
`WhatsAppConversation`, `WhatsAppMessage` (dos saltos), `WhatsAppMenuOption`, `WhatsAppMenuSession`,
`WhatsAppBotFlow`, `WhatsAppCampaignRecipient`, `WhatsAppCall`, `WhatsAppCallPermission`,
`CoexistenceSync`, `WebhookDelivery`.

El patrón idiomático, obligatorio, es el de `app/Http/Controllers/ChatController.php:92-95`:

```php
$instanceIds = Instance::where('company_id', $user->company_id)->pluck('id');
WhatsAppMessage::whereIn('instance_id', $instanceIds)...
```

Consultar cualquiera de esos diez modelos sin ese preámbulo es una fuga entre empresas.

Detalles que se olvidan:
- El índice único de instancias es `(company_id, phone_number_id)`, **no `phone_number_id` solo**: dos empresas
  pueden reclamar el mismo número. `Api/MessageApiController.php:40-68` responde 409 `ambiguous_instance`.
- Roles con Spatie y **teams = `company_id`**: `hasRole('master')` devuelve `false` si no se llamó antes a
  `setPermissionsTeamId()` con la empresa correcta. `Gate::before` en `AppServiceProvider.php:28` da a `master`
  todos los permisos. No hay `app/Policies/`; la autorización va por middleware `permission:xxx` en las rutas.

## Modelos y datos

- **No todo cliente tiene número.** Desde que Meta permite ocultarlo, el contacto puede llegar como un BSUID
  (`"CO.1402615141764490"`, a veces con `ENT` intercalado). **Nunca leer `phone_number` a pelo para enviar**, y
  nunca pasar un BSUID por `normalizePhone()` — lo convierte en un número inexistente. Usar
  `normalizeRecipient()`, `recipientId()` y `hasPhone()` de `WhatsAppConversation`.
- **Crear conversaciones solo con `WhatsAppConversation::resolveFor()`** (`:132`). Sustituyó a un
  `firstOrCreate(['wa_id' => $phone])` repartido por media docena de sitios que partía en dos el hilo del mismo
  cliente según cómo estuviera escrito el número.
- **La ventana de 24 h se mide con `COALESCE(sent_at, created_at)`**, nunca `created_at` solo (`:375`): cuando
  Meta reintenta durante días y suelta la cola de golpe, `created_at` es de hoy y `sent_at` de hace tres.
- **`instances.meta` es un cajón compartido** (`calling`, `resume_template`, `fallback_template`,
  `verified_name`). Asignar `$instance->meta = [...]` a pelo borra la configuración de otras funciones: usar
  `mergeFallbackTemplate()`, `setCallingSettings()`, `setResumeTemplate()`.
- **Cero `SoftDeletes` en todo el proyecto**: los borrados son reales. Por eso borrar conversaciones pasa por
  `ConversationDeletionRequest` y su aprobación.
- `whatsapp_messages.wamid` es único y es la base de toda la idempotencia del sistema.
- Hay migraciones que son **de datos, no de esquema** (siembra de menús por defecto, reparación de mensajes).
  Revisarlas antes de asumir que una migración es reversible.

## Envíos por WhatsApp

Ver `@.claude/rules/envios-whatsapp.md` — reglas de Meta que fallan en silencio.

## Comandos

Dos flujos de desarrollo. **Nativo:**
- `composer dev` — serve + `queue:listen` + vite. **No levanta Reverb**: `php artisan reverb:start` aparte.
- `composer test` — hace `config:clear` antes de `artisan test`; sin eso el config cacheado se cuela en los tests.
  Los tests corren en sqlite `:memory:` con `QUEUE_CONNECTION=sync` (`phpunit.xml`).
- Un solo test: `php artisan test --filter=NombreDelTest`.

**Docker**, vía Makefile (`make help` los lista). Todos usan `docker compose --env-file .env.docker`:
- `make up` / `down` / `restart` / `logs` / `shell`
- `make artisan cmd="route:list"` — con esa sintaxis exacta, `cmd=`
- `make migrate`, `make fresh` (destructivo), `make cache-clear`, `make queue-restart`

`.env.docker` está en `.gitignore`: sin copiarlo de `.env.docker.example` ningún `make` funciona.

No hay linter ni formateador (Pint está en require-dev pero sin `pint.json`), y no hay CI.

## Variables de entorno

**El contenedor no lee el `.env` del host** — `.dockerignore` lo excluye a propósito. La única vía es el bloque
`x-app-env` de `docker-compose.yml`. Añadir una variable solo al `.env` es reconstruir sin efecto; ya ha pasado
tres veces, una de ellas con `META_APP_SECRETS`.

- **`META_APP_SECRETS` es una LISTA**: `"<app_id>:<secreto>,<app_id>:<secreto>"`. Varias apps de Meta comparten
  un mismo callback. Si está vacía (y `META_APP_SECRET` también), **todos los webhooks caen con 403** — es la
  causa clásica de "se desplegó y dejaron de entrar mensajes". El `app_id` hace falta para armar el app access
  token `app_id|app_secret` de la suscripción de llamadas.
- `META_APP_ID` y `META_ES_CONFIG_ID`: sin ellas el botón de conectar WhatsApp **no se pinta, sin error alguno**.
- Tres versiones de Graph distintas y a propósito: `META_API_VERSION`, `META_ES_GRAPH_VERSION`,
  `META_CALLING_API_VERSION`. No unificarlas.
- **`DB_QUEUE_RETRY_AFTER=360` debe superar el `$timeout` del job más lento** (`ProcessWhatsAppAi`, 210 s). Por
  debajo, la cola entrega a un segundo worker un job que el primero sigue ejecutando y el cliente recibe la
  respuesta dos veces.
- `VITE_REVERB_*` son **build args**: se hornean en el bundle. Cambiar el dominio del websocket exige rebuild,
  no reinicio. `REVERB_HOST` está fijado a `reverb` en el compose ignorando el `.env`, adrede.
- `WHATSAPP_WINDOW_GUARD=shadow|enforce` y `WHATSAPP_WINDOW_GUARD_COMPANIES` (lista de `company_id`).

## Webhooks entrantes

`GET|POST /webhooks/whatsapp`, exentas de CSRF (`bootstrap/app.php:25`), en
`app/Http/Controllers/WhatsAppWebhookController.php`.

- La firma se valida **sobre el cuerpo crudo** (`$request->getContent()`), nunca sobre `$request->all()`
  re-serializado.
- **Si algún evento del lote falla se responde 500 a propósito** para que Meta reintente. Es seguro porque el
  guardado es idempotente por `wamid`. Todo lo que se escriba aquí debe seguir siéndolo.
- Los campos de coexistencia (`history`, `smb_app_state_sync`, `smb_message_echoes`) **no se vuelcan al log**:
  traen hasta seis meses de conversaciones ajenas. Las constantes `CAMPOS_SIN_VOLCADO` (privacidad) y
  `CAMPOS_COEXISTENCIA` (enrutado) están separadas a propósito aunque hoy coincidan.
- Log dedicado: `storage/logs/whatsapp.log`.

## Colas

Driver `database` en desarrollo nativo; en Docker el compose usa **Redis** por defecto para cola y caché
(`docker-compose.yml:34,37`), así que lo que corre en producción depende de `.env.docker`, no del `.env.example`.

El ritmo de campañas es **del número, no de la campaña**: `CampaignPacer` reparte turnos contra el reloj
compartido de la instancia (`campaign_send_slots`), porque Meta cuenta los mensajes por `phone_number_id`. Todo
envío masivo nuevo debe pedirle turno al pacer en vez de calcular su propio `->delay()`. Los envíos de chat,
bot, IA y API siguen saliendo sin límite: ahí no hay control de ritmo todavía.

`IniciarSincronizacionCoexistencia` tiene **24 h y un solo intento**: si se queda a medias hay que desconectar
el número y rehacer el registro con el cliente delante. Triple defensa contra duplicados (`ShouldBeUnique`,
índice único, transacción con bloqueo) — no debilitarla.

`ProcessWhatsAppAi` / `ProcessWhatsAppChatAi` no envían nada: delegan en `ProcessWhatsAppMenu`, el único sitio
que habla con Meta. Los dos saltos de cola son deliberados: las comprobaciones (agente asignado, hilo cerrado,
ventana de 24 h) se reevalúan **después** de la espera del modelo.

## Tareas programadas

Viven en `routes/console.php`, **no** en `bootstrap/app.php`: `campaigns:run-scheduled` (cada minuto),
`whatsapp:fallback-template` y `coexistencia:vigilar` (cada hora), `whatsapp:health-check` (07:00).

Hay 11 comandos propios en `app/Console/Commands/`; `whatsapp:diagnose` es el punto de partida cuando no entran
mensajes.

## Frontend

Inertia + React 19 + Vite 7 + Tailwind 4 (**sin `tailwind.config.js`**: la config va en CSS).
No usar `@vitejs/plugin-react` v6 — requiere Vite 8 y `laravel-vite-plugin` 2.x usa Vite 7; fijado en `^5.2.0`.
Ziggy se usa con la directiva `@routes` en `app.blade.php`, no como prop compartida.
Notas adicionales de arquitectura frontend en `memory/MEMORY.md` (parcialmente desactualizado).

## Repo

- Rama viva: **`master`**. `main` es un fósil del commit inicial, 250 commits atrás.
- Ramas nuevas: `tipo/descripcion-en-espanol-con-guiones`.
- Commits: conventional en español, y **el asunto describe el efecto para el usuario, no el cambio técnico**.
  Ej.: `fix: en un portátil el chat se quedaba con 476px de los 1356 de pantalla`.
- Al documentar en `docs/`, seguir el estilo existente: cada decisión rara se acompaña del incidente que la
  causó, con fecha, para que nadie la "arregle" de vuelta.
