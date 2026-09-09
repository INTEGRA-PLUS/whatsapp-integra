# Solicitud de acceso avanzado para `business_management`

**Por qué existe este documento.** El 8-sep-2026, con un cliente delante, el registro insertado
falló para toda cuenta que no tuviera rol en la app: *"Función no disponible — el inicio de sesión con
Facebook no está disponible…"*. La causa está en **Casos de uso → Conectar en WhatsApp → Permisos y
funciones**: `business_management` acumula 68 llamadas pero está en **acceso estándar** ("Listo para la
prueba"), y el propio panel avisa de que *"los permisos en el acceso estándar solo se solicitarán a las
personas con roles en esta app"*. De ahí que funcionara con la cuenta de Juan José Tuirán y con ninguna
otra. El permiso figura además como `REJECTED` en una solicitud anterior, así que hay que volver a
enviarlo.

Todo lo demás quedó comprobado y descartado ese mismo día: app en `live_mode` y publicada, cumplimiento
sin acciones ni infracciones, sin restricciones de país, App Review aprobado, registro de Tech Provider
completo (2 de 2), y la configuración `1686339276244548` pidiendo solo `whatsapp_business_management` y
`whatsapp_business_messaging`, ambos al máximo.

---

## Qué dicen los contadores (comprobado el 8-sep-2026)

Meta **no publica el desglose por endpoint** de cada permiso: el panel de casos de uso solo da un
contador, y el menú *Herramientas* únicamente ofrece el explorador de la API, el depurador de tokens y
el de contenido compartido. El contador, según su propio tooltip, son **llamadas de los últimos 30
días**:

| Permiso | Llamadas (30 días) | Acceso |
|---|---|---|
| `business_management` | 68 | estándar |
| `public_profile` | 58 | estándar |
| `whatsapp_business_manage_events` | 32 | estándar |
| `whatsapp_business_management` | 1 | avanzado |
| `whatsapp_business_messaging` | 1 | avanzado |

La asimetría es la pista, y conviene entenderla antes de escribir nada: nuestro backend llama a
endpoints de WhatsApp (`/{waba-id}`, `/{waba-id}/phone_numbers`, `/{waba-id}/subscribed_apps`…), que
consumen los dos permisos de abajo de la tabla, y esos marcan **1 llamada**. Las 68 de
`business_management` y las 58 de `public_profile` no salen, por tanto, de nuestro servidor: las
consume **el propio diálogo de registro insertado** cada vez que alguien lo abre y recorre sus
pantallas. Encajan con las decenas de intentos de conexión de estas semanas.

Es decir: el permiso se gasta exactamente donde el flujo se rompe para las cuentas sin rol. Eso es lo
que hay que contarle a Meta.

## Antes de enviar

1. **Crear un usuario de prueba** en el CRM y dejarlo activo (Meta lo usará y suele probar días después).
2. **Grabar el screencast** con el guion del final. Sin vídeo, la solicitud se rechaza por defecto.
3. **Decidir sobre `whatsapp_business_manage_events`** (32 llamadas, también en acceso estándar): si no
   se van a medir conversiones de anuncios de clic a WhatsApp, quitarlo del caso de uso en lugar de
   pedirlo. Cuantos menos permisos se piden, más simple es la revisión.

---

## Texto para el formulario (en inglés, listo para pegar)

### Tell us how your app uses this permission or feature

Integra CRM is a multi-tenant WhatsApp CRM used by internet service providers and other small businesses
in Colombia. Each of our business customers connects their own WhatsApp Business Account and uses our web
app to handle customer conversations, automated menus, and notification campaigns.

We are an approved Tech Provider and we onboard every customer through Embedded Signup, using the WABA
Sharing model: the WhatsApp Business Account is created under the customer's own business portfolio and
the customer grants our app access to it during the flow. We never take ownership of our customers'
assets.

`business_management` is required for that onboarding to work. During Embedded Signup the dialog
associates the customer's business portfolio with the WhatsApp Business Account being created or
connected, and the business token we receive at the end of the flow is what lets us operate on the assets
the customer explicitly shared with us.

This is where the permission is actually consumed. Our app dashboard shows 68 calls attributed to
`business_management` and 58 to `public_profile` over the last 30 days, while
`whatsapp_business_management` and `whatsapp_business_messaging` show 1 each: the volume comes from
business customers opening and walking through the Embedded Signup dialog, not from our server. Our
server-side calls happen after onboarding and are limited to the WhatsApp assets the customer shared:

- `GET /{waba-id}` — read the WhatsApp Business Account we were granted access to.
- `GET /{waba-id}/phone_numbers` — list the business phone numbers so the customer can pick the one to
  use in our app.
- `POST /{waba-id}/subscribed_apps` — subscribe our app to the account so we receive message webhooks.
- `GET /{waba-id}/message_templates` — list the customer's approved templates for their campaigns.
- `POST /{phone-number-id}/smb_app_data` — synchronize contacts and message history for customers who
  onboard with Coexistence and choose to share it.

We do not use this permission to read or manage ads, Pages, catalogs, or any business asset unrelated to
the customer's WhatsApp account.

### Why do you need Advanced Access?

With Standard Access, Meta only grants the permission to people who hold a role in our app. Our users are
third-party businesses that sign up on our website; they are not developers, administrators, or testers
of our app, and adding every customer as an app tester is not a workable onboarding process.

Today the consequence is concrete: when a business customer without an app role opens our Embedded Signup
dialog, it fails with "Feature unavailable — Facebook Login is currently unavailable for this app". Only
accounts that hold a role in the app can finish onboarding. Advanced Access is what allows real customers
to connect their own WhatsApp Business Account to our platform.

### Step-by-step instructions for the reviewer

1. Open `https://wpp.integracolombia.online` and log in with the test credentials provided in the App
   Review submission.
2. In the left menu, open **Instancias de WhatsApp** (WhatsApp instances).
3. Click **Conectar un número nuevo** (Connect a new number) to start Embedded Signup, or **Conectar mi
   WhatsApp Business actual** (Connect my existing WhatsApp Business) to start the Coexistence variant.
4. A Facebook dialog opens. Sign in with a Facebook account that administers a business, select or create
   a business portfolio, and select or create a WhatsApp Business Account and phone number.
5. Complete the flow. When it finishes, the dialog returns the WhatsApp Business Account ID and the phone
   number ID to our app.
6. Our backend then subscribes our app to the account and lists its phone numbers, and the newly
   connected number appears in the instance list with its Phone ID and WABA ID.
7. Open **Chat** to see conversations arriving on that number in real time.

Note for the reviewer: the account used in step 4 must not hold any role in our Meta app. That is
precisely the scenario that fails today with Standard Access.

---

## Guion del screencast

Meta quiere ver el permiso en uso, de principio a fin y sin cortes. Un vídeo de dos o tres minutos:

1. Pantalla de acceso del CRM y entrada con el usuario de prueba.
2. Menú lateral → *Instancias de WhatsApp*. Que se vea la lista vacía o con las instancias existentes.
3. Clic en *Conectar un número nuevo* → se abre el diálogo de Meta. **Mostrar la selección del portafolio
   comercial**: es el momento donde el permiso interviene.
4. Completar el registro hasta la pantalla de éxito.
5. Volver al CRM y mostrar la instancia recién creada con su Phone ID y su WABA ID.
6. Abrir el chat y mostrar un mensaje entrante o saliente por ese número.

Grabar con una cuenta **sin rol en la app** si para entonces ya hay acceso avanzado temporal; si no, se
graba con la cuenta que funciona y se explica en el texto que ese es justamente el problema a resolver.
