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

### Estado: el diagnóstico del 5-sep se hizo sin un número candidato (2026-09-07)

Lo que se creyó el 5-sep —"falta que Meta habilite la función"— se dio por
bueno probando con números que **ya estaban en Cloud API**, donde la
coexistencia no aplica y Meta no tiene por qué ofrecer el QR.

Auditoría contra Graph v25, instancia por instancia y con el token de cada una
(48 activas):

- **35 números con `platform_type: CLOUD_API` y ninguno en la app de WhatsApp
  Business.** No hay en la flota un solo caso donde la coexistencia sea
  aplicable.
- El caso que disparó todo —RH Comunicaciones, WABA `1743777786582767`, número
  `+57 301 9514710`— es `CLOUD_API` / `CONNECTED` / `VERIFIED`. El flujo no
  estaba fallando; el número no era candidato.
- Las WABAs se reparten en dos mundos: 19 en el portafolio **Integra Colombia
  SAS** suscritas a la app **Integra** (`1862365350983129`, sin App Review), y
  17 en **Ispintegra** suscritas a la app aprobada. Todas `ownership_type:
  SELF` salvo IntegraPay. O sea: no se onboardea el portafolio del cliente, se
  le crea la WABA dentro del propio — y la coexistencia está pensada justo para
  lo contrario.

Lo que sí quedó verificado y **no** es la causa: `featureType` +
`sessionInfoVersion: "3"` viajan de verdad (comprobados decodificando el
parámetro `extras` de la URL real del diálogo, no sólo en el bundle); el diálogo
abre en **v25.0** con `config_id=1739855773915048`; configuración v4 con
WhatsApp Cloud API en *Products* (reconfirmado el 7-sep abriendo el asistente:
la casilla está marcada y bloqueada, *"This can't be changed later"*); Tech Provider con acceso avanzado; Colombia no
está excluida.

**Bloqueo actual, anterior a la coexistencia.** El diálogo no abre para una de
las dos cuentas administradoras: *"Función no disponible — el inicio de sesión
con Facebook no está disponible debido a que estamos actualizando otros detalles
de la app"*. Es **por cuenta**, no por rol ni por navegador: reproducido en
Chrome de escritorio (incógnito) y en el navegador del celular (sesión normal,
otro dispositivo) con la misma cuenta, que figura como **Administrador** en
*Roles de la app*; con la otra cuenta administradora el diálogo sí abre y llega
hasta "Agrega tu número de teléfono".

No lo explica nada configurable. Verificado el 7-sep y descartado como causa:
app en `live_mode` / `is_live: true`, sin `location_restriction`; **Verificación
del negocio** = Verificado y **Verificación de acceso** = Verificado como
proveedor de tecnología (`Revisar > Verificación`); ninguna acción requerida
pendiente; las tres URLs legales y la del sitio responden 200.

`contact_email_verified: false` llegó a señalarse como sospechoso y **no lo es**:
no hay forma de dispararlo desde el panel (no existe botón de verificar, y
cambiar el valor no genera correo), y el fallo le ocurre igual a un
administrador. No perder tiempo ahí otra vez.

De paso, la *URL del sitio* de la app apuntaba a `whatsapp.integracolombia.com`,
que no resuelve (curl devuelve 000); se corrigió a `wpp.integracolombia.online`
el 2026-09-07.

**La configuración NO era el problema (probado el 7-sep).** La guía de versiones
dice que la v4 se obtiene *creando* una configuración nueva con los productos
marcados, no editando una existente — y la nuestra (`1739855773915048`) nació de
una plantilla y se le marcó *Products* después. Parecía la explicación. Se creó
una configuración limpia siguiendo la guía al pie de la letra
(**`1686339276244548`**, "Coexistencia v4": variación Registro insertado de
WhatsApp, WhatsApp Cloud API en Products **durante la creación**, sin marketing,
token de usuario del sistema a 60 días, activo sólo Cuentas de WhatsApp,
permisos `whatsapp_business_management` + `whatsapp_business_messaging`), y se
probó abriendo el diálogo a mano con ese `config_id`: **misma pantalla estándar**.
No repetir el experimento.

La configuración nueva se dejó creada. Conviene migrar a ella de todos modos
antes del apagado de v2 (15 de octubre de 2026), pero eso es independiente de la
coexistencia.

Queda también anotada una contradicción entre las dos guías de Meta: la de
coexistencia manda `featureType` + `sessionInfoVersion: "3"` en `extras`, y la de
versiones dice que en v4 `extras` va **vacío** y que la información de sesión "se
devuelve en todos los flujos". Va como pregunta en el ticket.

**Cuatro variantes probadas y descartadas el 7-sep** (abriendo el diálogo a mano,
sin tocar producción): configuración nueva v4 creada según la guía; `extras` sin
`sessionInfoVersion`; `version: "v3"` forzado en `extras`; y la configuración
vieja. Las cuatro sirven la misma pantalla estándar.

**Lo que dice la investigación externa (BSPs y foros, 7-sep):**

- La elegibilidad de la coexistencia es **por número y por antigüedad**: Meta pide
  que el número lleve **al menos 7 días activo** en la app de WhatsApp Business, y
  si estuvo registrado antes en una WABA hay un enfriamiento de **1–2 meses**. La
  app debe abrirse al menos cada 13 días. Nunca borrar y volver a registrar el
  número.
- Pero eso **no explica lo nuestro**: la pantalla de coexistencia aparece *antes*
  de escribir ningún número, así que su ausencia no puede depender del número. Es
  de nivel app. Además, cuando el número no es elegible Meta muestra otro error
  ("Your phone number isn't eligible… More activity on the WhatsApp Business App
  is needed"), no el `#2494064` que nos sale.
- Ser **Tech Provider es requisito duro** y es lo que separa a quien lo consigue
  de quien no (los Chatwoot self-hosted fallan por esto; el Chatwoot Cloud
  funciona). Nosotros ya lo somos.
- El tipo de función `coex` **no migra automáticamente a v4** porque "no encaja
  limpiamente en el modelo de selección de productos de v4". Es la señal más
  clara de que coexistencia + v4 es un borde áspero de la plataforma.
- Varios BSP describen el flujo del cliente empezando **desde el celular**:
  WhatsApp Business > Configuración > ... > *Sign Up with Facebook*, con QR.
  **Comprobado el 7-sep en el +57 318 145 4747: esa opción NO aparece.** Es otro
  indicio de que la función no está disponible para esta app, este número o esta
  región — aunque no es concluyente, porque ese punto de entrada no está en la
  documentación oficial de Meta, sólo en la de los BSP.

**Los dos canales de Meta están rotos (7-sep).** Asistencia directa
(`business.facebook.com/direct-support`, tema *WhatsApp Tech Provider:
Onboarding*, tipo *Embedded Signup - Coexistence Onboarding*) devuelve "Se
produjo un error al crear tu pregunta" en **tres** intentos, variando longitud de
descripción, campos opcionales y tema. El tema *Dev: Onboarding* ni siquiera
ofrece el tipo de coexistencia. Y la Herramienta de errores
(`developers.facebook.com/support/bugs`) se queda bloqueada en el paso *Producto
afectado*: con "API de WhatsApp Business > Registro insertado" seleccionado, el
botón *Siguiente* permanece deshabilitado. Queda como única vía el foro público
de la comunidad, donde ya hay un hilo de otro Tech Provider con el mismo síntoma.

### La causa más probable: Meta cambió la UI para todos (7-sep-2026)

La guía de versiones trae una nota fácil de pasar por alto: *"La UI actualizada,
que está disponible en la vista previa pública, **se implementará en todas las
versiones del registro insertado a principios de septiembre**."*

Encaja con todo lo observado:

- Las **cuatro** configuraciones probadas —la vieja, la nueva v4
  (`1686339276244548`) y una creada **desde plantilla sin tocar Products**
  (`28403787509263378`, que debería ser v2/v3)— sirven **la misma UI
  consolidada**. La UI ya no depende de la versión de la configuración.
- Abrir el diálogo en **v24.0** en vez de v25.0 tampoco la cambia. Tampoco
  depende de la versión del diálogo.
- Otro proveedor (TecnoChat, app `737014349457970`) sí ve la pantalla clásica
  *"Selecciona los activos comerciales · Portfolio empresarial · Cuenta de
  WhatsApp Business"*, pero su URL lleva `cbt=1771145…`, un timestamp de
  **febrero de 2026**: es una captura de la UI vieja, de antes del despliegue.

El punto de entrada de la coexistencia vivía en esa pantalla de selección de
WABA. En la UI consolidada esa pantalla no existe, y la coexistencia no está
cableada en su lugar. Por eso ninguna combinación de `config_id`, `featureType`,
`sessionInfoVersion`, `version` ni versión de diálogo cambia nada: **la UI se
decide en el servidor de Meta**.

**Vincular la cuenta de la app de WhatsApp Business al portafolio es el PASO
PREVIO OBLIGATORIO (corregido el 8-sep).** Por sí solo no completa nada —de ahí
la nota original—, pero sin él el registro insertado no se bifurca a
coexistencia. El orden correcto es: vincular primero en el portafolio del
cliente, y sólo después abrir el registro insertado y **escribir el número** en
"Enter a new phone number". Nota original, que se quedó a medias: Desde Ispintegra > Cuentas de WhatsApp > Agregar >
*Vincular cuenta de WhatsApp Business* se reclamó el +57 318 145 4747 como
activo (id `1421384372768123`, tipo "App de WhatsApp Business", estado **Sin
conexión**). Es un activo de Business Suite —sirve para poner WhatsApp como
método de contacto de la tienda—, no el registro en Cloud API. Comprobado
después: el diálogo sigue igual y el número **no** aparece como candidato en el
desplegable. No hace daño y conviene dejarlo, pero no es el desbloqueo.

**Por qué el desplegable muestra los números del portafolio "equivocado".** No
lista el portafolio en el que estás: lista los activos **que aún no están
conectados a la app que abre el diálogo**. Las 17 WABAs del portafolio Ispintegra
ya están suscritas a la app Ispintegra, así que no hay nada que onboardear y no
aparecen (Comuna13 entre ellas). Las 19 de Integra Colombia SAS cuelgan de la app
**Integra**, otra app distinta, así que para Ispintegra siguen "libres" y sí
aparecen. No es un error de configuración; es el reflejo del reparto de
[[reparto-wabas-apps-meta]].

**Orden correcto para cerrar esto:**

1. Verificar el correo de contacto de la app.
2. Probar con un número que siga en la app de WhatsApp Business y nunca haya
   pasado por Cloud API (`+57 318 145 4747` sirve), con una sesión sin rol en la
   app.
3. Si sale el QR, funcionaba desde el principio.
4. Si sale "Agrega tu número de teléfono", **ahora sí** el ticket a Meta por
   Soporte directo: Question Topic **"WABiz: Onboarding"** + **"TechProvider:
   Onboarding"**, Request Type **"Embedded Signup - Coexistence Onboarding"**.
   Que exista una ruta de soporte con ese nombre exacto es la señal de que la
   habilitación no es automática.

Aparte, y sin relación con la coexistencia: **13 de las 48 instancias activas no
pueden leer su propio `phone_number_id`** (error `100/33`) — ids 2, 3, 7, 8, 11,
23, 25, 26, 31, 37, 38, 51, 53. La 2 tiene un `phone_number_id` de relleno
(`123456123`).

### El volcado de los tres campos de coexistencia

| Pieza | Responsabilidad |
| --- | --- |
| `WhatsAppWebhookController::encolarCoexistencia` | Resuelve la instancia por `phone_number_id` y encola. Nada más: el controlador debe responder 200 rápido. |
| `ProcesarWebhookCoexistencia` (job) | Saca el trabajo de la petición. Un `history` puede describir **miles** de mensajes y si tardamos Meta reintenta el lote entero. |
| `CoexistenceIngestService` | El volcado de verdad: `history` → conversaciones y mensajes, `smb_app_state_sync` → contactos, `smb_message_echoes` → lo que el negocio responde desde el celular. |
| `CoexistenceSync` (modelo) | Una fila por instancia, con índice único. Es el candado del "un solo intento" y la fuente de la barra de progreso. |

Decisiones que no son obvias y conviene no deshacer:

- **Idempotencia por `wamid`.** `firstOrCreate` sobre una columna única, y
  `wasRecentlyCreated` decide si contar. Meta reenvía el lote completo si el
  callback responde 500, así que reprocesar tiene que ser inofensivo.
- **El último mensaje no se pisa con cualquier cosa.** El historial llega hacia
  atrás en el tiempo y desordenado; sólo se actualiza `last_message` si el
  mensaje es más nuevo que el que ya había. Si no, el listado de chats anuncia
  como "último" algo de hace seis meses.
- **Un `remove` de contacto no borra nada.** Significa que el negocio lo quitó de
  la agenda de su teléfono, no que quiera perderlo del CRM, donde cuelgan
  conversaciones y etiquetas. Se registra en el log y ya.
- **El progreso sale de Meta, no de un contador nuestro.** Cada webhook de
  `history` trae `metadata.progress` (0–100, **por fase**) y `metadata.phase`
  (0, 1, 2). Sólo se avanza, nunca se retrocede: los lotes llegan desordenados
  —para eso existe `chunk_order`— y una barra que baja parece un fallo.
- **Los defaults viven en el modelo, no sólo en la migración.** `firstOrCreate`
  deja como `null` las columnas con DEFAULT en la base, y `0 === null` es falso:
  con eso la barra se quedaba clavada en cero toda la importación. Está en
  `CoexistenceSync::$attributes`.
- **`media_placeholder`.** Los adjuntos no vienen en el hilo; llegan en un
  webhook `history` posterior con la forma normal (`value.messages[]`) y sólo
  para los mensajes de las dos últimas semanas. Hasta entonces el chat muestra
  que hubo un archivo en vez de un hueco.
- **El código `2593109` no es un error.** Es que el negocio eligió no compartir
  su historial. La sincronización queda como `rechazada`, no como `fallida`.

Cubierto por `tests/Feature/CoexistenceIngestTest.php`.

### El disparo automático de la importación

| Pieza | Responsabilidad |
| --- | --- |
| `EmbeddedSignupController@store` | Encola el job al crear la instancia. Una línea, sin ramas: decidir si aplica es del job. |
| `IniciarSincronizacionCoexistencia` (job) | Comprueba `is_on_biz_app`, reserva el intento y pide a Meta contactos e historial, en ese orden. |
| `MetaWhatsAppService::startSmbDataSync` | La llamada a `POST /{phone_number_id}/smb_app_data`. |
| `coexistencia:vigilar` (cada hora) | Avisa de las importaciones estancadas antes de que expire la ventana. |

Lo que no es evidente:

- **`requested_at` es el marcador del intento gastado, no la existencia de la
  fila.** La fila puede haberla creado antes el volcado de un eco del celular,
  que llega en cuanto el negocio responde y no tiene nada que ver con la
  importación. Bloquear por la fila dejaba al cliente sin historial y sin que
  nadie se enterase. Hay un test para exactamente eso.
- **`tries = 1`.** Un reintento automático sobre una llamada que Meta pudo haber
  aceptado quema la única oportunidad del cliente. Si falla, queda `fallida` en
  la base y se decide a mano, con la ventana todavía abierta.
- **Se marca como solicitada ANTES de llamar a Meta.** Si el proceso muere entre
  la llamada y el guardado, es preferible perder el `request_id` a volver a
  pedirlo y gastar el intento de verdad.
- **Primero contactos, después historial.** Cuando entran las conversaciones ya
  tienen a quién colgarse, y el cliente ve nombres en vez de números.
- **Que falle una de las dos no es fallo.** La otra sigue su curso y media
  importación es mejor que ninguna. Sólo si fallan las dos queda `fallida`.
- **`is_on_biz_app` se consulta antes de pedir nada.** En un registro normal no
  hay historial en ningún celular: pedirlo gastaría el intento para traer cero.
- **Versión de Graph aparte.** `smb_app_data` e `is_on_biz_app` no existen en la
  v21 con la que envían los clientes en producción; va en
  `META_COEXISTENCE_API_VERSION`, por defecto v25.0. Subir `api_version` movería
  el suelo a todos los envíos para arreglar una función que usa un número.

El vigilante avisa dos veces: a las **4 horas** sin avance, cuando queda margen
para reaccionar, y al cerrarse la ventana, marcando la importación como fallida
para que la pantalla deje de prometer algo que ya no va a llegar.

Cubierto por `tests/Feature/CoexistenceSyncStartTest.php`.

### El progreso en pantalla

| Pieza | Responsabilidad |
| --- | --- |
| `CoexistenceSync::porcentajeGlobal` | Traduce el progreso **por fase** de Meta a un avance continuo de 0 a 100. |
| `CoexistenceSync::paraPantalla` | La forma que consume el frontend. Una sola, para los dos caminos. |
| `CoexistenceSyncEvent` | Empuja el avance por Reverb, en el canal `instance.{id}` que ya existía. |
| `InstanceController@coexistenceSync` | El respaldo por consulta, para cuando el websocket no conecta. |
| `CoexistenceSyncCard.jsx` | La tarjeta dentro de cada instancia en `/instances`. |

Lo que hay detrás de las decisiones:

- **El porcentaje se reparte entre las tres fases.** Meta reporta `progress` de
  0 a 100 **por fase**, así que enseñarlo tal cual haría que la barra llegara al
  tope tres veces. Y nunca marca 100 mientras la importación siga viva: una
  barra llena hace que el cliente se vaya de la pantalla.
- **La misma forma por websocket y por consulta.** Si las dos vías entregaran
  cosas distintas, el frontend tendría que traducir según por dónde llegó, y el
  camino menos usado —el de respaldo— sería el que se rompiera sin que nadie lo
  notara. Un cliente detrás de un proxy de oficina que cierra los websockets
  tiene que ver exactamente lo mismo.
- **El estado viaja también en las props de Inertia.** Si sólo llegara por
  websocket, un cliente que recarga a mitad vería una pantalla vacía y pensaría
  que el proceso se perdió.
- **La respuesta va envuelta en `sync`.** Un `null` a secas se serializa como
  `{}`, que en JavaScript es verdadero: la pantalla pintaba una tarjeta de
  progreso hueca para instancias que nunca tuvieron importación.
- **El rechazo se cuenta con palabras.** Si el negocio eligió no compartir sus
  chats, la tarjeta lo explica en ámbar, no en rojo: es una decisión suya y
  mandarlo a soporte por eso es ruido.
- **La consulta de respaldo se apaga al terminar.** Seguir preguntando cada
  cinco segundos por algo que ya no cambia es carga en el servidor por nada.
- **El evento necesita el trait `Dispatchable`.** Sin él `::dispatch()` no
  existe, y como el emisor traga excepciones para no tumbar la importación, el
  fallo era completamente silencioso. Lo cazó un test.

Cubierto por `tests/Feature/CoexistenceProgressTest.php`.

### La guía dentro del producto

| Pieza | Responsabilidad |
| --- | --- |
| `Instances/GuiaCoexistencia.jsx` | Los siete pasos con sus capturas, en `/instances/guia-coexistencia`. |
| `CoexistenciaPrevioDialog.jsx` | Lo que hay que saber y confirmar **antes** de abrir la ventana de Meta. |
| `public/img/coexistencia/` | Las quince capturas reales del proceso, comprimidas. |

- **El aviso previo dejó de ser un `<details>`.** El contenido ya existía
  colapsado debajo del botón, que es como no tenerlo. Ahora es un modal que hay
  que atravesar, con cuatro casillas que confirman los requisitos —app Business,
  más de una semana de uso, cuenta de Facebook, celular a la mano— y el botón
  deshabilitado hasta marcarlas. Ahí se caían casi todos los intentos.
- **La cuenta de Facebook se pide en el paso 2 y no en el 3.** El paso del
  portafolio decía «se crea con tu cuenta de Facebook de siempre», dando por
  supuesto lo único que no se puede improvisar a mitad del proceso: quien no la
  tenía se enteraba con el portafolio ya empezado (19-sep-2026).
- **El paso previo en Meta Business Suite sale en ámbar dentro del modal.** Es
  el que más se salta y el que provoca el `#2494064`, que parece un problema del
  número del cliente y no lo es.
- **Cada paso lleva su etiqueta de dónde ocurre**, escritorio o celular. La
  mitad del proceso pasa en el teléfono y perder ese hilo es lo que hace que la
  gente abandone a la mitad.
- **La ruta va antes que `/instances/{instance}`**, o `guia-coexistencia` se
  tomaría por el id de una instancia.
- **La guía es la misma que el PDF externo**, pero servida desde el producto: el
  cliente la necesita en el momento en que está mirando el botón, no en un
  archivo que alguien le mandó por correo la semana pasada.

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

### Dos rechazos del número que parecen de requisitos y no lo son

Los dos le salieron a NOVA Partners el **24-sep-2026**, uno detrás del otro, al
conectar el +57 304 587 4126 por coexistencia.

**«No cumple los requisitos» en el desplegable.** Tras el paso de Meta Business
Suite, el número aparece en la lista de «Agrega tu número de teléfono» con esa
etiqueta y el aviso «Este número no se puede compartir con esta app». No es el
número: esa lista sólo comparte números que ya están en Cloud API, y uno de la
app no lo está. Hay que dejar «Ingresar un nuevo número de teléfono» y
escribirlo; Meta reconoce entonces que está en la app y pasa al QR. El
asistente ya lo avisaba en el paso 5, y aun así el cliente eligió de la lista.

**`#3441060`: «Tu agente de Business AI ya está vinculado a este número».** El
cliente tenía activadas las respuestas con IA de Meta en la app. Mientras estén
vinculadas, Meta no deja conectar el número a ninguna plataforma. Se quita en
la app: *Herramientas empresariales → Tu Business AI → Respuestas de IA →
Desvincular IA*. Meta sólo lo dice **después** de escribir el número, así que
ahora es la quinta casilla del paso 2 del asistente y figura en la guía.

Y un efecto secundario de esos intentos: la WABA se crea en Meta aunque la
ventana no llegue al final, y queda «Revisión en curso» sin que el CRM tenga
instancia. Al repetir, hay que elegir **esa** cuenta, no crear otra.

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
- Y los cuatro de plantillas (ver la sección siguiente):
  `message_template_status_update`, `message_template_quality_update`,
  `template_category_update`, `message_template_components_update`.

## Los avisos de plantillas (2026-10-01)

**Qué pasaba.** Hasta el 1-oct-2026 el webhook descartaba sin leerlos los campos
de plantillas, y la mayoría de apps ni siquiera estaban suscritas a ellos. Una
plantilla que Meta pausaba por quejas seguía figurando como aprobada en tres
sitios a la vez —la caché del guardarraíl de parámetros, el estado del respaldo
fuera de ventana y las campañas—, y lo único que veía la empresa era su campaña
fallando con `132015`, destinatario a destinatario, sin que nadie le dijera por
qué ni hasta cuándo. Mientras, Ajustes ponía en verde «Webhook suscrito a la
WABA — necesario para recibir aprobación/rechazo de plantillas»: verde con la
app suscrita sólo a `messages`, y sin código que hiciera nada con ellos.

**Qué hace ahora** (`App\Services\EventosDePlantilla`):

| Aviso | Efecto |
| --- | --- |
| Cualquiera | Tira `wa:templates:{waba}`, la caché del catálogo de `TemplateParameterGuard` |
| `PAUSED`, `DISABLED`, `REJECTED` | Pausa las campañas `queued`/`sending` con esa plantilla (mismo estado que el botón «Pausar») y avisa a los administradores con el motivo en español |
| `FLAGGED`, calidad `RED` | Avisa: es el paso previo a la pausa |
| Cambio de categoría | Avisa, tanto el anuncio (`correct_category`) como el hecho; si pasa a marketing, dice que cuesta más |
| Edición de la plantilla | Invalida el estado del respaldo, cuyo cuerpo guardado ya no es el de Meta |

Si la plantilla es la de respaldo de alguna instancia, se apunta el estado nuevo
sin consultar el Graph (el aviso ya lo trae).

**Tres cosas que no hay que "arreglar":**

- **`entry.id` es la WABA, no el número.** El aviso vale para todas las
  instancias con ese `waba_id`, **de todas las empresas**: una WABA puede estar
  conectada en dos. Cada empresa recibe su aviso con sus campañas, no las de la
  otra.
- **Un aviso de una plantilla o WABA desconocida no lanza.** El webhook
  respondería 500 y Meta lo reintentaría durante siete días.
- **La idempotencia es por `template_id + evento + entry.time`** en caché, ocho
  días (más que los siete de reintentos de Meta). Si el proceso falla a medias se
  borra la marca, para que el reintento lo haga entero.

**El `DISABLED` de Meta se guarda como `META_DISABLED`.** El respaldo ya usaba
`DISABLED` para «la empresa lo apagó en Ajustes»; guardar el de Meta con el mismo
nombre hacía que la pantalla dijera «Desactivado» con el interruptor encendido.
`PAUSED` (TTL 30 min: la pausa dura 3 h o 6 h) e `IN_APPEAL` tienen etiqueta
propia. Un envío del respaldo que vuelve con `132001`, `132015` o `132016`
invalida el estado guardado (`WhatsAppFallbackTemplateService::refrescarTrasError()`),
en vez de esperar las 12 h de TTL de una aprobada.

**La suscripción es de la app, no de la WABA.** Los campos se eligen en
`POST /{app_id}/subscriptions` y valen para todas las WABAs de esa app. Meta
**sustituye la lista entera** en cada POST: mandar sólo los campos nuevos daría de
baja `messages` y dejaría de entrar todo. Por eso
`WhatsAppSettingsController::ensureAppWebhookFields()` lee los actuales y reenvía
la unión. Se añaden al pulsar «Suscribir» en Ajustes y al habilitar llamadas; el
check de Ajustes queda en acción mientras falte alguno, y
`php artisan whatsapp:check-subscription` lo señala como problema.

## Los acuses de entrega (2026-10-01)

`App\Services\EstadosDeMensaje`, llamado desde el webhook:

- **Un acuse no hace retroceder al mensaje.** Meta no garantiza el orden: un
  `delivered` tardío borraba el `read`. `failed` sí puede llegar tras `sent`.
- **El acuse que llega antes que el wamid se guarda 10 minutos** en
  `wa:estado-pendiente:{wamid}`. Todo código que guarde el wamid de un envío debe
  llamar después a `WhatsAppWebhookController::aplicarEstadoPendiente($mensaje)`
  (ya con el mensaje en `sent`). Se eligió esto y no responder 500: los acuses de
  mensajes enviados por fuera del CRM (otra app en el mismo número) no tendrán
  nunca wamid aquí, y con un 500 Meta reenviaría el lote entero durante siete días.
- **Un fallo al guardar sí responde 500**, para que Meta reintente; reintentar es
  seguro porque un acuse repetido no hace retroceder nada.
- **El tiempo real va al canal de la instancia del mensaje**, no al de la que
  recibió el webhook: con dos empresas en el mismo `phone_number_id` son distintas.
- Lo que Meta cobra (`pricing`, `conversation`) se guarda en `metadata.pricing`.

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
