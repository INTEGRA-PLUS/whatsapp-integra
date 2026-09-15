# Resumen de conversación con IA

La extensión `conversation_summary` y su flujo de n8n. Escrito el 15-sep-2026,
el día que se montaron los dos.

## Para qué existe

El caso es el **traspaso**. Un asesor coge un chat de cuarenta mensajes y tres
días —porque el compañero libra, porque entra el turno de tarde, porque el
cliente vuelve tras una semana— y antes de contestar tiene que leérselo entero.

El error que se quiere evitar no es un resumen pobre: eso se nota, se abre el
hilo y ya. Es **contradecir lo que ya se le dijo al cliente**. De ahí que en el
prompt lo prometido sea obligatorio y que inventar esté prohibido en dos sitios
distintos.

## Por qué a petición y no automático

Resumir cada conversación según llega es gastar una inferencia en las que tienen
dos mensajes, que son la mayoría y no la necesitan. El botón gasta sólo cuando
alguien lo necesita.

Y deja el dato que hace falta antes de automatizar nada: **cuántas veces se
pulsa**. Si nadie lo usa, automatizarlo habría sido gastar en silencio.

## El contrato

`POST` al webhook, con cabecera `X-Api-Key`, y **30 segundos** de plazo por el
lado de Laravel.

```json
{
  "empresa":      { "id": 57, "nombre": "Cootramed" },
  "conversacion": { "id": 90038, "contacto": "Camilo R." },
  "tono": "telegrama",
  "mensajes": [
    { "de": "cliente", "texto": "Llevo tres días sin servicio", "cuando": "2026-09-15T09:02:00-05:00" },
    { "de": "asesor",  "texto": "Le agendamos visita el jueves", "cuando": "2026-09-15T09:05:00-05:00" }
  ]
}
```

Los mensajes van **en orden cronológico** y son los últimos N que configure la
empresa (40 por defecto). `tono` es `telegrama`, `neutro` o `informe`.

La respuesta:

```json
{
  "resumen": "Sin servicio desde el martes...",
  "puntos": ["Sin servicio desde el martes", "Ya reinició el router"],
  "pendientes": ["Reagendar la visita"]
}
```

`ResumenIaClient` es **tolerante a propósito** y acepta la respuesta envuelta en
`output` o `data`, los alias en inglés (`summary`, `highlights`, `todo`) y las
listas como texto con saltos de línea y viñetas. No es descuido: n8n envuelve
distinto según cómo esté montado el flujo, y descartar una respuesta buena por
la forma del sobre sería tirar una inferencia ya pagada.

La única condición dura: **si `resumen` viene vacío se trata como fallo**. Un
panel en blanco parece una avería; un error explica qué pasó.

Recortes: resumen a 2.000 caracteres, cada punto a 200, máximo 8 por lista.

## El flujo de n8n

`WhatsApp · Resumen de conversación`, en el mismo n8n que el semáforo y los
menús (`178.238.224.239`). Ocho nodos, clonando la arquitectura del semáforo
para no inventar un patrón nuevo:

```
Webhook (POST /webhook/whatsapp-resumen, headerAuth)
  → Preparar resumen      CONFIG arriba + guard de validación
  → ¿Puede seguir?        no → Sin resumen → Responder
  → Ollama · Resumen      gpt-oss:120b-cloud, salida estructurada
  → Verificar resumen
  → Responder
```

Reutiliza las credenciales que ya existían: `Bearer Auth account` para Ollama y
`Header Auth account` para el webhook.

**Se importó con el CLI**, no por la interfaz ni tocando Postgres:

```bash
docker cp flujo.json n8n-n8n-main-1:/tmp/flujo.json
docker exec -i n8n-n8n-main-1 n8n import:workflow --input=/tmp/flujo.json
```

Dos cosas que el CLI exige y no están en la documentación: el JSON necesita un
campo `id` en la raíz —sin él falla con `null value in column "id"`— y conviene
poner `active: false`, porque importar un flujo y encender un webhook público
son dos decisiones distintas.

### Tres diferencias con el semáforo, y por qué

- **`temperature: 0.2`, no 0.** Clasificar y redactar no son lo mismo: a cero
  salen resúmenes cortados y telegráficos aunque se pida el tono `informe`.
  Baja igualmente, porque lo que se resume son hechos.
- **Timeout de 25 s, no 40.** Laravel corta a los 30. Un flujo más lento
  devolvería un resultado que el CRM ya no está esperando.
- **El webhook lleva `headerAuth`.** El del semáforo no lo lleva (ver abajo).

## Lo que cuesta

Un resumen son ~0,0009 USD. Para comparar, una conversación completa de chat
—seis turnos más dos análisis de semáforo— sale por 0,0086 USD.

El consumo se apunta en `company_ai_usage` vía `ContadorDeIa`, que cuenta el
resumen como **una conversación** a efectos de crédito: se pide una vez por hilo
y la caché evita que se repita.

## La caché, que es lo que hace que se use

`whatsapp_conversations.summary_until_message_id` guarda hasta qué mensaje cubre
el resumen. No responde «¿hay resumen?» sino **«¿el que hay sigue valiendo?»**.

Sin eso, abrir el resumen dos veces cuesta dos inferencias, y un resumen que se
regenera cada vez que alguien lo abre es un resumen que nadie vuelve a abrir. El
botón «Rehacer» es la salida para cuando el resultado no convenció.

## Variables

En el bloque `x-app-env` de `docker-compose.yml`, que es la única vía para que
el contenedor las vea — el `.env` del host está en `.dockerignore`.

```
RESUMEN_WEBHOOK_URL=https://<n8n>/webhook/whatsapp-resumen
RESUMEN_API_KEY=<la clave de Header Auth account>
RESUMEN_TIMEOUT=30
```

Sin ellas la extensión se instala y se enciende igual, pero el botón responde
503 y **lo dice**: «El servicio de resumen no está configurado en la
plataforma». Avisar es mejor que fallar callado.

## Cómo probarlo sin tocar el CRM

```bash
curl -X POST https://<n8n>/webhook/whatsapp-resumen \
  -H "X-Api-Key: TU_CLAVE" -H "Content-Type: application/json" \
  -d '{"empresa":{"id":1,"nombre":"Prueba"},
       "conversacion":{"id":1,"contacto":"Camilo"},
       "tono":"telegrama",
       "mensajes":[
         {"de":"cliente","texto":"Llevo tres días sin internet"},
         {"de":"asesor","texto":"Le agendo visita para el jueves"},
         {"de":"cliente","texto":"Nadie vino el jueves"}]}'
```

Si devuelve los tres campos, el botón «Resumir» funciona sin tocar nada más.

---

## Dos cosas que se descubrieron montando esto

### El semáforo lleva desde siempre sin IA

Su flujo existe y está **activo** en n8n, pero `SENTIMIENTO_WEBHOOK_URL` está
**vacía** en producción (comprobado el 15-sep-2026). O sea que el semáforo sólo
ha funcionado con la capa 1, el diccionario, desde que se instaló.

No es un fallo: degrada exactamente como se diseñó. Pero la capa 2 —la que
entiende ironía y frases largas, y la que sube del ~50 % de acierto del léxico
al 85 %+ del modelo— nunca se ha usado con un cliente real. Se enciende con:

```
SENTIMIENTO_WEBHOOK_URL=https://<n8n>/webhook/whatsapp-sentimiento
SENTIMIENTO_API_KEY=cualquier-valor
```

La clave da igual porque ese flujo **no la valida**, pero `configured()` exige
que no esté vacía.

### El webhook del semáforo está abierto

`authentication: ninguna`. Cualquiera que acierte la ruta puede mandarle
conversaciones, y el flujo las procesa. El `01 · Chatbot Gateway` sí usa
`headerAuth`, así que el patrón correcto ya estaba montado al lado — el de
sentimiento se quedó sin él.

El de resumen nace con `headerAuth` puesto por eso.

---

## Recordatorio

**Los flujos no viven en ningún repo**: están en `workflow_entity` del Postgres
de n8n. Este documento y el JSON que se importó son lo único versionado, así que
un cambio hecho a mano en la interfaz se pierde aquí.
