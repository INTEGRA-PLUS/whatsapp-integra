# App Review de Instagram: expediente listo para enviar

Todo lo que hay que rellenar, con los textos ya escritos y los valores exactos.
Falta una sola cosa que no se puede adelantar, y está explicada abajo.

App: **Integra CRM** · `865904982715022`
Estado hoy: última solicitud **aprobada**, ninguna pendiente, `can_submit: true`.

---

## El orden importa, y no es el que parece

Meta exige **ver la función andando** antes de dar acceso avanzado. No se puede
grabar lo que no existe, así que la solicitud no va primero: va cuarta.

1. **Configurar el producto Instagram** en el panel (abajo están los valores)
2. **Construir la integración mínima** — recibir un DM y contestarlo desde el CRM
3. **Probarla con nuestra propia cuenta profesional de Instagram**, que el
   acceso estándar ya permite sin ninguna aprobación
4. **Grabar el screencast** con eso funcionando
5. **Enviar la solicitud** de acceso avanzado

El paso 3 es la clave y mucha gente no lo sabe: **acceso estándar sirve para
cuentas propias añadidas en el panel**. O sea que se puede construir, probar y
grabar entero sin esperar a Meta. La aprobación sólo hace falta para servir
cuentas de clientes.

Como la revisión anterior tardó **9,7 días**, conviene enviarla en cuanto el
screencast esté grabado y seguir construyendo mientras tanto.

---

## 1 · Configuración en el panel

**App Dashboard → Productos → Instagram → API con Instagram Login**

### URLs que hay que registrar

| Campo | Valor propuesto |
|---|---|
| OAuth redirect URI | `https://wpp.integracolombia.online/instagram/callback` |
| Deauthorize callback URL | `https://wpp.integracolombia.online/instagram/desautorizar` |
| Data deletion request URL | `https://wpp.integracolombia.online/instagram/eliminar-datos` |
| Webhook callback URL | `https://wpp.integracolombia.online/webhooks/instagram` |

Las tres primeras las pide Meta al configurar Business Login. **Las tres tienen
que responder 200 antes de enviar la solicitud**: el revisor las visita.

Las dos últimas —desautorizar y eliminar datos— son obligación legal, no
trámite: Meta avisa por ahí cuando un usuario revoca el acceso o pide borrado, y
hay que atenderlas de verdad.

### Permisos a solicitar

- `instagram_business_basic`
- `instagram_business_manage_messages`

**Sólo esos dos.** Pedir `instagram_business_manage_comments` o
`instagram_business_content_publish` sin usarlos alarga la revisión y da motivos
para rechazar. Si más adelante hacen falta, se piden aparte.

### Webhook

Tópico `instagram`, con estos campos:

`messages` · `messaging_postbacks` · `messaging_seen` · `message_reactions` ·
`messaging_referrals` · `messaging_optins`

Va en su propio `callback_url`, distinto del de WhatsApp: son tópicos separados
y caben los dos en la misma app.

### Dos datos que hay que copiar del panel

En **Instagram → Configuración de la API con Instagram Login → Business login
settings** aparecen el **Instagram App ID** y el **Instagram App Secret**, que
**no son los de Facebook**. El secreto va a la lista de
`META_APP_SECRETS`; el validador de firmas ya prueba todas las entradas.

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

- [ ] Las cuatro URLs responden 200 desde fuera de nuestra red
- [ ] El producto Instagram añadido y Business Login configurado
- [ ] Webhook del tópico `instagram` suscrito y verificado
- [ ] Instagram App Secret añadido a `META_APP_SECRETS`
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
