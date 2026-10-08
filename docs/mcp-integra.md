# Conectar la IA al asistente de Integra (MCP)

Cómo darle al modelo del flujo de n8n acceso al **asistente de Integra**: el
servidor MCP que ya corre dentro de la instancia de Integra de cada cliente.

Esta guía es de **conexión**, no de construcción. El servidor existe, está en
producción y no se toca desde este repositorio.

---

## 1. Qué hay al otro lado

| | |
| --- | --- |
| Dónde corre | Dentro de la instancia de Integra de **cada cliente** |
| URL | el dominio del cliente + `/software/mcp` — p. ej. `https://cmnet.online/software/mcp` |
| Autenticación | `Authorization: Bearer itg_…` — un token **por empresa y por perfil** |
| Herramientas | **39**: 36 de consulta y 3 de escritura |
| Perfiles | `lectura`, `soporte` (igual, con cédula y celular sin enmascarar), `escritura`, `nomina` |

Consultas más útiles para el CRM: `clientes_buscar`, `clientes_ficha`,
`cartera_cliente`, `pagos_listar`, `contratos_estado`, `contratos_diagnosticar`,
`contratos_historial`, `facturas_detalle`, `radicados_listar`,
`red_sesion_contrato`, `mensajes_estado_envio`.

**Escrituras, y sólo estas tres:** registrar un pago sobre una factura que ya
existe, abrir un radicado, y pedir una prórroga (que queda pendiente de
aprobación). Piden clave de idempotencia y firman como usuario "Asistente IA".

**Lo que no puede y no podrá:** emitir ni anular facturas, tocar la numeración
DIAN, cortar o reconectar el servicio, escribir en routers, borrar nada, ni
tocar nómina. Si alguien dice que la IA "crea facturas", es falso.

> ### ⚠️ Hoy ninguna escritura funciona
> Los **43 tokens emitidos son de perfil `lectura`**. Registrar un pago, abrir
> un radicado o pedir una prórroga van a fallar en el servidor aunque el permiso
> esté concedido en *Configuración → Flujo IA*. Para habilitarlas hay que emitir
> un token de perfil `escritura` (paso manual, §3) y pegarlo en el panel.
>
> El CRM lo detecta y se adelanta: si el perfil guardado no es `escritura`, la
> herramienta no sale siquiera hacia Integra y el modelo recibe "el token es de
> sólo lectura, dile al cliente que lo registras con un asesor". Sin eso, el
> síntoma sería un modelo prometiendo radicados que nadie abre.

---

## 2. Cómo se conecta: pass-through, y por qué

Cada empresa tiene **su dominio y su token**. Las credenciales de n8n son
estáticas, y el nodo MCP Client es un sub-nodo del agente.

**Se descartó** poner el token de cada empresa en una credencial dinámica de
n8n. Se puede —n8n admite expresiones en credenciales—, pero exige un **valor de
reserva** para poder listar las herramientas en tiempo de diseño, y ese valor es
el token de una empresa concreta. El día que la expresión no resuelva, n8n no
falla: usa la reserva, y la conversación de una empresa consulta el Integra de
otra. (Además, el issue
[n8n#23421](https://github.com/n8n-io/n8n/issues/23421) reporta que el token
dinámico no se envía si *Tools to Include* no está en `All`.)

**Lo que se hace en su lugar:** n8n pega siempre contra **un** endpoint nuestro
con **una** credencial estática, y Laravel reenvía el JSON-RPC al MCP de la
empresa que toque, con su token.

```
n8n ──POST /api/mcp/integra/{grant}──▶ Laravel ──▶ https://cliente.tld/software/mcp
        X-Mcp-Key: (estática)                       Authorization: Bearer itg_…
```

- El `grant` es un identificador **cifrado** de empresa + conversación +
  permisos, que caduca en 15 minutos. Lo acuña Laravel y viaja en el payload.
- El token `itg_` **no sale de Laravel**: ni al payload, ni a n8n, ni al
  navegador.
- Si la expresión de la URL falla, el resultado es un **401**, no los datos de
  otro cliente.

Laravel **no interpreta** las herramientas: reenvía `tools/list` y `tools/call`
tal cual. Una herramienta nueva en Integra funciona sin tocar este repositorio.

---

## 3. Pasos manuales del usuario

### 3.1 Emitir el token (en el servidor del cliente)

Dentro del contenedor de Integra de esa empresa:

```bash
php artisan mcp:token --perfil=lectura     # consultar
php artisan mcp:token --perfil=escritura   # + pagos, radicados y prórrogas
```

**El token se muestra una sola vez**: en la base de Integra sólo queda su hash.
Si se pierde, hay que emitir otro. Por eso no hay asistente de conexión
automático en el panel: no existe ningún endpoint al que podamos llamar para que
nos lo dé.

No uses `--perfil=nomina`. El panel lo rechaza: el CRM atiende a suscriptores, y
un token que además abre la nómina de la empresa no tiene nada que hacer detrás
de un modelo que conversa con desconocidos.

### 3.2 Pegarlo en el CRM

*Integraciones → Complementos → Integra → **Asistente de Integra (IA)***

El panel se ve **aunque la API v1 no esté conectada**: es otra credencial y no la
necesita. Al principio vivía dentro del bloque que sólo aparece tras conectar la
v1, y conectar el asistente obligaba a conectar primero algo que no usa
(2026-09-30). Trae además, plegable, la guía de estos mismos pasos para quien
administra la empresa, y avisa cuando la credencial está bien pero el puente no
está encendido en el servidor (`MCP_INTEGRA_KEY` vacía): verde y sin ese aviso,
nadie sabría que la IA nunca la usa.

- **URL**: el dominio donde entras a Integra. El `/software/mcp` se añade solo.
- **Token**: el `itg_…` completo.
- **Perfil**: el mismo con el que lo emitiste.

Al guardar se prueba contra el servidor real antes de persistir nada: si el
token no abre, no se guarda. El botón **Verificar** vuelve a probarlo — úsalo
cuando "la IA dejó de saber cosas", porque un token revocado desde el servidor
del cliente no nos avisa.

### 3.3 Conceder permisos de escritura (opcional)

*Configuración → Flujo IA → IA en los menús → Hasta dónde puede llegar*

Nace sólo con **Consultar**. **Radicar** y **Cobrar** se conceden a mano. Los dos
gates son independientes y hacen falta los dos: el permiso del panel y un token
de perfil `escritura`.

---

## 4. Configurar el servidor (una vez por instalación)

```bash
MCP_INTEGRA_KEY=            # php -r "echo bin2hex(random_bytes(32));"
MCP_INTEGRA_GRANT_TTL=15    # minutos que vale el permiso de la URL
MCP_INTEGRA_TIMEOUT=60      # diagnosticar un contrato llega hasta el router
MCP_INTEGRA_URL=            # vacío = APP_URL
```

> **Docker.** Una variable nueva del `.env` **no llega al contenedor**: el
> `.dockerignore` excluye el `.env` de la imagen. Las cuatro ya están en
> `x-app-env` de `docker-compose.yml`. Si se añade otra, hay que añadirla ahí o
> el panel dirá "no configurado" sin nada que investigar.

Sin `MCP_INTEGRA_KEY` el endpoint responde **404** y el flujo se queda sin
herramientas. Comprobación rápida:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST "$APP_URL/api/mcp/integra/loquesea" \
  -H "X-Mcp-Key: $MCP_INTEGRA_KEY" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
# 401 → correcto: la llave pasó y lo que falta es el permiso de empresa.
# 404 → MCP_INTEGRA_KEY no llegó al contenedor.
```

---

## 5. Configurar n8n

**Todo este apartado es manual.** Laravel ya manda lo necesario en el payload
del webhook de menús:

```jsonc
"integra": {
  "base_url": "…", "token": "…",          // camino viejo (API v1), sigue ahí
  "mcp": {
    "url": "https://crm.tudominio.com/api/mcp/integra/eyJpdiI6…",
    "escribe": false                      // ¿el token permite las 3 escrituras?
  }
}
```

`mcp` llega a **`null`** cuando la plataforma no tiene la llave configurada, la
empresa no ha pegado su credencial, o la pegó y ya no funciona.

**Paso 1 — Credencial.** *Credentials → New → Header Auth*
- Name: `MCP Integra CRM`
- Header Name: `X-Mcp-Key`
- Header Value: el valor de `MCP_INTEGRA_KEY`

Es **una sola credencial para toda la plataforma** y no cambia nunca. Ésa es la
diferencia con la alternativa descartada.

**Paso 2 — Nodo.** Cuelga un **MCP Client Tool** del nodo del agente.

| Campo | Valor |
| --- | --- |
| Endpoint | expresión: `{{ $('Webhook').first().json.body.integra.mcp.url }}` |
| Server Transport | `HTTP Streamable` |
| Authentication | Generic Credential → Header Auth → `MCP Integra CRM` |
| Tools to Include | **All** |

Tres detalles que no son opcionales:

- **`.first()`, no `$json`.** Un sub-nodo resuelve siempre contra el primer
  item. Aquí da igual —el webhook trae una conversación por ejecución—, pero
  escribirlo explícito evita que el día que alguien meta un `Split` la
  herramienta empiece a consultar a otro cliente.
- **Ajusta el nombre del nodo webhook** al que tenga tu flujo.
- **`Tools to Include` en `All`.** Con una selección, n8n deja de enviar la
  cabecera de autenticación (issue #23421). Además, filtrar aquí sería mantener
  la misma lista en dos sitios: quien decide qué puede usar cada empresa es el
  CRM.

**Paso 3 — Rama sin MCP.** Un `IF` antes del agente:

```
{{ $json.body.integra.mcp !== null }}
```

Por la rama falsa, el flujo de siempre (sin herramientas). Una empresa que no ha
pegado su token tiene que seguir siendo atendida.

**Paso 4 — Prompt del agente.** Usa el que manda Laravel ya compuesto:

```
{{ $json.body.asistente.prompt.compuesto }}
```

Trae el prompt base de la plataforma, las instrucciones que escribió la empresa
dentro de su bloque, y las reglas innegociables repetidas al final. Añade
**alrededor** las reglas propias del flujo: se solapan a propósito, para que un
error en Laravel no pueda relajar el flujo.

**Paso 5 — Timeout del nodo**: 90 s, por encima de los 60 s con los que corta el
pass-through. El total sigue cabiendo en `AI_MENUS_TIMEOUT` (180 s).

### La credencial de Ollama

No es parte del MCP, pero es donde más tiempo se pierde montando el flujo: la
API key de Ollama tiene forma **`id.secreto`**, y la web sólo lista el `id`.
Pegar sólo el id da un **test verde en n8n que es un falso positivo**. Se valida
de verdad con:

```bash
curl -s -X POST https://ollama.com/api/me -H "Authorization: Bearer $OLLAMA_API_KEY"
```

Si el test de n8n va en verde y las inferencias fallan igual, es esto.

---

## 6. Lo que el prompt le exige al modelo

Van en `App\Support\AiPrompt::base()` y viajan en cada mensaje. Las dos primeras
salen de la guía oficial del asistente y arreglan el mismo error de fondo:

- **"No pude preguntar" NO es "no hay".** Si una herramienta no logra hablar con
  el equipo del cliente, el modelo dice que no pudo comprobarlo y ofrece un
  asesor. Nunca concluye que el cliente no tiene sesión, ni servicio, ni
  facturas, porque una consulta no respondiera.
- **Las advertencias se leen y se trasladan.** Cada respuesta trae un apartado
  con lo que el servidor no alcanzó a mirar. Una respuesta a la que le falta un
  dato no es una respuesta completa.
- **No promete lo que no puede.** Ni emitir o anular facturas, ni cortes,
  reconexiones o equipos de red: no existe la herramienta.

El mismo criterio está en el pass-through: cuando el servidor no responde, el
modelo recibe *"dile al cliente que no has podido comprobarlo — no que no
exista"*, y no un error de protocolo que le corte el turno.

---

## 7. Permisos y frenos

| Control | Dónde | Qué hace |
| --- | --- | --- |
| Perfil del token | Integra (servidor del cliente) | **El gate de verdad.** Un `lectura` no escribe pase lo que pase. |
| `abilities` de la empresa | *Flujo IA* del CRM | `leer` / `radicados` / `pagos`. Nace sólo con `leer`. |
| Clasificación de escrituras | `IntegraMcpPolicy` | `annotations.readOnlyHint` primero; lista de nombres de reserva. Una escritura sin clasificar exige **todos** los permisos de escritura. |
| Tope de llamadas | pass-through | 60 por conversación / 10 min. Al otro lado hay un ERP de producción. |
| Caducidad del grant | `McpGrant` | 15 min, una empresa. |

Si Integra renombra una herramienta de escritura, la clasificación por
`readOnlyHint` sigue valiendo; la lista de nombres es configurable en
`services.mcp_integra.write_tools` (formato `permiso => [nombres]`).

---

## 8. Colas

**Esto no añade trabajo en cola.** Las herramientas las llama n8n contra
Laravel, fuera de los jobs de IA. Lo que sí puede crecer es el tiempo de una
respuesta, porque el modelo encadena herramientas. Si deja de caber, se sube
`AI_MENUS_TIMEOUT` **y después** `DB_QUEUE_RETRY_AFTER` /
`REDIS_QUEUE_RETRY_AFTER` — no se rediseñan las colas, que ya van justas con 2
workers y ~210 s por inferencia.

---

## 9. Diagnóstico

| Síntoma | Causa probable |
| --- | --- |
| El endpoint responde 404 | `MCP_INTEGRA_KEY` no llegó al contenedor (`x-app-env`). |
| Siempre 401 | Cabecera `X-Mcp-Key` mal, o la expresión de la URL no resuelve y el grant llega vacío. |
| "El permiso ha caducado" a mitad de conversación | Más de 15 min entre acuñarlo y usarlo. Sube `MCP_INTEGRA_GRANT_TTL`. |
| El modelo dice que no puede consultar | La empresa no tiene credencial, o `Verificar` falla: token revocado en el servidor del cliente. |
| Promete un radicado y no aparece | Token de perfil `lectura`. Ver el aviso del §1. |
| n8n no manda la autenticación | *Tools to Include* no está en `All`. |

## 10. Probarlo

```bash
DB_CONNECTION=mysql DB_DATABASE=wpp_test php -d memory_limit=1G vendor/bin/phpunit \
  --filter='McpIntegraProxyTest|McpIntegraConnectionTest'
```

Las que hay que mirar si se toca algo:

- `test_la_llamada_acaba_en_el_dominio_y_con_el_token_de_la_empresa_del_permiso`
  — el aislamiento multiempresa, comprobado sobre la petición que sale de verdad.
- `test_con_permiso_pero_token_de_solo_lectura_se_avisa_antes_de_prometer_nada`
  — el caso de las 43 empresas de hoy.
- `test_si_el_servidor_no_responde_el_modelo_no_concluye_que_no_hay_datos`.
- `test_guarda_la_credencial_y_el_token_no_vuelve_nunca`.
