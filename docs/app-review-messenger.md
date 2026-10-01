# App Review de Messenger: expediente

Todo lo que hay que rellenar para pedir acceso avanzado a los permisos de
Messenger, con los textos ya escritos. Mismo formato que
`docs/app-review-instagram.md`, que fue aprobado el 22-sep-2026.

App: **Integra CRM** · `865904982715022` · borrador `1068915315747320`

## Enviada el 28-sep-2026 (28-Sep-2026 14:39 hora de Colombia)

Verificado por API (`devtools_app_review status`): `submission_status: PENDING`,
`is_pending: true`, envío `1068915319080653`. La anterior (Instagram) tardó unos
10 días. Al aprobarse, comprobar `access_level: advanced` en `privileges` para
`pages_messaging`, `pages_manage_metadata`, `pages_show_list` y
`pages_read_engagement`, no sólo que figuren como aprobados.

La llamada de prueba de `pages_messaging` apareció la mañana del 28-sep (62
llamadas) y, además, hubo que certificar en «Uso permitido → Renewal» los cinco
permisos ya aprobados (Instagram, WhatsApp y `public_profile`). Sin esa pestaña
el botón de enviar seguía gris aunque los cinco bloques nuevos estuvieran en verde.

## Estado al 27-sep-2026 (noche): formulario rellenado, falta la llamada de prueba

Todo el formulario está rellenado en el borrador `1068915315747320`. Lo único que
impide enviar es que Meta cuente la llamada de prueba de `pages_messaging`: el
panel dice «0 de 1» aunque el CRM ya contestó por Messenger tres veces (19:56,
20:34 y 21:52). Meta avisa de que tarda **hasta 24 h** en reflejarlas. Mirar el
28-sep por la tarde: si sigue en 0, mandar una respuesta más desde el Chat.

| Bloque | Estado |
|---|---|
| `pages_show_list` | Completo |
| `pages_manage_metadata` | Completo (llamada de prueba: «Completado») |
| `pages_read_engagement` | Completo (llamada de prueba: «Completado») |
| Business Asset User Profile Access | Completo |
| `pages_messaging` | Todo relleno (descripción, vídeo, página Integra, instrucciones, confirmación) salvo la llamada de prueba |
| Tratamiento de datos | Revisado: las respuestas de Instagram (aprobadas el 22-sep) siguen siendo ciertas; se cambió «conversaciones de WhatsApp» por «de WhatsApp, Instagram y Messenger» |
| Instrucciones para revisores | Reescritas para Messenger. «¿Facebook Login integrado?» pasó de *No* a **Sí** |

El vídeo subido en los cinco bloques es `Messenger - App Review (final).mp4`
(1 min 43 s): la toma de las 21:50 sin el login —salía el autocompletado de
Chrome con correos de otras personas—, con la lista de chats personales de
Messenger difuminada y rótulos en inglés en una franja bajo la imagen, para que
no tapen nada.

Textos precargados que eran falsos y se reescribieron: `pages_read_engagement`
decía que analizamos publicaciones, métricas y estadísticas de la página, y no lo
hacemos.

## Estado al 27-sep-2026 (tarde)

| Paso | Estado |
|---|---|
| Caso de uso «Messenger from Meta» en la app | Hecho (24-sep) |
| Configuración de *Inicio de sesión con Facebook para empresas* | Hecho: «Messenger — conectar página», `1723800469162316`, token de usuario, sin activos, 4 permisos |
| `META_FB_LOGIN_CONFIG_ID` en producción | Hecho (24-sep) |
| URI de retorno `/messenger/callback` registrada | Hecho (24-sep) |
| Conectar una página de punta a punta | **Hecho el 27-sep**: página **Integra** (`1426150013911590`) en la empresa 57, instancia 74, suscrita a `messages`, `messaging_postbacks`, `message_reactions`, `message_reads`, `message_echoes` |
| **Recibir un mensaje y contestarlo desde el CRM** | **Hecho el 27-sep**, después de dos arreglos (ver abajo): «prueba a integra» de Juan José entró a la bandeja y la respuesta salió con su `mid` de Meta |
| Llamada de prueba a la API de `pages_messaging` | Hecha el 27-sep a las 19:56 (la respuesta del ensayo). Meta tarda hasta 24 h en contarla: mirar el 28-sep |
| `pages_show_list` en el borrador | **Falta.** Es dependencia obligatoria de `pages_manage_metadata` |
| `pages_read_engagement` en el borrador | Falta (va en la configuración de login, así que se pide) |
| Descripciones, screencast, uso de datos, instrucciones | Textos abajo; screencast por grabar |

### Lo que el ensayo del 27-sep destapó

Nada de esto se habría visto sin probar en producción, y los dos habrían salido
en el screencast:

1. **Los mensajes rebotaban con 419.** `webhooks/messenger` no estaba exenta del
   CSRF (las de WhatsApp e Instagram sí). Meta entregó el mensaje cinco veces y
   las cinco se rechazó. Arreglado en `134b963`, con un test que pregunta al
   middleware directamente.
2. **Contestar fallaba con «Instancia no configurada».** `isMetaConfigured()`
   seguía diciendo que Messenger no estaba construido. Arreglado en `4905ac4`,
   junto con la revisión de salud de las 07:00, que habría marcado la página
   «Sin conexión» cada mañana.

Pendiente menor: el eco de la propia respuesta llega en el mismo segundo en que
se guarda y choca con el índice único de `wamid`. El resultado es correcto (no
se duplica nada y Meta recibe 200), pero deja una línea de ERROR en
`messenger.log` que no es un error.

Y dos cosas para el screencast: con la línea de Messenger elegida, el chat vacío
dice «Integra Plus para WhatsApp»; y la cabecera enseña «Suplantando» si se entra
como master.

### Por qué hasta ahora sólo funcionaba con algunas cuentas

Mientras estos permisos no tengan acceso avanzado, **Meta sólo los concede a
cuentas con rol en la app**. Con cualquier otra el diálogo dice «Esta app
necesita al menos un *supported permission*», que parece un fallo de
configuración y no lo es. El 27-sep le pasó a la cuenta personal de Alejandro;
con la de Juan José Tuiran (administrador) el flujo entero funcionó.

Lo mismo con los mensajes: **en acceso estándar sólo llegan al webhook los de
cuentas con rol en la app.** Para ensayar y grabar, el «cliente» que escribe a
la página tiene que ser una cuenta con rol, o no entrará nada.

### Al enviar, Meta re-revisa lo que ya está aprobado

El borrador arrastra como «Acceso existente para revisar» `public_profile`,
`whatsapp_business_messaging`, `whatsapp_business_management`,
`instagram_business_basic` e `instagram_business_manage_messages`. No se puede
quitar. Es lo normal, pero la solicitud de Messenger pone a revisión también lo
que está en producción.

---

## 1 · Qué permisos pedir, y por qué cada uno

Son exactamente los que usa el código (`MessengerLoginService`,
`MessengerMensajeriaService`):

| Permiso | Para qué lo usa Integra CRM | Llamada |
|---|---|---|
| `pages_show_list` | Listar las páginas del cliente para que elija cuál conectar | `GET /me/accounts` |
| `pages_manage_metadata` | Suscribir la página al webhook para recibir sus mensajes | `POST /{page-id}/subscribed_apps` |
| `pages_messaging` | Recibir los mensajes y contestarlos desde la bandeja | webhook `messages` y `POST /me/messages` |
| `pages_read_engagement` | Leer el nombre y la foto de la página para mostrar cuál está conectada | incluido en la configuración de login |

Recomendado además, aunque no es un permiso sino una **función**:
**Business Asset User Profile Access**. Es lo que deja leer nombre y foto de
quien escribe (`GET /{psid}?fields=first_name,last_name,profile_pic`). Sin ella,
con clientes reales la bandeja mostraría números en vez de nombres.

---

## 2 · Textos de la solicitud (en inglés, que es como revisa Meta)

### `pages_messaging`

> Integra CRM is a customer service platform for internet service providers in
> Colombia. Businesses already handle their WhatsApp and Instagram conversations
> in our shared inbox; this permission lets them answer their Facebook Page's
> Messenger conversations from the same place.
>
> When a person writes to the business's Page, we receive the message through
> the Messenger webhook and show it in the inbox, next to the business's other
> channels. An agent reads it and replies from Integra CRM; the reply is sent
> with the Send API and arrives in the person's Messenger. We only reply to
> conversations the person started, within the standard messaging window, for
> customer support (billing questions, service outages, technical support). We
> do not send promotional or unsolicited messages.
>
> Without this permission the business would have to answer Messenger from a
> separate tool, and its agents would lose the conversation history and the
> assignment of each chat to an agent.

### `pages_manage_metadata`

> We use pages_manage_metadata only to subscribe the business's Page to our app's
> webhook (POST /{page-id}/subscribed_apps) right after the business connects it,
> and to unsubscribe it when they disconnect. This is what makes new Messenger
> messages reach the business's inbox in Integra CRM. We do not change any other
> Page setting. This permission requires pages_show_list, which is included in
> this request.

### `pages_show_list`

> After the business logs in with Facebook Login for Business, we call
> GET /me/accounts to show the list of Pages the person manages, so they can
> choose which Page to connect to their Integra CRM inbox. We only store the ID,
> name and access token of the Page they select. This permission is required by
> pages_manage_metadata, which is also part of this request.

### `pages_read_engagement`

> We use pages_read_engagement to read the connected Page's basic information
> (name and profile picture) and show the business which Page is connected to
> their inbox. We do not read, store or analyze the Page's posts or followers.

### Instrucciones para revisores

> Integra CRM: https://wpp.integracolombia.online
> Test user (our app, not a Facebook account): [usuario Meta Reviewer] / [contraseña]
>
> 1. Log in with the test user. Go to «Instancias» (Instances).
> 2. Click «Conectar Messenger». Facebook Login for Business opens; log in with
>    your Facebook account and select a Page you manage.
> 3. Back in Integra CRM, choose the Page and click «Conectar esta página». It
>    appears as a Messenger card, «Activa».
> 4. From a Facebook account, send a message to that Page in Messenger.
> 5. In Integra CRM open «Chat»: the message appears in the inbox with a
>    Messenger badge. Type a reply and send it.
> 6. The reply arrives in Messenger.
>
> A Page already connected for testing: Integra (1426150013911590).

Credenciales: las **del CRM**, nunca las de una cuenta de Facebook. Meta lo
prohíbe expresamente.

### Tratamiento de datos (Data Use Checkup)

El formulario viene **precargado con las respuestas de Instagram**. Leerlo
entero antes de avanzar. Lo que tiene que decir para Messenger:

- Datos que se reciben: ID y token de la página, mensajes que las personas
  mandan a la página, nombre y foto de quien escribe.
- Uso: mostrar y contestar las conversaciones en la bandeja del negocio.
- Almacenamiento: en nuestra base de datos, sólo para ese negocio (aislado por
  empresa). Nada se vende ni se comparte con terceros.
- Borrado: al desconectar la página y por la URL de eliminación de datos
  (`https://integracolombia.co/eliminacion-de-datos.html`).

---

## 3 · Lo que Meta pide dentro del formulario (no está en la documentación)

Leído en el borrador el 27-sep-2026, en el modal de `pages_messaging`:

- **Screencast con el flujo de autorización OAuth incluido.**
- **«0 de 1 llamadas de prueba a la API necesarias».** Hasta que haya un envío
  real por `pages_messaging`, no deja completar el paso. Tarda hasta 24 h en
  contar.
- **Seleccionar una página** para que el revisor pruebe.
- *«Crea una cuenta real en Facebook y otórgale el rol de evaluador. No envíes un
  usuario de prueba creado en Roles de la app: no pueden recibir mensajes de
  bots.»*
- `pages_manage_metadata`: *«La solicitud debe contener pages_show_list»*.

---

## 4 · El screencast

Un archivo por permiso; el mismo vale para los cuatro. Pantalla del CRM y
Messenger **lado a lado**, sin cortes, con rótulos cortos en inglés. El montaje
con `ffmpeg` y cómo auditarlo sin verlo están en la memoria del proyecto
(«screencast del App Review»).

Tomas, en este orden:

1. CRM → Instancias. Rótulo: *Business connects its Facebook Page*.
2. «Conectar Messenger» → diálogo de **Facebook Login for Business** entero: la
   pantalla de páginas (eligiendo **una**) y la de permisos. Rótulo: *OAuth:
   the business grants access to one Page*.
3. De vuelta en el CRM, «Conectar esta página» → tarjeta «Activa».
4. Messenger (otra cuenta, **con rol en la app**): escribir a la página. Rótulo:
   *A customer writes to the Page in Messenger*.
5. CRM → Chat: aparece el mensaje con la etiqueta Messenger. Rótulo: *The
   message arrives in the Integra CRM inbox*.
6. Contestar desde el CRM → la respuesta llega a Messenger. Rótulo: *The agent's
   reply arrives in Messenger*.

Para que Facebook vuelva a enseñar la pantalla de permisos hay que borrar la
conexión **por los dos lados**: eliminar la instancia en el CRM y quitar la app
en Facebook → Configuración → **Integraciones comerciales** → Integra CRM →
Eliminar. Si no, Facebook dice «ya vinculaste Integra CRM» y se salta justo lo
que el revisor quiere ver.

Antes de grabar: quitar la **suplantación** de la cabecera del CRM (sale en
ámbar «Suplantando») entrando directamente con el usuario Meta Reviewer.

---

## 5 · Checklist antes de enviar

- [x] Ensayo real: mensaje de una cuenta con rol → llega al CRM → respuesta sale (27-sep)
- [x] Llamada de prueba de `pages_messaging` contada (28-sep, 62 llamadas)
- [x] `pages_show_list` y `pages_read_engagement` añadidos al borrador
- [x] *Business Asset User Profile Access* añadido
- [ ] Cuenta de evaluador para el revisor, según pide Meta (no se ha creado; el revisor usa la suya)
- [x] Descripciones pegadas (sección 2)
- [x] Screencast grabado, auditado y subido en los cinco bloques
- [x] Data Use Checkup revisado
- [x] Instrucciones para revisores con el usuario Meta Reviewer (id 89, empresa 57)
- [x] Enviada y comprobada por API: `is_pending: true` (28-sep)
