# Sumar Messenger e Instagram a la bandeja

Qué hace falta para que el CRM reciba también las conversaciones de Facebook e
Instagram de nuestros clientes. Verificado el 10-sep-2026 contra el MCP de Meta
y su documentación, no de memoria.

## Lo que ya está resuelto, y es la mitad del camino

Tres cosas que normalmente son el cuello de botella **ya las tenemos**:

- **Verificación del negocio: aprobada.** El MCP lo confirma:
  `business_verification_passes: true`. Es el trámite más lento de Meta y no hay
  que repetirlo por canal.
- **Política de privacidad y URLs legales: en pie y verificadas.**
- **`can_submit: true`** — la app puede enviar solicitudes nuevas de App Review
  ahora mismo, sin arreglar nada antes.

Y algo que no esperaba: **la app ya puede suscribirse a los tópicos que hacen
falta**. Lo verifiqué con `devtools_webhook_list`:

| Tópico | Campos que nos sirven |
|---|---|
| `page` | `messages`, `messaging_postbacks`, `message_echoes`, `message_reads`, `message_reactions`, `messaging_handovers` |
| `instagram` | `messages`, `message_reactions`, `messaging_postbacks`, `messaging_seen`, `comments`, `mentions` |

**Cada tópico lleva su propio `callback_url`.** Eso desactiva el problema que
tenemos con WhatsApp —una app, un callback por tópico— porque `page`,
`instagram` y `whatsapp_business_account` son tópicos distintos: caben los tres
en la misma app, cada uno apuntando donde queramos.

## Los permisos que faltan

### Messenger (Facebook)

Se piden vía **Facebook Login for Business**:

| Permiso | Estado hoy en nuestra app |
|---|---|
| `pages_messaging` | nunca solicitado |
| `pages_manage_metadata` | nunca solicitado |
| `pages_show_list` | **RECHAZADO** |
| `pages_read_engagement` | **RECHAZADO** |
| `business_management` | **RECHAZADO** |

Ojo con el último: **`business_management` es dependencia de `pages_messaging`,
`pages_show_list` e `instagram_manage_messages`**. La documentación dice
literalmente que hay que señalarlo en la solicitud de App Review. Y los tres que
figuran rechazados son restos de solicitudes viejas: hay que volver a pedirlos,
esta vez con caso de uso escrito.

Además, quien conceda el token de página tiene que poder ejecutar las tareas
**MESSAGING** y **MODERATE** en esa página. Si el cliente nos pone a alguien sin
esos permisos, el flujo falla sin decir por qué.

### Instagram — hay dos caminos y no dan igual

**A) Instagram API con Instagram Login** ← el que recomiendo

- **No exige que el cliente tenga una página de Facebook vinculada.** Esto es lo
  que lo decide: la mitad de los negocios pequeños tienen Instagram y no tienen
  página, o la tienen abandonada y sin acceso.
- Permisos: `instagram_business_basic` + `instagram_business_manage_messages`
- Login propio, en `instagram.com/oauth/authorize`, con **App ID y App Secret de
  Instagram distintos de los de Facebook**
- Host `graph.instagram.com`
- Token de 60 días, **renovable** sin volver a molestar al cliente
- No necesita `business_management`

**B) Instagram vía Facebook Login**

- Exige página de Facebook vinculada
- Permisos `instagram_basic` + `instagram_manage_messages` + los de páginas
- Arrastra `business_management`

El camino A es más simple, tiene menos dependencias y no obliga al cliente a
tener nada en Facebook. El B sólo compensa si ya estamos pidiendo los permisos
de páginas para Messenger de todos modos.

### Y en los dos casos: acceso avanzado

La documentación es explícita: **acceso avanzado si la app sirve cuentas que no
son nuestras**, que es exactamente nuestro caso. Acceso estándar sólo vale para
cuentas propias añadidas en el panel.

Es la misma distinción que ya nos mordió con WhatsApp, y significa **App Review
con screencast otra vez** — con las mismas tres tomas que aprendimos: selección
del activo con la cuenta visible, envío real desde nuestra interfaz, y el mensaje
llegando en la app nativa.

## Lo que cambia a favor: Messenger e Instagram son gratis

**No hay costo por mensaje.** Ni plantillas, ni categorías, ni tarifario por
país. Eso es un argumento comercial fuerte y una diferencia grande frente a
WhatsApp:

- El cliente no necesita asociar medio de pago para estos dos canales
- No hay que explicarle utility contra marketing
- No hay tramo de mensajería que calentar

A cambio, la regla es más estricta en un punto: **la conversación siempre la
inicia la persona.** No existe el equivalente a una plantilla para escribir
primero. Y la ventana de respuesta también es de **24 horas** — el mismo modelo
mental que ya tenemos con `isWindowOpen()`.

## Lo que cuesta de verdad: el código

Aquí está el grueso del trabajo, y conviene no minimizarlo.

Todo nuestro dominio se llama `WhatsApp*`: `WhatsAppConversation`,
`WhatsAppMessage`, `WhatsAppCampaign`, `MetaWhatsAppService`… y las
conversaciones se identifican por `wa_id`, `phone_number` y `bsuid`.

Messenger e Instagram no tienen teléfono: usan **identificadores con ámbito**
—PSID en Facebook, IGSID en Instagram— que sólo valen para esa página o esa
cuenta. La misma persona escribiendo por los dos canales son dos identificadores
distintos y no hay forma directa de unirlos.

Hay un precedente que ayuda: cuando Meta permitió ocultar el número, añadimos
`bsuid` a las conversaciones y `normalizeRecipient()` / `recipientId()` para
tratar «esto no es un teléfono» sin romper nada. **Ese es el camino a
generalizar**, no empezar de cero.

Lo que habría que tocar, en orden de esfuerzo:

1. **Un concepto de canal** en instancias y conversaciones. Hoy `instances.type`
   ya existe con valor `meta`; hace falta que signifique algo.
2. **Un despachador de webhooks por tópico.** El controlador actual asume el
   formato de `whatsapp_business_account`. Los payloads de `page` e `instagram`
   son parecidos pero no iguales (`messaging[]` en vez de `messages[]`).
3. **Envío por canal.** `MetaWhatsAppService` habla con `graph.facebook.com` y
   endpoints de WhatsApp. Instagram con Instagram Login habla con
   `graph.instagram.com`.
4. **Tokens con caducidad.** Los de Instagram duran 60 días y hay que
   refrescarlos. Los de WhatsApp que usamos hoy no caducan. Hace falta una tarea
   programada que los renueve antes de que mueran, o los clientes se caen solos
   cada dos meses.
5. **La firma de los webhooks.** Instagram firma con **su propio app secret**.
   Nuestro `META_APP_SECRETS` ya es una lista precisamente para esto: se añade
   una entrada más y el validador lo prueba sin cambios.

Lo que **no** hay que tocar: la bandeja, el kanban, las etiquetas, los informes,
el reparto por carga, las macros. Todo eso ya trabaja sobre conversaciones y
mensajes, así que hereda los canales nuevos gratis. **Ese es el argumento para
hacerlo**: el 80 % del producto ya está construido.

## Orden que propongo

**Instagram primero, y sólo por Instagram Login.** Razones: no depende de que el
cliente tenga página de Facebook, no arrastra `business_management`, pide dos
permisos en vez de cinco, y es el canal que más piden los negocios pequeños.

Messenger después, reutilizando el despachador y el modelo de canal que ya
estarán hechos.

**Antes de escribir código**, tres pasos en el panel de Meta que no dependen de
nosotros y marcan el calendario:

1. Añadir el producto Instagram a la app y configurar Business Login
2. Pedir `instagram_business_basic` e `instagram_business_manage_messages` con
   **acceso avanzado**
3. Suscribir el tópico `instagram` con su propio callback

El App Review anterior tardó 9,7 días. Conviene enviarlo mientras se construye,
no después.

## Lo que hay que decidir

- **¿Se cobra aparte?** Estos canales no tienen costo de Meta, así que el margen
  es limpio. Puede ser un nivel superior de la tarifa o ir incluido como
  diferenciador.
- **¿Una conversación por canal, o una persona con varios canales?** Lo segundo
  es lo que quiere un agente —ver a alguien completo— pero unir un PSID con un
  IGSID con un teléfono no lo resuelve Meta: hay que hacerlo por datos propios
  (correo, cédula, nombre) y aceptar que a veces falla.
- **¿Qué pasa con las campañas?** No aplican: en estos canales no se puede
  escribir primero. Hay que decirlo en el producto para que nadie lo intente.

## Referencias

- Instagram con Instagram Login: <https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/>
- Business Login para Instagram: <https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/business-login>
- Enviar mensajes por Instagram: <https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-instagram-login/messaging-api>
- Messenger Platform, visión general: <https://developers.facebook.com/documentation/business-messaging/messenger-platform/overview>
