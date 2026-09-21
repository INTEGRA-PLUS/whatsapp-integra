# 18-sep-2026: texto predictivo, y por qué lo difícil no era sugerir sino no afirmar

**Qué se construyó.** Una extensión que propone hasta tres formas de contestar el mensaje que el
asesor tiene delante, en un panel flotante sobre el cuadro de redacción del chat. Se pulsa una y el
texto entra en el campo, listo para corregir. Dos piezas: el flujo n8n
`whatsapp-texto-predictivo` (Ollama, `gpt-oss:120b`) y el lado Laravel, que es síncrono y sin cola.

**Escribir tres frases es lo fácil: lo hace cualquier modelo.** Todo el diseño se fue en las dos
decisiones de abajo, y las dos vienen de cómo fallan estas herramientas en producción.

## 1. La IA redacta el tono; las cifras las pone el asesor

Es la regla del flujo de menús —«la IA entiende y enruta; el código afirma»— pero aquí pesa más que
en ningún otro sitio del proyecto, y el motivo es de comportamiento, no de tecnología:

> Lo que sale de aquí lo envía una persona **con prisa**. Una persona con prisa pulsa el chip y le
> da a enviar sin releer.

Un precio que se inventó el modelo se convierte, en ese clic, en un precio que la empresa dio por
escrito por WhatsApp. No es una alucinación en un chat de pruebas: es un compromiso comercial con
fecha y remitente.

Pedírselo al prompt no basta —se le pide, y de hecho el prompt lo repite tres veces—, así que la
comprobación está **en código y en dos sitios**:

- El nodo **`Verificar sugerencias`** del flujo saca toda cifra de cada sugerencia y comprueba que
  esté **literalmente en la conversación**.
- `App\Services\TextoPredictivoIaClient::leer()` lo vuelve a hacer al recibir.

La duplicación es deliberada. El JSON del flujo se importa a mano, se le puede desenganchar un nodo
sin querer, y apuntar `PREDICTIVO_WEBHOOK_URL` a otro sitio cuesta cinco segundos; lo que cuesta un
`preg_match` aquí es que un precio falso no llegue nunca al campo de texto.

Tres detalles del verificador que parecen manías y no lo son:

- **Se descarta la sugerencia entera, no el número.** A una frase a la que le quitas la cifra deja
  de querer decir lo que decía, y lo que queda es una promesa a medias que alguien va a enviar
  igual.
- **Se compara por dígitos, no literalmente.** El cliente escribe «120000» y el modelo contesta
  «$120.000». Reformatear no es inventar, y descartarlo dejaría la extensión sin poder confirmar un
  precio jamás.
- **Quedarse con dos sugerencias de tres es el resultado correcto.** Y quedarse con cero también:
  el asesor sabe escribir, lleva haciéndolo todo el día.

## 2. Sugerir no es responder, y la diferencia es un clic

La extensión **no envía**. Ni con confirmación, ni con retardo, ni «si el asesor no toca nada en
diez segundos». `aplicarSugerencia()` hace `setNewMessage()` y pone el cursor al final. Se acabó.

Ese clic de más es lo único que separa una ayuda de redacción de un bot contestando en nombre de la
empresa, y es lo que permite tenerla encendida en conversaciones donde a nadie se le ocurriría
dejar contestar solo a un modelo. Está escrito en el manifiesto de la extensión, en el detalle que
ve quien la instala y en el propio panel («las escribes tú, no se envían solas») a propósito: es la
primera pregunta que hace todo el mundo.

## 3. Por qué es síncrono y no un job, al revés que el semáforo

El semáforo va por cola dedicada porque nadie lo está esperando: pinta un punto de color y el
resultado sirve igual treinta segundos después. Aquí hay un asesor con el cursor en el campo y un
cliente esperando. Una sugerencia que llega por websocket cuando la frase ya está escrita no llega
tarde: llega a estorbar.

De ahí el timeout más corto de los tres flujos del CRM —**25 s**, contra 30 del resumen y 45 del
semáforo— y de ahí que el controlador responda en la misma petición.

El análogo correcto de esta extensión no es el semáforo: es el resumen.

## 4. Lo que evita que esto se coma el crédito

El patrón, no la tarifa, es lo que hace cara a una herramienta de IA (la del chat reenvía el prompt
entero en cada turno; el semáforo corre en *todas* las conversaciones). Esta se pide al abrir cada
chat, así que lleva tres frenos:

- **Caché por `(conversación, último mensaje, borrador)`**, 15 minutos. Un acierto es literalmente
  «la misma pregunta»: cerrar el chat y volver a abrirlo —que es lo que hace un asesor todo el
  día— no puede costar una segunda inferencia.
- **La tanda vacía también se guarda.** Si el modelo no ve nada que sugerir para este mensaje, no lo
  va a ver mejor treinta segundos después. Sin esto, un chat sin nada que sugerir pediría una
  inferencia cada vez que alguien lo abriera.
- **Un candado atómico de 10 s por conversación** (`Cache::add`, que es reloj y candado a la vez).
  Pulsar «otra vez» tres veces porque la primera tardó son tres inferencias pagadas para la misma
  pregunta, y el asesor sólo va a leer la última.

Y tres condiciones para que el automático no dispare: sólo si el último mensaje es del **cliente**
(sugerir sobre un mensaje nuestro es pagar por tres formas de hablar solos), sólo si el campo está
**vacío** (a quien ya está escribiendo se le estorba) y sólo con la **ventana de 24 h abierta** (con
la ventana vencida sólo sale una plantilla aprobada, así que las tres frases nacerían muertas).

Coste apuntado en `ContadorDeIa`: `predictivo`, 0,00048 USD por evento, entre el semáforo y el
resumen. Se apunta **aunque no salga ninguna sugerencia** —la inferencia se pidió y se pagó igual—;
contar sólo los aciertos haría que el flujo más roto pareciera el más barato.

## 5. Dónde se pinta, y por qué ahí

Panel `absolute bottom-full` dentro del contenedor del cuadro de redacción, junto al selector de
respuestas rápidas. **No una franja sobre el hilo**: eso ya se intentó con el resumen y acabó en
modal porque empujaba los mensajes que estabas leyendo justo cuando ibas a contestarlos. Aquí no se
podía repetir, porque estas tres aparecen solas sin que nadie pulse nada.

Va **detrás** de las respuestas rápidas: si el asesor escribió «/» ya sabe lo que busca, y taparle
su propia lista con sugerencias del modelo es quitarle de en medio lo que había pedido.

## Lo que no entra

- **No sustituye a las respuestas rápidas.** Aquéllas son de la empresa, fijas y gratis. Esto es
  para lo que cambia en cada conversación.
- **No hay predicción mientras se teclea.** Un modelo de 120B con 2–6 s de latencia no puede ir
  detrás del cursor, y fingirlo con un modelo pequeño daría sugerencias peores que el silencio.
- **No entra en el mini-chat del kanban** (`PanelConversacion.jsx`), que ya se quedó fuera de
  plantillas, notas y reacciones por lo mismo: es un panel de vistazo, no la bandeja.

## Configurar

1. Importar `whatsapp-texto-predictivo.json` en n8n y reenganchar **dos** credenciales (el export de
   n8n nunca las lleva): la Bearer Auth de Ollama y una Header Auth `X-Api-Key` para el webhook.
   **Aquí la del webhook no es opcional** como en el semáforo: aquél protegía la factura, éste
   protege además datos — el payload lleva la conversación entera del cliente.
2. `PREDICTIVO_WEBHOOK_URL` y `PREDICTIVO_API_KEY` en el `.env`. Recordar que **el contenedor no lee
   el `.env` del host**: ya están en el bloque `x-app-env` de `docker-compose.yml`, pero hay que
   reconstruir.
3. Extensiones → **Texto predictivo** → Instalar. Requiere complemento de IA (Esencial o Completa).

Comprobarlo, en este orden: `node test-modelo.mjs` (¿funciona el prompt?), `node probar-webhook.mjs`
(¿está bien montado el flujo?) y `php artisan wa:predictivo-probar` (¿llega la app hasta él?). Si el
segundo pasa y el tercero falla, el problema está en el `.env`.

Fuentes del flujo: `~/Desktop/proyects/n8n/local-files/flujo-texto-predictivo/`. **El JSON se
genera con `build.mjs`**: editarlo a mano se pierde en la siguiente regeneración. Esa carpeta está
en el `.gitignore` del repo de n8n, igual que la del semáforo.
