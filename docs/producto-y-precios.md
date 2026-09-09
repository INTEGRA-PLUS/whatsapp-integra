# Qué vendemos, qué cuesta y contra quién competimos

Base para cotizar. Recoge lo que el producto hace de verdad —comprobado contra
el código, no contra el folleto—, lo que cobra Meta, cómo lo cobra la
competencia, y dónde está nuestro margen.

## La regla que lo condiciona todo: somos Tech Provider, no BSP

Meta distingue dos figuras, y la diferencia decide el modelo de negocio entero:

| | **Solution Partner (BSP)** | **Tech Provider — nosotros** |
|---|---|---|
| Línea de crédito con Meta | Sí | **No** |
| Quién le paga a Meta | El BSP, y revende al cliente | **El cliente, directo** |
| Margen habitual sobre el mensaje | 5–20 % | **0 %** |
| Quién pone la tarjeta | El BSP | **El cliente, en su propia WABA** |

Nuestra aprobación de Tech Provider (5-sep-2026, acceso avanzado) permite operar
las WABAs de otros negocios, pero **no** extender crédito. Eso tiene dos caras:

**A favor, y es un argumento de venta fuerte:** el cliente paga a Meta el precio
de Meta. No hay intermediario cobrando un 5–20 % sobre cada mensaje. Con doce
mil socios y varios envíos al mes, esa diferencia se nota y es verificable: el
cliente ve su propia factura de Meta.

**En contra, y hay que decirlo en la primera reunión:** el cliente **tiene que
asociar un medio de pago a su cuenta de WhatsApp Business**. Si no lo hace, o si
la tarjeta falla, los mensajes se detienen y nosotros no podemos cubrirlo. En
una cooperativa eso pasa por tesorería, no por el área de sistemas, y conviene
arrancar ese trámite el primer día porque no depende de nosotros.

## Lo que cuesta Meta en Colombia

Desde el 1-jul-2025 Meta cobra **por mensaje**, no por conversación. Tarifas por
mensaje entregado (tarifario vigente en 2026; Meta lo actualiza el primer día de
cada trimestre, así que hay que verificarlo antes de firmar):

| Categoría | Colombia | Para qué es |
|---|---|---|
| **Servicio** | **Gratis** | Todo lo que respondemos dentro de las 24 h desde que el socio escribió |
| **Utility** | **~0,0009 USD** | Avisos de factura, recordatorios de pago, estados de cuenta, confirmaciones |
| **Authentication** | ~0,0009 USD | Códigos de verificación |
| **Marketing** | **~0,0138 USD** | Promociones, campañas comerciales |

**Colombia es de los mercados más baratos del mundo.** Alemania paga 0,0550 USD
por el mismo mensaje utility; nosotros 0,0009.

Las consecuencias son grandes y conviene entenderlas antes de poner precio:

- **12.000 avisos de utility al mes ≈ 11 USD.** El costo de Meta es
  prácticamente ruido frente a cualquier tarifa de plataforma razonable.
- **Los mismos 12.000 como marketing ≈ 165 USD**, quince veces más. Clasificar
  bien las plantillas no es un detalle técnico: es la diferencia entre un costo
  irrelevante y uno que se nota.
- Para una cooperativa, casi todo lo que va a mandar —cuotas, moras, estados de
  cuenta, avisos de asamblea— **es legítimamente utility**. Ahí hay una asesoría
  que vale dinero y que la competencia no siempre da.
- **Lo que el socio inicia no cuesta nada.** Un chatbot que atiende consultas
  entrantes tiene costo de Meta cero. Todo el uso de "CRM + chatbot" que la
  cooperativa quiere automatizar cae en servicio, es decir, gratis.

Nota sobre la ventana de 24 h: las plantillas utility son gratis **dentro** de
una ventana de servicio abierta y se cobran fuera. Como una campaña va por
definición a quien no acaba de escribir, hay que presupuestarlas como pagadas.

## Qué hace el producto

Comprobado contra el código. Agrupado como se vende, no como está el repo.

### Conversación y equipo
- Bandeja compartida multi-agente con asignación automática **por carga real**
  (conversaciones abiertas), no por turno rotativo.
- Vista Kanban de conversaciones con columnas configurables.
- Notas internas, menciones a compañeros, respuestas rápidas y macros.
- Etiquetas, ficha de contacto, historial completo.
- Presencia en vivo: quién más está mirando el chat y quién está escribiendo.
- Exportar una conversación a PDF (vía impresión del navegador, para que los
  emojis salgan bien).
- Borrado de conversaciones **con aprobación**: no hay borrado directo, queda
  como solicitud. Para una entidad vigilada esto es un argumento, no una
  limitación.
- Horarios de atención con respuesta fuera de horario.

### Automatización sin IA
- Menús interactivos por niveles, con submenús y sesiones por conversación.
- Respuestas automáticas y seguimientos.
- El menú **decide en caliente** dentro del webhook y se calla si un agente ya
  tomó el chat: el cliente nunca recibe dos respuestas al mismo mensaje.

### Automatización con IA
Dos flujos distintos, contra un gateway propio (n8n):
- **IA de menús**: entiende lo que el socio pide en lenguaje natural y lo lleva
  a la acción correcta, con lista blanca de eventos que la IA puede emitir.
- **IA de chat**: conversación abierta con contexto de la conversación.
- Ambas **deciden y devuelven texto; no envían**. Las comprobaciones —agente
  asignado, hilo cerrado, ventana de 24 h— se reevalúan *después* de la espera
  del modelo, que es cuando importan.
- El apartado de IA está bloqueado tras un secreto de activación: no se enciende
  por accidente.

### Acciones de negocio contra el ERP
Integración con Integra 2.0. El bot no solo informa: resuelve.
- Consultar factura
- Pagar en línea
- Reportar una falla (crea el radicado)
- Consultar estado del servicio (activo, suspendido, mora, retirado…)

Sondea qué puede hacer de verdad en cada empresa antes de ofrecerlo: *conectado*
no es lo mismo que *funciona*, y una acción sin permisos se deriva a un asesor
en vez de fallar.

### Campañas
- Envío masivo con plantilla aprobada, personalizada por destinatario.
- Segmentos guardados, importación por pegado, mezcla de fuentes sin repetidos.
- Programación puntual y recurrente por días y hora.
- Pausar, reanudar, cancelar y reintentar solo lo fallido.
- **Ritmo por número, no por campaña**: varias campañas sobre la misma línea se
  turnan en vez de sumarse contra Meta.
- **Aviso previo si la campaña no cabe** en el tramo de mensajería del número.
- Baja de campañas (opt-out) detectada en el chat y resuelta por una persona.
- Exportación del resultado a CSV.

### Llamadas de WhatsApp
Permisos de llamada, historial y registro por conversación.

### Coexistencia
El cliente sigue usando WhatsApp en su celular **y** en la plataforma a la vez.
Importa hasta **seis meses** de historial y la agenda del teléfono. Es una de las
cosas que más diferencian: la mayoría de plataformas obligan a elegir.

### Plataforma
- Multi-empresa con roles y permisos por empresa.
- Panel maestro con suplantación para soporte.
- Webhooks salientes por empresa, para que el ERP del cliente reaccione a lo que
  pasa en el chat.
- Informes por agente: volumen, tiempos de respuesta, conversaciones sin
  responder.
- Vigilancia de salud de cada línea, con aviso cuando cambia el estado.
- API v1 para que el ERP envíe y consulte.
- Guardarraíl de la ventana de 24 h y respaldo automático con plantilla cuando
  está cerrada, para que un aviso no se pierda en silencio.

### Lo que todavía NO hace
Honestidad por delante, porque sale en la primera demo:
- **Embedded Signup no está construido.** Está configurado en Meta pero no hay
  código: hoy las empresas se conectan pegando tokens a mano. Y la v2 se apaga
  el 15-oct-2026, así que lo que se construya va sobre v4.
- No hay control de ritmo fuera de campañas: chat, bot, IA y API salen sin
  límite.
- Los informes no tienen exportación programada.
- No hay app móvil propia.

## La competencia

Hay dos ligas distintas, y conviene no confundirlas al cotizar.

### Liga A — los del nicho ISP, que son la competencia de verdad

Venden lo mismo que nosotros: CRM de WhatsApp **integrado al software de
facturación del ISP**. Cobran más, facturan por trimestre adelantado y todos
excluyen el consumo de Meta.

**W-Chat** (`wchatisp.com`) — integrado a Mikrowisp y SmartOLT. Es el que mejor
modelo de precios tiene, porque **cobra por número de suscriptores**, que es
justo como piensa un ISP o una cooperativa:

| Suscriptores | CRM Multiagente | CRM + Bot | CRM + Bot + IA |
|---|---|---|---|
| hasta 500 | 40 USD/mes | 49 | 70 |
| 501–2.000 | 80 USD/mes | 99 | 129 |
| 2.001–5.000 | 140 USD/mes | 170 | **210** |
| más de 5.000 | a cotizar | a cotizar | a cotizar |

Se cobra por trimestre adelantado (tarifa mensual × 3) y **excluye Meta e IA**.
Su integración hace sincronización bidireccional con Mikrowisp: saldo, facturas
en PDF, registro de pago y **reconexión automática** validando el comprobante
con IA.

**CRM Inbox** (`crminbox.io`) — integrado a Mikrowisp y WispHub. Cobra por
**contactos activos**, también por trimestre:

| Plan | Trimestre | Al mes | Contactos activos | Usuarios |
|---|---|---|---|---|
| Standard | 285 USD | 95 | 2.000 | 5 |
| Premium | 465 USD | 155 | 5.000 | 11 |
| Enterprise | 720 USD | 240 | 7.000 | ilimitados |

Contactos extra: 10 USD por cada 1.000. Usuarios extra: 3–4 USD.
Dicen explícitamente lo mismo que nosotros: *"los mensajes son gratuitos e
ilimitados siempre y cuando el cliente sea quien inicie la conversación"*.

**Para 12.000 socios**, el Enterprise de CRM Inbox sale a 720 + 50 (los 5.000
contactos que faltan) = **770 USD al trimestre, unos 257 USD al mes**. W-Chat a
ese volumen entra en "a cotizar", pero extrapolando su curva estaría entre 350 y
450 USD al mes.

### Liga B — los generalistas, más baratos y sin ERP

**TecnoChat** — 19 / 27 / 57 / 112 USD al mes, con ~2 meses gratis al año.
Escala por **contactos** y **conversaciones de IA**, no por agentes. Su tope son
40.000 contactos y 5 agentes por 112 USD.

**Whaticket** — Basic 49 USD (3 agentes), Pro 109 USD (8 agentes), conexión
extra 20 USD. **Vende créditos de campaña**: dos campañas de 2.000 contactos
≈ 76 USD extra al mes. Con las tarifas reales de Colombia esos 4.000 mensajes
utility le cuestan a Meta unos 3,6 USD. Su IA además obliga al cliente a comprar
tokens de OpenAI por su cuenta.

**Cliengo** desde 45 USD/mes, enfocado a captación.
**Kommo** 15–45 USD por usuario/mes con permanencia de 6 meses: a partir de
cinco agentes se dispara.

### Lo que se aprende de todos ellos

1. **Nadie cobra por mensaje.** Todos pasan el consumo de Meta al cliente. Es el
   estándar del mercado, así que no es una concesión nuestra sino lo normal.
2. **Los del nicho ISP cobran entre 2 y 4 veces más** que los generalistas, y
   nadie se queja: la integración con el ERP es lo que se paga.
3. **El eje de precio correcto no son los agentes**, son los suscriptores o
   contactos. W-Chat lo hace explícito y es el modelo que mejor le encaja a una
   cooperativa: "tienes 12.000 socios, este es tu plan".
4. **Todos facturan por trimestre o año adelantado.** Nadie vende mensual puro,
   y con razón: el costo de captación y montaje no se recupera en un mes.
5. **La IA es un escalón de precio aparte**, no algo incluido. W-Chat cobra un
   50–60 % más por el nivel con IA. Es la forma natural de armar los paquetes.

## Dónde estamos nosotros

**Ventajas reales, no de folleto:**

1. **Cero markup sobre Meta.** Whaticket cobra ~76 USD por lo que cuesta 3,6.
   Nosotros no tocamos ese dinero: el cliente le paga a Meta directo. Es
   verificable en su propia factura, y en una cooperativa —donde hay revisoría
   fiscal— la transparencia vale.
2. **Coexistencia con seis meses de historial.** El cliente no pierde sus
   conversaciones ni deja de usar el celular.
3. **El bot resuelve contra el ERP**, no solo responde. Factura, pago, falla,
   estado del servicio.
4. **Aviso antes de enviar** si la campaña no cabe en el tramo del número. La
   competencia deja que falle y lo descubras contando.
5. **Sin techo de campaña** desde ahora: 25.000 por selección, configurable.
6. **Borrado con aprobación y trazabilidad**, que es lenguaje de auditoría.

**Desventajas que hay que asumir:**

1. Sin Embedded Signup, la conexión es manual y con nosotros delante.
2. No tenemos marca ni catálogo de integraciones como los grandes.
3. El cliente tiene que poner tarjeta en Meta. Un BSP le evita ese trámite.

**La conclusión para cotizar:**

No competimos por precio de mensaje: ahí no cobramos nada y nadie puede bajar de
cero. Competimos en la **liga ISP**, no en la generalista, y ahí el suelo lo
marcan CRM Inbox (~257 USD/mes para 12.000 contactos) y W-Chat (a cotizar por
encima de 5.000 suscriptores). Ponerse al nivel de TecnoChat sería regalar el
trabajo y además restar credibilidad: nadie cree que una integración con el ERP
cueste 112 USD.

Tres decisiones que se derivan de la competencia:

1. **Cobrar por número de socios**, como W-Chat, y no por agentes. Es como
   piensa el cliente y evita la discusión de cuánta gente conectamos.
2. **Tres niveles**: CRM, CRM + Bot, y CRM + Bot + IA. Es la escalera que usa
   W-Chat y funciona porque deja entrar barato y subir después.
3. **Facturar por trimestre o año adelantado**, como hacen todos. El montaje
   —conectar el número, armar plantillas, entrenar al equipo— no se recupera en
   un mes.

El mensaje comercial de una frase: *pagas la plataforma a nosotros y los
mensajes a Meta al costo, sin intermediarios que cobren margen encima*.

## Referencias

- Tarifario oficial: <https://developers.facebook.com/documentation/business-messaging/whatsapp/pricing>
- Tarifas Colombia: <https://www.plivo.com/whatsapp/pricing/co/>
- TecnoChat: <https://tecnochat.com/#precios>
- Whaticket: <https://blog.beexcc.com/whaticket-precios>
- CRM Inbox: <https://crminbox.io/precios/> y <https://crminbox.io/crminbox-mikrowisp/>
- W-Chat: <https://wchatisp.com/>
- Tech Provider vs BSP: <https://developers.facebook.com/documentation/business-messaging/whatsapp/solution-providers/overview>
