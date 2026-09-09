# Tarifas

Cómo cotizamos, y el caso trabajado de la cooperativa de 12.000 socios.

Para el análisis de competencia y el tarifario de Meta que sostienen estos
números, ver `producto-y-precios.md`.

## La regla que lo condiciona todo: cobramos la plataforma, no los mensajes

Somos Tech Provider, no BSP: **no podemos poner línea de crédito y no
revendemos mensajes**. El cliente asocia su tarjeta a su propia cuenta de
WhatsApp Business y le paga a Meta directamente, al precio de Meta.

Eso convierte una limitación técnica en el mejor argumento comercial que
tenemos, y hay que decirlo con números, no con adjetivos:

| | Nosotros | Whaticket |
|---|---|---|
| Plataforma | 260 USD/mes | 109 USD/mes |
| 24.000 mensajes de campaña | **~22 USD, pagados a Meta** | ~456 USD en créditos |
| **Total** | **~282 USD/mes** | **~565 USD/mes** |

Los 24.000 mensajes son dos campañas mensuales a 12.000 socios. A Whaticket se
le calculó con su propia tarifa publicada (76 USD por 4.000). La diferencia no
es de plataforma: es del margen que ellos cobran sobre cada mensaje y nosotros
no.

## Los tres niveles

Escalera de tres peldaños, que es lo que usa la competencia del nicho y funciona
porque deja entrar barato y subir después. Precios para **hasta 15.000
contactos**, con **agentes ilimitados**.

| | **Conversacional** | **Automatizado** | **Inteligente** |
|---|---|---|---|
| **Al mes** | 160 USD | 210 USD | **260 USD** |
| Por trimestre | 480 USD | 630 USD | 780 USD |
| **Al año** (2 meses gratis) | 1.600 USD | 2.100 USD | **2.600 USD** |

**Conversacional** — la bandeja y el equipo.
Bandeja compartida multiagente con reparto automático por carga real, Kanban,
etiquetas, notas internas y menciones, respuestas rápidas y macros, horarios de
atención, ficha de contacto, historial de 24 meses, exportar conversación a PDF,
informes por agente, llamadas de WhatsApp, coexistencia con importación de hasta
6 meses de historial, y campañas con plantilla.

**Automatizado** — añade el bot que atiende solo.
Menús interactivos por niveles, respuestas automáticas y seguimientos, campañas
programadas y recurrentes, gestión de bajas (opt-out), webhooks salientes hacia
sus sistemas, y la API para que su software envíe y consulte.

**Inteligente** — añade la IA.
Entiende lo que el socio pide en lenguaje natural y lo lleva a la acción
correcta, más conversación abierta con contexto. Incluye **10.000
conversaciones de IA al mes**; por encima, 15 USD por cada 1.000.

### Lo que se cobra aparte

- **Montaje: 600 USD, por una sola vez.** Conexión del número con Meta,
  verificación del negocio, creación y aprobación de plantillas, diseño de los
  menús, importación de la base de socios y formación del equipo.
  **Se condona pagando el año por adelantado.**
- **Línea adicional de WhatsApp: 40 USD/mes.**
- **Contactos por encima de 15.000: 12 USD por cada 1.000.**
- **El consumo de Meta**, que el cliente paga directo y no pasa por nosotros.

## El caso de la cooperativa: 12.000 socios

### Lo que costaría de verdad, al mes

| Concepto | USD |
|---|---|
| Plataforma, nivel Inteligente | 260 |
| Mensajes de Meta: 12.000 avisos de cuota (utility) | ~11 |
| Segunda campaña mensual, 12.000 más | ~11 |
| **Total mensual** | **~282** |

Pagando el año por adelantado: **2.600 USD de plataforma** (dos meses gratis),
montaje condonado, más lo que consuman en Meta. Sale a unos 217 USD al mes.

Conviene enseñar esta tabla tal cual. Que el 8 % del costo sea Meta y el resto
plataforma es exactamente el argumento: no hay letra pequeña.

### Dos fases, porque la cooperativa no usa Integra

Nuestras acciones de negocio —consultar factura, pagar, reportar falla, estado
del servicio— están construidas contra Integra 2.0. La cooperativa usa otro
core, así que el día uno el bot informa pero no resuelve.

**Fase 1 — desde el primer mes.** Todo lo del nivel Inteligente: bandeja para
los 12.000 socios, campañas de cuota y mora, menús informativos (horarios,
sedes, requisitos, tasas, estado de trámites), IA conversacional, coexistencia.
Esto ya automatiza lo que hoy hacen a mano, que es lo que pidieron.

**Fase 2 — conector a su core, cotizado aparte.** Consultar saldo y cuota,
estado del crédito, certificados y extractos, radicar PQRS en su sistema. **No
se cotiza sin ver primero contra qué**: si su core tiene API documentada son
semanas; si hay que negociar acceso con su proveedor, meses. Lo correcto es
vender una fase de descubrimiento corta y cotizar después con datos.

Ponerlo en dos fases no es una debilidad de la propuesta: es lo que permite
arrancar en semanas en vez de esperar meses a que se resuelva la integración.

## Qué mirar antes de firmar

**El tramo de mensajería.** Un número nuevo empieza pudiendo escribirle a 250
personas nuevas cada 24 horas. Llegar a los 12.000 son semanas de calentamiento,
y no hay forma de acelerarlo. Hay que pedir el número y empezar ese proceso el
primer día, en paralelo a todo lo demás. Es el único punto del proyecto que no
se puede comprimir con más trabajo.

**El medio de pago en Meta.** Sin tarjeta asociada no salen mensajes, y nosotros
no podemos cubrirlo. En una cooperativa eso pasa por tesorería: hay que
arrancarlo el primer día.

**La conexión es manual.** Embedded Signup está configurado en Meta pero sin
construir: hoy el número se conecta pegando tokens, con nosotros delante. Para
un cliente está bien; para veinte no escala, y conviene tenerlo en el plan.

**Los otros once clientes.** Esta es nuestra primera tarifa. Antes de firmar
conviene tener decidido qué pasa con los ISP que hoy usan la plataforma sin
pagar: tarifa de fundador, gratuidad por contrato, o subida por fases. Si se
enteran por su cuenta, la conversación es peor.

## Nota sobre la moneda

Estos precios están en dólares porque así cotiza toda la competencia. Para una
cooperativa colombiana conviene ofrecer el equivalente en pesos con **ajuste
anual por IPC o TRM**: los consejos de administración aprueban presupuestos en
pesos, y un precio en dólares introduce una incertidumbre que puede frenar la
decisión sin que el precio sea el problema.
