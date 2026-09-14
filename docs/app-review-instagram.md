# App Review de Instagram: expediente listo para enviar

Todo lo que hay que rellenar, con los textos ya escritos y los valores exactos.
Falta una sola cosa que no se puede adelantar, y está explicada abajo.

App: **Integra CRM** · `865904982715022`

Estado al **14-sep-2026**: borrador `1058246920147493` con los dos permisos
dentro, descripciones e instrucciones escritas. Faltan el screencast, el Data
Use Checkup y las casillas de «Uso permitido». `can_submit: false` — y eso es
normal: el mensaje *«ya hay una en proceso»* se refiere al propio borrador, no a
una solicitud enviada.

### Instagram nunca se ha enviado a revisión

Conviene dejarlo escrito porque la API engaña. `devtools_app_review privileges`
devuelve `is_rejected: true` para `instagram_business_basic` y
`instagram_business_manage_messages`, y **no es un dictamen de rechazo**: es el
estado «nunca concedido». El historial real del panel sólo tiene dos entradas:

| Fecha | Resultado | Permisos |
|---|---|---|
| 5-sep-2026 | Aprobada | `whatsapp_business_messaging`, `whatsapp_business_management` |
| 11-may-2026 | No aprobada | `whatsapp_business_messaging` |

O sea que **el rechazo del screencast que se cita más abajo fue el de WhatsApp**,
no uno de Instagram. Vale igual como aviso, pero no es un antecedente de este
expediente.

### Y al enviar, Meta re-revisa lo de WhatsApp

El borrador arrastra `public_profile`, `whatsapp_business_messaging` y
`whatsapp_business_management` como **«Acceso existente para revisar»**. Son los
que están en producción con once clientes. No se puede quitar y es el
comportamiento normal de Meta, pero significa que esta solicitud no es un
trámite aislado de Instagram.

---

## El orden importa, y no es el que parece

Meta exige **ver la función andando** antes de dar acceso avanzado. No se puede
grabar lo que no existe, así que la solicitud no va primero: va cuarta.

1. ~~**Configurar el producto Instagram** en el panel~~ — **hecho el 10-sep-2026**
2. ~~**Suscribir el webhook**~~ — hecho, en cuanto el endpoint estuvo desplegado
3. ~~**Construir la integración mínima**~~ — conectar la cuenta, recibir un DM y
   contestarlo desde el CRM: **hecho el 11-sep-2026**
4. ~~**Probarla con nuestra propia cuenta profesional**~~ — `integracolombiasas`
   (`17841458371253418`) añadida, con «Suscripción al webhook» activada
5. **Grabar el screencast** ← *aquí estamos*, y bloqueado por el secreto de
   Instagram (ver «El secreto que falta»)
6. **Enviar la solicitud** de acceso avanzado

### Lo que hace falta del lado de la cuenta

- Una **cuenta profesional** (Empresa o Creador). Una personal no sirve, y
  convertirla la vuelve pública: las profesionales no pueden ser privadas.
- **Dos cuentas para el screencast**, no una: la profesional es la del negocio
  dentro del CRM, y hace falta otra —vale una personal— haciendo de cliente
  final que escribe el DM.
- Meta pide además **al menos una llamada correcta a la API** antes de dejar
  enviar la solicitud: *«to request Advanced Access to certain permissions, you
  need to make at least 1 successful API call»*. Conectar la cuenta ya la hace.
- Y puede pedir **credenciales para entrar a nuestro CRM**. Ahí sirve el usuario
  de la empresa DEMO.

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

### Sin el rol de evaluador, TODO falla con el mismo error

Este fue el problema de verdad y costó seis intentos, así que conviene leerlo
antes de tocar nada.

En acceso estándar, la cuenta de Instagram tiene que estar **registrada en la
app con el rol de «evaluador de Instagram»**, y la cuenta tiene que **aceptar la
invitación**. Mientras eso falte, Meta emite el token sin protestar y después
**ninguna** llamada a `graph.instagram.com` resuelve. Todas —`/access_token`,
`/refresh_access_token` y `/me`— responden lo mismo:

    Unsupported request - method type: get   (IGApiException, código 100)

Ese mensaje idéntico para causas distintas es lo que hace perder horas: parece
un problema de ruta o de método, y no lo es.

**Cómo se hace, y dónde está escondido:**

1. Panel → paso 2 «Generar tokens de acceso» → **Agregar cuenta** → rol
   **Evaluador de Instagram** → escribir el nombre de usuario
2. La cuenta acepta desde **instagram.com** (la app del móvil **no** lo muestra):
   Configuración → Aplicaciones y sitios web → pestaña **«Invitaciones para
   evaluadores»**
3. Y una vez aceptada, activar **«Suscripción al webhook»** en esa misma fila del
   paso 2: viene **desactivada**, y sin ella no llega ningún mensaje aunque la
   cuenta esté conectada. **Es por cuenta, no por app**, así que hay que hacerlo
   con cada cliente.

Con el rol puesto, el canje documentado (`ig_exchange_token`) **funciona a la
primera** y devuelve un token de 60 días. El código conserva de todos modos un
respaldo —canje → renovación → seguir con el que hay— que no estorba, pero la
causa nunca fue el endpoint.

Lo que sí quedó descartado por el camino, y ahorra repetirlo: la URL era la
documentada, la clave era la de Instagram y no la de Facebook, esa misma URL con
un token inválido responde con normalidad, la ruta versionada se comporta igual,
y el token era real (`IGAG…`, 208-218 caracteres).

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

La descripción enviada el 14-sep-2026 **abre con la declaración de dependencia**,
que Meta exige en el propio formulario y sin la cual rebota:

> Solicitamos instagram_business_basic como permiso dependiente de
> instagram_business_manage_messages. Lo necesitamos para identificar la cuenta
> profesional de Instagram cuyos mensajes directos atendemos desde nuestra
> bandeja.

Y sigue con:

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

Y lo que **no está en la documentación pública** y nos costó el rechazo de mayo:

> **Declarar en la solicitud que la app es servidor a servidor.** Si no se dice,
> el revisor busca un flujo de login de Meta que no existe, no lo encuentra, y
> rechaza.

Va en las descripciones, abriéndolas con esa frase.

### Cuidado: aquí NO hay token de usuario del sistema

La versión anterior de este documento decía que había que declarar «servidor a
servidor **y usa token de usuario del sistema**». Eso era cierto para WhatsApp y
**es falso para Instagram**. El código no hace eso
(`app/Services/InstagramLoginService.php`):

```
api.instagram.com/oauth/access_token       código → token corto
graph.instagram.com/access_token           ig_exchange_token → 60 días
graph.instagram.com/refresh_access_token   renovación
```

Es **Business Login for Instagram con token de usuario**. Declarar un system
user token que no existe es describir mal la app en una App Review, que es
justo lo que hace que la rechacen. Las descripciones enviadas dicen «servidor a
servidor» y explican que guardamos el token de larga duración de Business Login.

### Lo que Meta pide de verdad, y sólo se ve dentro del formulario

El modal de cada permiso trae un bloque «Instructions for Developers» que no
está en la documentación pública. Para `instagram_business_basic`:

- **Screencast**: enseñar cómo una cuenta profesional se conecta a la app, y
  enseñar después su información de perfil (nombre de usuario, foto) ya dentro
  de la app.
- **En la descripción**: dónde ve el revisor esa información de perfil, y las
  credenciales del CRM para que pueda conectar su propia cuenta.
- Y el que se olvida: *«If you are requesting instagram_business_basic
  permission as a dependent permission for either of
  instagram_business_manage_messages or instagram_business_manage_comments then
  specify that clearly in your submission»*. **Es nuestro caso**, y va en la
  primera frase de esa descripción.
- Prohibición explícita: *«Please don't provide any Instagram account
  credentials»*. Las del CRM sí; las de Instagram no.

---

## 4 · Antes de darle a enviar

- [x] Las cuatro URLs responden 200 desde fuera de nuestra red *(10-sep-2026)*
- [x] Caso de uso de Instagram añadido y Business Login configurado *(10-sep-2026)*
- [x] Webhook del tópico `instagram` verificado y guardado *(10-sep-2026)*; los
      campos llegan al conectar la primera cuenta
- [ ] **Instagram App Secret (el de `Integra CRM-IG`) añadido a
      `META_APP_SECRETS`** — comprobado el 14-sep-2026: **sigue sin estar**, ver
      «El secreto que falta»
- [x] El código: conectar la cuenta, recibir DM y responder desde el CRM
      *(11-sep-2026)*
- [x] Nuestra cuenta profesional conectada en acceso estándar: `integracolombiasas`
      (`17841458371253418`), con «Suscripción al webhook» activada *(14-sep-2026)*
- [x] Los dos permisos en el borrador *(14-sep-2026)* — `instagram_business_basic`
      faltaba y bloqueaba a `manage_messages` por `dependent_permission`
- [x] Las dos descripciones y las instrucciones para revisores escritas
      *(14-sep-2026)*: `use_case` en `true` para ambos permisos
- [x] Usuario del revisor y cuenta de Instagram en la **misma empresa**
      (`company_id=57`) *(14-sep-2026)*
- [ ] Screencast con las cinco tomas, sin cortes — **uno por permiso**
- [x] En las descripciones, declarado que es servidor a servidor *(sin la frase
      del token de sistema, que aquí sería falsa)*
- [x] Sólo dos permisos solicitados, ninguno de más
- [ ] Data Use Checkup («Tratamiento de datos») y las casillas de «Uso permitido»
- [ ] Verificado por API que la solicitud quedó enviada — el panel a veces dice
      una cosa y la API otra:
      `devtools_app_review action=status` debe mostrar `is_pending: true`

### La trampa del `company_id`, que no da ningún error

Las credenciales que se le dan a Meta tienen que ser de un usuario de la
**misma empresa** que tiene conectada la cuenta de Instagram. Si no, el revisor
entra, abre el Chat y **no ve ni una conversación de Instagram**: el aislamiento
manual por `company_id` las filtra fuera sin lanzar ningún error, y el revisor
concluye que la función no existe. Es el mismo desenlace que el rechazo de mayo.

Se comprueba así, en el VPS:

```bash
docker exec -i whatsapp-integra-app-1 php artisan tinker --execute="
\$u = App\Models\User::where('email','revisor@integracolombia.co')->first();
echo 'USUARIO: '.(\$u ? \$u->email.'  company_id='.\$u->company_id : 'NO EXISTE').PHP_EOL;
foreach (App\Models\Instance::where('channel','instagram')->get() as \$i) {
    echo 'INSTANCIA IG: '.\$i->external_account_id.'  company_id='.\$i->company_id.'  activa='.(\$i->active ? 'si':'no').PHP_EOL;
}"
```

Y **no dársela al usuario master**: `Gate::before` le concede todos los permisos,
así que el revisor de Meta vería las conversaciones reales de los once clientes.

---

## El secreto que falta, y por qué bloquea el screencast

Comprobado el 14-sep-2026: en producción `META_APP_SECRETS` tiene **dos**
entradas, `1862365350983129` y `865904982715022`. **Falta `28822685693981719`**,
la de `Integra CRM-IG`.

Mientras falte, `InstagramWebhookController.php:57-60` responde **403 a todos los
webhooks de Instagram** y no entra ni un DM. Grabar el screencast en ese estado
es imposible: la toma del mensaje llegando a la bandeja no ocurre.

Se mira sin exponer los secretos, sólo los `app_id`:

```bash
docker exec -i whatsapp-integra-app-1 sh -c 'echo "$META_APP_SECRETS" | tr "," "\n" | cut -d: -f1'
```

**Dónde se cambia — y no es el compose.** `docker-compose.yml:79` ya declara
`META_APP_SECRETS: ${META_APP_SECRETS:-}`; el valor vive en **`.env.docker:65`**,
en `/root/proyectos/whatsapp-integra`. Como el código no cambia, **no hace falta
`build`**: basta `docker compose up -d` para recrear el contenedor, y el
`entrypoint.sh` rehace el `config:cache` con las env vars vivas.

Es una **lista**: hay que añadir la tercera entrada conservando las dos que ya
están. Sustituirla en vez de ampliarla tumbaría los webhooks de WhatsApp de los
once clientes en producción.

Después, la prueba de fuego antes de gastar una grabación — mandar un DM a
`integracolombiasas` y mirar:

```bash
docker exec -i whatsapp-integra-app-1 tail -20 /var/www/html/storage/logs/instagram.log
```

Si sale `❌ Webhook de Instagram rechazado: firma inválida`, el secreto no cuadra.

---

## Cómo comprobar el resultado sin abrir el panel

```
devtools_app_review action=status      → submission_status, is_approved
devtools_app_review action=privileges  → is_live Y access_level por permiso
devtools_app_review action=requirements → los pasos que faltan, permiso a permiso
```

`devtools_webhook_list action=list_subscriptions` **no sirve para Instagram**: sólo
devuelve `whatsapp_business_account`. No es que esté roto — en Instagram Login la
suscripción vive dentro del producto, en la app `Integra CRM-IG`
(`28822685693981719`), que ni siquiera aparece en `devtools_app_list`. Para verla
hay que abrir el panel.

La URL del App Review de esta app tampoco es la clásica: `/app-review/permissions/`
redirige a `/use_cases/`. La que funciona es
**`/apps/865904982715022/app-review/submissions/`**.

Lo que hay que mirar al aprobar no es sólo `is_live: true`, sino
**`access_level: advanced`**. Es la distinción que el panel no destaca y la que
decide si podemos servir cuentas de clientes o sólo las nuestras.
