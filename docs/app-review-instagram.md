# App Review de Instagram: expediente listo para enviar

Todo lo que hay que rellenar, con los textos ya escritos y los valores exactos.
Falta una sola cosa que no se puede adelantar, y está explicada abajo.

App: **Integra CRM** · `865904982715022`
Estado hoy: última solicitud **aprobada**, ninguna pendiente, `can_submit: true`.

---

## El orden importa, y no es el que parece

Meta exige **ver la función andando** antes de dar acceso avanzado. No se puede
grabar lo que no existe, así que la solicitud no va primero: va cuarta.

1. ~~**Configurar el producto Instagram** en el panel~~ — **hecho el 10-sep-2026**,
   salvo el webhook, que no se puede: ver «El webhook no va aquí» más abajo
2. **Construir la integración mínima** — recibir un DM y contestarlo desde el CRM
3. **Suscribir el webhook**, ya con el endpoint contestando
4. **Probarla con nuestra propia cuenta profesional de Instagram**, que el
   acceso estándar ya permite sin ninguna aprobación
5. **Grabar el screencast** con eso funcionando
6. **Enviar la solicitud** de acceso avanzado

El paso 3 es la clave y mucha gente no lo sabe: **acceso estándar sirve para
cuentas propias añadidas en el panel**. O sea que se puede construir, probar y
grabar entero sin esperar a Meta. La aprobación sólo hace falta para servir
cuentas de clientes.

Como la revisión anterior tardó **9,7 días**, conviene enviarla en cuanto el
screencast esté grabado y seguir construyendo mientras tanto.

---

## 1 · Configuración en el panel

**Ya no hay menú «Productos».** Esta app está en el modelo de *casos de uso*, y
el camino real es:

**Casos de uso → Agregar casos de uso → Administración de contenido →
«Administrar mensajes y contenido en Instagram» → Personalizar**

Dentro, la pestaña que sirve es **«Configuración de la API con inicio de sesión
con Instagram»** (hay otra casi igual, *con inicio de sesión con Facebook*, que
es el camino viejo y no es el nuestro).

### Añadir el caso de uso crea una segunda app

Esto no está en la documentación y conviene saberlo antes: al añadirlo, Meta
crea sola una app de Instagram aparte, con **identidad propia**:

| | |
|---|---|
| Nombre | **Integra CRM-IG** |
| Instagram App ID | **`28822685693981719`** |
| Clave secreta | en la misma pantalla, botón «Mostrar» |

**No son los de Facebook** (`865904982715022`). El *client_id* del OAuth de
Instagram es el de arriba; la clave secreta es la que va a la lista de
`META_APP_SECRETS`, junto a la de WhatsApp, no en su lugar — el validador de
firmas ya prueba todas las entradas ([[env-meta-app-secrets]]).

### URLs que hay que registrar

| Campo | Valor | Estado |
|---|---|---|
| OAuth redirect URI | `https://wpp.integracolombia.online/instagram/callback` | ✅ guardada |
| Deauthorize callback URL | `https://wpp.integracolombia.online/instagram/desautorizar` | ✅ guardada |
| Data deletion request URL | `https://wpp.integracolombia.online/instagram/eliminar-datos` | ✅ guardada |
| Webhook callback URL | `https://wpp.integracolombia.online/webhooks/instagram` | ✅ verificada y guardada |

Las tres primeras están en dos sitios distintos y por eso se pasan por alto: la
de redirección se pide en el paso **4. Configurar el inicio de sesión de empresa
de Instagram → Configurar**, y las otras dos sólo aparecen si, *después*, se
despliega ese mismo paso 4 y se pulsa **«Configuración de inicio de sesión del
negocio»**. Ahí están las tres juntas.

**Las tres tienen que responder 200 antes de enviar la solicitud**: el revisor
las visita. Hoy ninguna existe todavía en el código.

Las dos últimas —desautorizar y eliminar datos— son obligación legal, no
trámite: Meta avisa por ahí cuando un usuario revoca el acceso o pide borrado, y
hay que atenderlas de verdad.

### El webhook va después de construir el endpoint

El paso 3 del panel tiene los campos, pero **guardar es «Verificar y guardar»**:
Meta llama al momento con `hub.challenge` y sólo acepta la URL si algo contesta.
Sin `routes/web.php` publicando `/webhooks/instagram`, el botón falla — no es un
formulario que se rellene por adelantado.

Por eso el webhook se movió detrás de construir el endpoint, y por eso el orden
de arriba tiene seis pasos y no cinco. Desplegado el endpoint, la verificación
pasó a la primera y el paso 3 quedó en verde (10-sep-2026).

**Y va en el producto, no en la página general de Webhooks.** Si se entra por
*Casos de uso → Webhooks → Producto: Instagram*, Meta avisa en amarillo: «Las
configuraciones de Webhooks para la API de Instagram con inicio de sesión de
empresa de Instagram solo se admiten dentro del producto». Los campos de esa
pantalla no sirven para este camino.

### Los campos del tópico llegan con la cuenta, no antes

`messages`, `messaging_postbacks` y compañía **no se marcan a mano** en Instagram
Login. El paso 2 del panel lo dice sin subrayarlo: «Agrega una cuenta de
Instagram para generar tokens de acceso **y configurar suscripciones a
webhooks**». O sea que la suscripción a campos es por cuenta conectada y llega
con el OAuth, no antes.

Lo que se comprueba por API mientras tanto es que **no se rompió nada**:
`devtools_webhook_list` sobre `865904982715022` sigue devolviendo un solo tópico,
`whatsapp_business_account`, con sus doce campos y `enabled: true`.

### La trampa que costó la primera verificación

`META_IG_WEBHOOK_VERIFY_TOKEN` estaba documentada como «si se deja vacía usa la
de WhatsApp», y era mentira. El bloque `x-app-env` del compose la declara como
`${META_IG_WEBHOOK_VERIFY_TOKEN:-}`, así que sin valor en el `.env.docker` la
variable llega al contenedor **definida y vacía**, no ausente — y `env()` sólo
usa su valor por defecto cuando la clave **no existe**.

Resultado: el endpoint devolvía 403 con el token correcto. Se arregló con `?:` en
vez del segundo argumento de `env()`, con prueba que lo fija. Merece la pena
recordarlo porque afecta a **cualquier** variable nueva de este proyecto: en
Docker, «vacía» y «sin declarar» no son lo mismo.

### Permisos a solicitar

- `instagram_business_basic`
- `instagram_business_manage_messages`

**Sólo esos dos.** Pedir `instagram_business_manage_comments` o
`instagram_business_content_publish` sin usarlos alarga la revisión y da motivos
para rechazar. Si más adelante hacen falta, se piden aparte.

**Cuidado con el botón «Add all required permissions»** del paso 1 del panel:
mete **tres**, con `instagram_business_manage_comments` incluido. No se ha
pulsado a propósito. Los permisos se añaden de uno en uno desde **Permisos y
funciones → Agregar a revisión de la app**, y eso ya es el expediente de
revisión, así que se hace con el screencast grabado, no antes.

Para construir y probar con nuestra propia cuenta no hace falta añadir nada: el
acceso estándar del caso de uso ya lo permite.

### Webhook

Tópico `instagram`, con estos campos:

`messages` · `messaging_postbacks` · `messaging_seen` · `message_reactions` ·
`messaging_referrals` · `messaging_optins`

Va en su propio `callback_url`, distinto del de WhatsApp: son tópicos separados
y caben los dos en la misma app. Comprobado el 10-sep-2026, después de añadir
el caso de uso de Instagram: la suscripción de WhatsApp sigue intacta —
`whatsapp_business_account`, sus doce campos, `enabled: true`. **Añadir
Instagram no toca nada de WhatsApp**, que era el miedo razonable teniendo once
clientes en producción sobre esta misma app.

---

## 2 · Los textos de la solicitud

Escritos para el revisor: concretos, sin marketing, y diciendo qué hace la app
con cada permiso.

### Descripción general de la app

> Integra CRM es una plataforma de atención al cliente para empresas de
> Colombia —proveedores de internet y cooperativas de ahorro y crédito— que
> centraliza en una sola bandeja los mensajes que sus clientes les envían.
>
> Hoy operamos WhatsApp Business Platform con acceso avanzado (permisos
> whatsapp_business_messaging y whatsapp_business_management, aprobados en
> septiembre de 2026). Nuestros clientes nos piden atender también los mensajes
> directos de Instagram desde la misma bandeja, para que un mismo asesor pueda
> responder sin cambiar de aplicación.
>
> La aplicación es servidor a servidor. Nuestros usuarios conectan su cuenta
> profesional de Instagram mediante Business Login for Instagram y nosotros
> guardamos el token para recibir y responder sus mensajes.

### `instagram_business_basic`

> Lo usamos para identificar la cuenta profesional de Instagram que el usuario
> conecta: su ID de cuenta, su nombre de usuario y su foto de perfil.
>
> Esos datos se muestran en nuestra aplicación para que el usuario sepa qué
> cuenta tiene conectada cuando administra varias, y para etiquetar cada
> conversación con la cuenta que la recibió. Sin este permiso no podríamos
> distinguir las conversaciones de una cuenta de las de otra.

### `instagram_business_manage_messages`

> Es el permiso central de nuestro caso de uso: recibir los mensajes directos
> que los clientes finales envían a la cuenta profesional de nuestro usuario, y
> enviar las respuestas que el asesor escribe desde nuestra bandeja.
>
> Los mensajes entrantes llegan por el webhook del tópico instagram, campo
> messages, y se muestran en la conversación correspondiente. Cuando el asesor
> responde, enviamos la respuesta al endpoint de mensajes usando el token del
> usuario, siempre dentro de la ventana de 24 horas y siempre en respuesta a una
> conversación que el cliente final inició.
>
> No enviamos mensajes no solicitados ni iniciamos conversaciones.

### Si preguntan por el uso de datos

> Los mensajes se guardan asociados a la empresa que los recibió y sólo son
> visibles para los usuarios de esa empresa. Cada empresa está aislada de las
> demás. El borrado de conversaciones requiere aprobación y queda registrado.
> Atendemos las solicitudes de desautorización y de eliminación de datos en las
> URL registradas.

---

## 3 · El screencast

Lo que nos rechazaron la primera vez fue exactamente esto, y el motivo del
revisor fue literal:

> *"the screencast does not show a message being sent from your app UI and the
> same message appearing in the native client."*

Así que la grabación tiene que mostrar **cinco tomas, seguidas y sin cortes**:

1. **El login.** Un usuario en nuestra aplicación pulsa «Conectar Instagram», se
   abre la ventana de autorización de Instagram, **se ve qué cuenta se
   selecciona**, y se conceden los permisos.
2. **La cuenta conectada** aparece en nuestra pantalla, con su nombre de usuario
   visible.
3. **Un mensaje entrante.** Desde otro teléfono, alguien le escribe un DM a esa
   cuenta. **Se ve llegar a nuestra bandeja.**
4. **La respuesta, escrita desde nuestra interfaz.** No desde Instagram: desde
   el CRM, y se ve pulsar enviar.
5. **El mismo mensaje llegando a Instagram de verdad**, en el teléfono del
   cliente final.

Y lo que **no está en la documentación pública** y nos costó el primer rechazo:

> **Declarar en la solicitud que la app es servidor a servidor y usa token de
> usuario del sistema.** Si no se dice, el revisor busca un flujo de login de
> Meta que no existe, no lo encuentra, y rechaza.

Va en las descripciones, abriéndolas con esa frase.

---

## 4 · Antes de darle a enviar

- [x] Las cuatro URLs responden 200 desde fuera de nuestra red *(10-sep-2026)*
- [x] Caso de uso de Instagram añadido y Business Login configurado *(10-sep-2026)*
- [x] Webhook del tópico `instagram` verificado y guardado *(10-sep-2026)*; los
      campos llegan al conectar la primera cuenta
- [ ] Instagram App Secret (el de `Integra CRM-IG`) añadido a `META_APP_SECRETS`
- [ ] Nuestra cuenta profesional de Instagram conectada y funcionando en
      acceso estándar
- [ ] Screencast con las cinco tomas, sin cortes
- [ ] En las descripciones, declarado que es servidor a servidor con token de
      usuario del sistema
- [ ] Sólo dos permisos solicitados, ninguno de más
- [ ] Verificado por API que la solicitud quedó enviada — el panel a veces dice
      una cosa y la API otra:
      `devtools_app_review action=status` debe mostrar `is_pending: true`

---

## Cómo comprobar el resultado sin abrir el panel

```
devtools_app_review action=status      → submission_status, is_approved
devtools_app_review action=privileges  → is_live Y access_level por permiso
devtools_webhook_list action=list_subscriptions → que el tópico instagram esté
```

Lo que hay que mirar al aprobar no es sólo `is_live: true`, sino
**`access_level: advanced`**. Es la distinción que el panel no destaca y la que
decide si podemos servir cuentas de clientes o sólo las nuestras.
