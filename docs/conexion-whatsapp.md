# Conectar el WhatsApp de una empresa

Cómo entra una cuenta de WhatsApp al sistema, para quien tenga que tocarlo.

Hay **tres** caminos y no son intercambiables: el que sirve depende de si el
número ya tiene WhatsApp o no, y equivocarse borra la cuenta del cliente.

| Camino | Cuándo | Qué hace |
| --- | --- | --- |
| Formulario manual | Respaldo, o entornos sin registro insertado configurado | Alguien pega `phone_number_id`, `waba_id` y un token a mano |
| Registro insertado — número nuevo | El número **no** tiene WhatsApp | El cliente autoriza en la ventana de Meta y todo se rellena solo |
| Registro insertado — coexistencia | El número **ya** tiene WhatsApp Business | Igual, pero el negocio sigue usando su app del celular |

## La regla que lo condiciona todo

**Un número que ya tiene WhatsApp no se puede conectar por el camino normal.**
Meta responde *"This number is registered to an existing WhatsApp account"*, y lo
único que "arregla" ese error es borrar esa cuenta de WhatsApp — con sus chats.

Para un ISP eso deja fuera al caso más común: el dueño lleva años atendiendo
desde su celular. Por eso existe el tercer camino.

## Coexistencia: lo que le cambia al cliente

Esto es lo que hay que decirle **antes** de que pulse, no después. La pantalla de
consentimiento de Meta cubre compartir el historial, pero **no** menciona nada de
lo siguiente:

- Se desactivan, en los chats 1 a 1: **mensajes temporales**, **ver una vez** y
  **ubicación en tiempo real**.
- Las **listas de difusión** quedan de solo lectura. Las existentes se leen, no
  se crean nuevas.
- Los **dispositivos vinculados se desconectan**, WhatsApp Web incluido. Se
  pueden volver a vincular después, pero se caen en el momento del onboarding.
- Los **grupos no se sincronizan**. Siguen funcionando en la app; simplemente no
  aparecen en el CRM.

Lo que **no** pasa: no se pierden chats, el negocio sigue respondiendo desde el
celular y el número no se mueve de sitio.

Además:

- El número queda con un **tope fijo de 20 mensajes por segundo**. Es el precio
  de seguir siendo compatible con la app, y no se puede subir.
- Se sincronizan hasta **6 meses** de historial, y sólo si el cliente acepta
  compartirlo en la ventana.
- Lo que el negocio manda desde su celular es gratis; lo que sale por la API se
  cobra a tarifa de Cloud API.
- **Países no soportados:** Nigeria y Sudáfrica. Colombia sí.
- El cliente necesita **WhatsApp Business 2.24.17 o superior**.

## Cómo está montado

| Pieza | Responsabilidad |
| --- | --- |
| `EmbeddedSignupButton.jsx` | Carga el SDK de Meta, escucha el *session logging* y lanza la ventana. Los dos caminos son el mismo componente con distinto `featureType`. |
| `EmbeddedSignupController@config` | Le dice al navegador si hay configuración. Si falta algo responde `enabled:false` y el botón no se pinta. |
| `EmbeddedSignupController@store` | Canjea el código, suscribe la app al WABA del cliente y crea la instancia. |
| `MetaWhatsAppService::exchangeSignupCode` | El único paso que usa el secreto de la app. Servidor a servidor, siempre. |
| `InstanceController@store` | El formulario manual de respaldo. No lo toca nada de lo anterior. |

### La coexistencia se activa en la configuración, no en el código

Antes que nada: la configuración de Facebook Login for Business tiene un paso
**Products**, y **crearla desde una plantilla lo deja vacío**. Meta lo dice sin
mucho énfasis: *"seleccionar los productos te pone automáticamente en v4"*. Sin
ningún producto marcado la configuración no es v4, y la coexistencia es una
función de v4 — así que el `featureType` del código no hace absolutamente nada.

El síntoma es el mismo error de siempre ("este número ya está registrado en una
cuenta de WhatsApp"), así que parece que el problema es el número del cliente.
No lo es.

Se arregla en `Inicio de sesión con Facebook para empresas > Configuraciones >
Editar > Products`, marcando **WhatsApp Cloud API**. Ojo: *"This can't be changed
later"*. Marcar WhatsApp Cloud API auto-selecciona también la API de mensajes de
marketing; si no vas a usarla, desmárcala — pedir permisos que no se usan es lo
que hace que el cliente abandone el flujo.

El `config_id` no cambia al editar, así que no hay que tocar el `.env`.

### Estado: falta que Meta habilite la coexistencia (2026-09-05)

Todo lo configurable está hecho y **el flujo sigue sin ofrecer la coexistencia**:
la ventana muestra "Agrega tu número de teléfono" y rechaza el número por tener
WhatsApp. Antes de volver a tocar nada, esto ya está verificado y NO es la causa:

- App del cliente: WhatsApp **Business** 2.26.34.74 (mínimo 2.24.17).
- País: Colombia (sólo Nigeria y Sudáfrica están excluidos).
- Tech Provider con **acceso avanzado** en los dos permisos, verificación de
  negocio en regla.
- Configuración v4, con WhatsApp Cloud API marcado en *Products*.
- `featureType` + `sessionInfoVersion: "3"` viajando (comprobado en el bundle
  desplegado, no sólo en el fuente).
- Diálogo abriéndose en **v25.0** (visible en la URL de la ventana).
- Session logging activo y los tres webhooks suscritos.

Queda pendiente pedirle a Meta que active la función, por Soporte directo:
Question Topic **"WABiz: Onboarding"** + **"TechProvider: Onboarding"**, Request
Type **"Embedded Signup - Coexistence Onboarding"**. Que exista una ruta de
soporte con ese nombre exacto es la señal de que la habilitación no es
automática.

### Las dos trampas de la coexistencia

**`sessionInfoVersion: "3"` no es opcional.** El `featureType` por sí solo no
hace nada: sin esa segunda propiedad en `extras`, Meta ignora la petición y
sirve el flujo normal. Y el flujo normal rechaza cualquier número que ya tenga
WhatsApp, así que el síntoma es *exactamente* el error que la coexistencia venía
a evitar — parece un problema del número del cliente y es de la petición.

**El evento de cierre trae sólo el `waba_id`.** La coexistencia no termina con
`FINISH` sino con `FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING`, y su payload no
incluye `phone_number_id`: el número ya existía, así que Meta no lo devuelve.
Por eso el backend acepta que falte y lo resuelve preguntando por los números del
WABA, ya con el token en la mano. Exigirlo hacía fallar la conexión en el último
paso, después de que el cliente ya había aceptado todo.

### Por qué el listener se registra antes de abrir la ventana

Meta devuelve las dos mitades del resultado **por caminos distintos**:

- el `code` llega por el callback de `FB.login`;
- el `waba_id` y el `phone_number_id` llegan por `postMessage` (lo que Meta llama
  *session logging*).

Hacen falta las dos. Por eso el listener vive todo el tiempo que vive el botón y
guarda lo capturado en un `ref` y no en estado: el callback se cierra sobre el
valor que hubiera al montar, y en estado llegaría siempre vacío.

### Tres decisiones del backend que parecen de más y no lo son

**El número repetido se rechaza antes de canjear el código.** El código de Meta
es de un solo uso: gastarlo y fallar la validación después obliga al cliente a
repetir toda la ventana desde cero.

**Si falla la suscripción al WABA, no se crea la instancia.** Una instancia sin
suscripción se ve conectada en la lista y no recibe un solo mensaje. Es el peor
resultado posible: nadie busca el fallo hasta que un cliente reclama que no le
contestan.

**El registro del número en Cloud API se deja fuera.** Pide un PIN, puede fallar
por causas ajenas y ya tiene su propio botón en la pantalla de WhatsApp. Meterlo
aquí convertiría un fallo reintentable en "no se conectó nada".

## Configuración

| Variable | Valor | Dónde sale |
| --- | --- | --- |
| `META_APP_ID` | `865904982715022` | Panel de la app (Ispintegra) |
| `META_ES_CONFIG_ID` | `1739855773915048` | Inicio de sesión con Facebook para empresas > Configuraciones |
| `META_APP_SECRETS` | `app_id:secreto,...` | Panel de la app > Configuración > Básica |

**Las tres tienen que estar también en `docker-compose.yml`.** Ese archivo declara
una lista explícita de variables: ponerlas sólo en el `.env` del host hace que
lleguen vacías al contenedor, el endpoint responda `enabled:false` y el botón no
se pinte — sin un error en los logs y sin nada que investigar. Ya pasó una vez.

`META_APP_SECRET` en singular **está vacío en producción**; el secreto se resuelve
por app desde `META_APP_SECRETS`. Ver `MetaWhatsAppService::appSecretFor()`.

En el panel de la app, además, tienen que estar en `Sí`: *Iniciar sesión con el
SDK para JavaScript* (sin esto la ventana no abre), *OAuth de navegador
integrado*, *OAuth de cliente*, *OAuth web* y *Aplicar HTTPS*; y el dominio
público listado en *Dominios permitidos para el SDK para JavaScript*.

## Requisitos del lado de Meta

- **Acceso avanzado** a `whatsapp_business_messaging` y
  `whatsapp_business_management`. Aprobado el 2026-09-05. Sin él la app sólo
  puede operar activos propios, no los de un cliente.
- Ser **Tech Provider** o Solution Partner. Es uno de los seis requisitos de la
  coexistencia, no el único.
- Estos webhooks suscritos en la app, además de los de siempre: `history`,
  `smb_app_state_sync`, `smb_message_echoes`.

## Cómo se desconecta después

**No sirve la Deregister API.** Un número que está a la vez en Cloud API y en la
app no se puede desregistrar por ahí. Lo desconecta el propio cliente desde su
celular: **Ajustes > Cuenta > Plataforma de Business > Desconectar cuenta**.

Al hacerlo llega un webhook `account_update` con evento `PARTNER_REMOVED`.

Ojo con el reloj: hay **24 horas** desde el onboarding para sincronizar el
historial. Pasadas, el cliente tiene que desconectarse y repetir toda la ventana.

## Lo que todavía NO hace

Conectar por coexistencia **no llena el chat del CRM**. Los tres webhooks llegan
y `WhatsAppWebhookController` los ignora sin romperse — sólo deja constancia de
que llegaron y de su tamaño, nunca su contenido (ver la nota de abajo).

Falta procesarlos: importar el historial y conciliarlo con las conversaciones
existentes, reflejar en el chat de los agentes lo que el dueño escribe desde su
celular (`smb_message_echoes`) y sincronizar contactos (`smb_app_state_sync`).
Es trabajo de modelo de datos.

> **El historial no se escribe en el log.** `history` trae hasta seis meses de
> conversaciones de una empresa cliente y `smb_message_echoes` cada mensaje que
> manda desde su celular. Volcar eso al log escribiría en disco, en claro, la
> mensajería completa de un negocio ajeno, sobre un archivo que ya pesa decenas
> de MB al día. Ver `WhatsAppWebhookController::payloadForLog()`.

## Cuando algo falla

- **El botón no aparece.** Falta configuración. Mira `GET /api/embedded-signup/config`:
  si dice `enabled:false`, revisa las tres variables *dentro del contenedor*, no
  en el `.env` del host.
- **"This number is registered to an existing WhatsApp account".** O se pulsó el
  botón equivocado, o la coexistencia no se activó. Comprueba lo segundo antes de
  culpar al número: si la ventana muestra la pantalla de "agrega tu número" en vez
  de ofrecerte conectar tu cuenta existente, la función no está activa. Revisa,
  **por este orden**: que el paso *Products* de la configuración tenga WhatsApp
  Cloud API marcado (es la causa más probable y la menos evidente), y que el
  `featureType` vaya acompañado de `sessionInfoVersion: "3"`.
- **"Has alcanzado el máximo de números".** Un portafolio nuevo está limitado a
  **2 números registrados** hasta que el negocio se verifique o llegue a 2.000 de
  límite de mensajería.
- **Meta autorizó pero no devolvió la cuenta ni el número.** El *session logging*
  no llegó. Suele ser un bloqueador de ventanas emergentes o el dominio ausente
  de la lista de dominios permitidos.
- **Borrar un número de prueba.** Se hace en WhatsApp Manager, con la papelera; no
  se puede por API. Y si el número envió mensajes **de pago**, queda bloqueado
  **30 días** antes de poder borrarse.
