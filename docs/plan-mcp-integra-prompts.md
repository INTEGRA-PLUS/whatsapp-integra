# Plan — conectar el flujo de IA al MCP de Integra

> **Corrección.** La primera versión de este plan proponía construir un servidor
> MCP en Laravel sobre `IntegraClient`. Estaba mal: **el servidor MCP ya existe
> y está en producción**, corriendo dentro de cada instancia de Integra, con 39
> herramientas. Aquel trabajo se eliminó de la rama (queda en
> `backup/ambos-agentes`). Lo que hace falta no es un servidor: es **enchufar el
> flujo de n8n al que ya hay**, resolviendo el problema real, que es de routing
> multiempresa.

---

## Lo que ya existe (y que este plan NO toca)

El "asistente de Integra": un servidor MCP dentro de cada instancia del cliente.

| | |
| --- | --- |
| URL | dominio del cliente + `/software/mcp` (ej. `https://cmnet.online/software/mcp`) |
| Auth | `Authorization: Bearer itg_…`, **un token por empresa y por perfil** |
| Herramientas | 39: 36 de consulta, 3 de escritura |
| Perfiles | `lectura`, `soporte` (cédula y celular sin enmascarar), `escritura`, `nomina` |
| Emisión | `php artisan mcp:token --perfil=…` dentro del contenedor del cliente; **se muestra una sola vez** |
| Hoy | 43 tokens emitidos, **todos de perfil `lectura`** |

Escrituras, y sólo estas tres: registrar un pago sobre una factura existente,
abrir un radicado, y pedir una prórroga (queda pendiente de aprobación). Piden
clave de idempotencia y firman como "Asistente IA".

**No puede y no podrá**: emitir ni anular facturas, tocar numeración DIAN,
cortar o reconectar, escribir en routers, borrar nada, ni tocar nómina.

---

## El problema de diseño

Cada empresa tiene **su dominio y su token**. Las credenciales de n8n son
estáticas y el nodo MCP Client es un **sub-nodo** colgado del agente. ¿Cómo
llegan, por conversación, la instancia y el token correctos?

### (a) Cabecera dinámica en n8n — comprobado: **sí se puede, y aun así no**

No lo doy por hecho: lo verifiqué contra la documentación y los issues de n8n.

1. Las credenciales de n8n **sí admiten expresiones**, y la comunidad lo usa con
   Bearer Auth del MCP Client Tool para tokens de vida corta.
2. En un **sub-nodo**, una expresión *siempre resuelve contra el primer item*.
   En nuestro caso eso no estorba: el webhook trae una conversación por
   ejecución, así que "el primero" es "el único".
3. Pero el issue [#23421](https://github.com/n8n-io/n8n/issues/23421) reporta
   que el token dinámico **no se envía** si *Tools to Include* no está en `All`.
   Sigue abierto y marcado como stale.
4. Y la propia guía de credenciales dinámicas exige un **valor de reserva**,
   porque n8n necesita autenticarse en tiempo de diseño para listar las
   herramientas.

El punto 4 es el que lo descarta, y no es un detalle de configuración. El valor
de reserva es un token **de una empresa concreta**. El día que la expresión no
resuelva —un rename del nodo webhook, un cambio de forma del payload, el punto
3— n8n no falla: usa la reserva en silencio. Es decir, **la conversación de la
empresa A consulta el Integra de la empresa B**, y con un token de escritura eso
es un pago registrado en la contabilidad equivocada. En un CRM multiempresa el
modo de fallo por defecto no puede ser "los datos de otro cliente".

A eso se suma lo que (a) implica aunque todo funcione: el token `itg_` de las 43
empresas viajaría a n8n **en cada mensaje** y quedaría guardado en cada registro
de ejecución. Son credenciales del ERP de producción de terceros.

### (b) Pass-through delgado en Laravel — **elegida**

n8n pega siempre contra **un** endpoint nuestro con **una** credencial estática.
Laravel resuelve de qué empresa se trata y reenvía el JSON-RPC al MCP de esa
empresa con su token.

```
n8n (1 credencial estática)
   │  POST /api/mcp/integra/{grant}      X-Mcp-Key: …
   ▼
Laravel  ── abre el grant → empresa ── company_integrations(mcp_integra)
   │                                      base_url + token itg_ (cifrado)
   ▼
https://cmnet.online/software/mcp        Authorization: Bearer itg_…
```

Las dos opciones dependen de la misma expresión de n8n. La diferencia es **cómo
fallan**: (a) cae en el Integra de otro cliente; (b) cae en un 401. Fail closed.

Y de paso:

- **El token `itg_` no sale de Laravel.** Ni al payload, ni a n8n, ni al navegador.
- Hay dónde **aplicar los permisos por empresa** que el panel ya ofrece
  (`leer` / `radicados` / `pagos`) sobre las tres escrituras.
- Hay dónde **auditar** qué herramienta llamó la IA y para qué empresa.
- Una empresa sin MCP configurado se resuelve sola: no hay grant, no hay bloque
  `mcp` en el payload, y el `IF` del flujo se va por la rama de siempre.

**No se reimplementa nada.** El proxy no conoce las 39 herramientas: reenvía
`tools/list` y `tools/call` tal cual y devuelve la respuesta tal cual. Lo único
que mira es el nombre de la herramienta en `tools/call`, para la política de
escritura.

---

## Credenciales por empresa

Fila nueva en `company_integrations`, `key = mcp_integra`. Sin migración: la
tabla ya tiene `base_url`, `access_token` (cast `encrypted` y `hidden`),
`settings`, `status`, `connected_at`, `last_error`, `account`.

| Columna | Qué guarda |
| --- | --- |
| `base_url` | `https://cliente.tld/software/mcp` |
| `access_token` | el `itg_…`, cifrado en reposo, **nunca de vuelta al frontend** |
| `settings.perfil` | `lectura` \| `soporte` \| `escritura` |
| `account` | lo que devolvió el sondeo: nº de herramientas, nombre y versión del servidor |

**`nomina` no se ofrece en el panel.** El CRM atiende a suscriptores; un token
que además abre la nómina de la empresa no tiene nada que hacer detrás de un
modelo que conversa con desconocidos. Pegarlo sería ampliar el daño de cualquier
fallo sin ganar una sola respuesta.

Es fila propia y no se cuelga de las de Integra (`invoice_payments`,
`contacts_sync`) porque **es otra credencial**: otro token, otro perfil, otra
URL y otro ciclo de vida. `Integra::SOURCES` no la toca, así que no puede
confundirse con la conexión del API v1 ni revocarla.

---

## Política de escritura

El gate de verdad es **el perfil del token**, y lo aplica el servidor de Integra.
Lo de aquí es defensa en profundidad, y se dice así en el código: si mañana el
proxy se equivoca, un token `lectura` sigue sin poder escribir.

Cómo se clasifica una herramienta como escritura, en este orden:

1. `annotations.readOnlyHint === false` en el `tools/list` del servidor real
   (cacheado). Es independiente del nombre, así que sobrevive a un rename.
2. Una lista de nombres configurable (`services.mcp_integra.write_tools`),
   por si el servidor no anota.

Una escritura clasificada exige el permiso de la empresa que le corresponda
(`radicados` o `pagos`). Una escritura que **no** se pueda clasificar exige
**todos** los permisos de escritura: no sabemos cuál es, así que se pide el
máximo. Se registra en el log para poder añadirla a la lista.

**Hoy todas las escrituras van a fallar**: los 43 tokens emitidos son de
`lectura`. Eso se dice en la guía y en la UI, porque si no el síntoma —el modelo
prometiendo un radicado que no se abre— es indistinguible de un bug.

---

## Prompt

`AiPrompt::base()` gana las reglas de uso de herramientas, con los dos matices
de la guía oficial:

- **"No pude preguntar" no es "no hay".** Cuando el MCP no logra hablar con un
  router, el modelo debe decir que no pudo comprobarlo, no concluir que el
  cliente no tiene sesión. Es la diferencia entre "no lo sé" y una afirmación
  falsa sobre el servicio de alguien.
- **Las advertencias se leen y se trasladan.** Cada respuesta trae un apartado
  con lo que el servidor no alcanzó a mirar. Dar por completa una respuesta
  recortada es cómo se le dice a un cliente que está todo bien.
- **Nunca prometer lo que el MCP no puede**: emitir o anular facturas, cortes,
  reconexiones, tocar routers. No es una preferencia: no existe la herramienta.

---

## Archivos

| Archivo | Cambio |
| --- | --- |
| `app/Support/McpGrant.php` | **nuevo** — acuñar/abrir el permiso por empresa. |
| `app/Services/Mcp/IntegraMcpClient.php` | **nuevo** — cliente JSON-RPC al servidor real + sondeo. |
| `app/Services/Mcp/IntegraMcpPolicy.php` | **nuevo** — clasificar escrituras y cruzarlas con los permisos. |
| `app/Http/Controllers/Mcp/IntegraMcpProxyController.php` | **nuevo** — el pass-through. |
| `app/Http/Controllers/IntegraMcpSettingsController.php` | **nuevo** — conectar/probar/desconectar desde el panel. |
| `app/Models/CompanyIntegration.php` | `KEY_MCP_INTEGRA` y dos ayudas. |
| `app/Services/WhatsAppAiClient.php` | `integra.mcp` en el payload (URL con grant; **sin token**). |
| `app/Support/AiPrompt.php` | reglas de uso de herramientas. |
| `routes/api.php`, `routes/web.php` | +2 y +3 líneas. Edición quirúrgica. |
| `config/services.php`, `.env.example`, `docker-compose.yml` | `mcp_integra`. |
| `resources/js/pages/Integrations/Index.jsx` | panel "Asistente de Integra". |
| `docs/mcp-integra.md` | **reescrito** — guía de conexión del flujo. |

**Sin migraciones.**

## Colas

**No añade trabajo en cola.** Las herramientas las llama n8n contra Laravel,
fuera de los jobs. Lo que puede crecer es el tiempo de una respuesta, porque el
modelo encadena herramientas y alguna (`contratos_diagnosticar`) habla con un
router. El proxy corta a 60 s; el flujo entero sigue cabiendo en
`AI_MENUS_TIMEOUT` (180 s). Si un día no cabe, se sube ese y después
`*_QUEUE_RETRY_AFTER` — no se rediseñan las colas, que ya van justas con 2
workers y ~210 s por inferencia.

## Riesgos

| Riesgo | Mitigación |
| --- | --- |
| El grant queda en los logs de n8n. | Cifrado, 15 min, una empresa, y sólo abre lo que ella autorizó. |
| Un rename de la herramienta de escritura burla la lista de nombres. | Se clasifica primero por `readOnlyHint`; y el perfil del token es el gate real. |
| El proxy añade un salto. | Es una petición HTTP en la misma red; el tope de 60 s lo cubre. |
| Una empresa sin MCP. | Sin bloque `mcp` en el payload y rama `IF` en el flujo. |
| Otro agente en el repo. | Sólo se añaden líneas a `routes/*.php`, `config/services.php`, `docker-compose.yml` y un panel nuevo en `Integrations/Index.jsx`. Nada se reescribe. |

## Tests

- `McpIntegraProxyTest` — llave, grant caducado/manipulado, **aislamiento
  multiempresa** (el token y el dominio que salen son los de la empresa del
  grant), reenvío literal de `tools/list` y `tools/call`, gating de escrituras
  por `readOnlyHint` y por nombre, escritura sin clasificar, empresa sin MCP,
  timeout y traducción de errores.
- `McpIntegraConnectionTest` — guardar/probar/borrar la credencial, que el token
  **nunca** vuelve al frontend, que `nomina` se rechaza, permisos del panel.
- `AiPromptTest` — las reglas nuevas están en el prompt base.

`DB_CONNECTION=mysql DB_DATABASE=wpp_test php -d memory_limit=1G vendor/bin/phpunit`
