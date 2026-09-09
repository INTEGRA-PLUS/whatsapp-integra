# Rótulos del vídeo de App Review

Textos en inglés para superponer al screencast de la solicitud de acceso avanzado a
`business_management` (ver `app-review-business-management.md`). El revisor de Meta no tiene por qué
entender español, y la app está toda en español: sin estos rótulos el vídeo es media hora de pantallas
que no sabe leer.

**Cómo usarlos.** Cada uno va cuando empieza la escena que describe y se queda tres o cuatro segundos.
En la parte inferior de la pantalla, con fondo oscuro y letra grande —se revisa en ventanas pequeñas—,
y sin tapar el diálogo de Meta, que es lo que el revisor quiere ver.

| # | Cuándo aparece | Texto |
|---|---|---|
| 1 | Rótulo inicial, antes de abrir el navegador | `Integra CRM — App ID 865904982715022`<br>`Permission requested: business_management (Advanced Access)` |
| 2 | Pantalla de acceso del CRM | `Our platform. Business customers sign in here to manage their WhatsApp conversations.` |
| 3 | Escribiendo el usuario y la contraseña | `Signing in with the test account provided in this submission.` |
| 4 | Menú → Instancias de WhatsApp (lista vacía) | `No WhatsApp number connected to this account yet.` |
| 5 | Clic en "Conectar mi WhatsApp Business actual" | `Starting Embedded Signup to connect the customer's own WhatsApp Business account.` |
| 6 | Se abre el diálogo de Facebook | `Facebook Login for Business dialog. The customer signs in with their own account.` |
| 7 | Pantalla de permisos del diálogo | `The customer reviews and grants the permissions our app requests.` |
| 8 | **Selección del portafolio comercial** | `Business portfolio selection. This step requires business_management.` |
| 9 | Todavía en esa pantalla, unos segundos más | `The WhatsApp Business Account is created under the customer's own portfolio (WABA Sharing model). We never take ownership of it.` |
| 10 | Número de teléfono y verificación | `The customer enters and verifies their WhatsApp Business phone number.` |
| 11 | Pantalla de éxito del diálogo | `Onboarding complete. The dialog returns the WhatsApp Business Account ID and the phone number ID.` |
| 12 | De vuelta en el CRM, instancia creada | `Back in our app: we subscribed our app to the account and listed its phone numbers, using the customer's token.` |
| 13 | Phone ID y WABA ID en pantalla | `The connected number, with its Phone ID and WABA ID.` |
| 14 | Chat con un mensaje entrando o saliendo | `The customer can now message their own customers from our platform.` |
| 15 | Rótulo final | `With Standard Access this flow only works for people who hold a role in our app. Advanced Access is what lets real business customers connect their own account.` |

El 8 y el 9 son los importantes: es el único punto del vídeo donde se ve el permiso haciendo algo.
Detén ahí el ratón unos segundos aunque la pantalla ya esté lista para continuar.

## Si algo sale distinto al grabar

- **Si el diálogo falla** con "Función no disponible": estás grabando con una cuenta sin rol en la app.
  Añádela como probadora (*Roles de la app → Probadores*) o graba con una que ya lo tenga, y deja el
  rótulo 15 igualmente: es exactamente lo que estás pidiendo que se arregle.
- **Si aparecen datos de clientes reales** en cualquier momento, corta y repite. La cuenta de prueba
  (`revisor@integracolombia.co`, empresa "Meta App Review") está vacía a propósito.
- **Si el número que conectas ya existe** en otra empresa del sistema, elige otro: el registro
  funcionará, pero los webhooks entrantes irían a la primera instancia activa con ese
  `phone_number_id`, y el mensaje del rótulo 14 podría no aparecer en el chat que estás grabando.
