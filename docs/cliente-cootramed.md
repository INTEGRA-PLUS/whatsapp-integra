# COOTRAMED — lo que sabemos antes de la reunión

Todo lo de aquí es información pública, sacada de su web y de fuentes del sector
(sep-2026). Lo que es dato aparece como dato; lo que es deducción se dice.

## Quiénes son

Cooperativa de ahorro y crédito **fundada en 1938**, con personería reconocida
por Resolución 329 de diciembre de ese año. Casi noventa años. NIT
**890905859-3**, domicilio en Medellín, Carrera 54 No. 40A-26.

Está **vigilada por la Superintendencia de la Economía Solidaria** como
cooperativa especializada de ahorro y crédito, y es asociada de Confecoop
Antioquia.

**Vínculo abierto:** puede asociarse cualquier persona natural mayor de 14 años
—con representante legal si es menor— y también personas jurídicas. No es un
fondo cerrado de empleados de una sola empresa, que es lo que explica los 12.000
socios.

## Dónde están

Cinco agencias y dos extensiones de caja, casi todas fuera del área
metropolitana:

| Agencias | Extensiones de caja |
|---|---|
| Medellín (Centro Comercial Sandiego) | Alpujarra |
| Caucasia | La Pintada |
| Tarso | |
| Chigorodó | |
| Arboletes | |

Más una red de corresponsales solidarios.

**Esto importa para la venta.** Caucasia, Chigorodó y Arboletes están en el Bajo
Cauca y Urabá, a cinco o seis horas de Medellín. Un socio de Arboletes no se
acerca a una agencia para preguntar el saldo de su crédito. WhatsApp no es un
canal más para ellos: es probablemente **el único canal viable** para buena parte
de su base.

## Qué venden

- **Ahorro:** a la vista, a término y CDAT.
- **Crédito:** por **libranza** y por taquilla.
- **Solidaridad:** auxilios y subsidios.
- **Asistencias:** médica, odontológica, hogar, legal y mascotas.
- **Convenios:** medicina prepagada, turismo, seguros, educación, emergencias
  médicas y deporte.

La libranza es descuento por nómina, así que ese crédito se cobra solo. Los
recordatorios de pago pesan más en **crédito por taquilla**, que es donde el
socio tiene que acordarse. Conviene preguntar el reparto entre las dos: cambia
cuánto valor tiene una campaña de cobranza.

## Su tecnología

Esto es lo más importante que encontramos.

- **Portal Natural** (`portalnatural.redcoopcentral.com`) — su portal
  transaccional. Es de la **Red Coopcentral**, o sea del Banco Cooperativo
  Coopcentral, no un desarrollo suyo.
- **Redcoopagos PSE** para pagos.
- **Trámites Virtuales** y formulario de PQRSF en línea.
- **Telegram** y tutoriales en video.
- **WhatsApp: 312 602 1105, y la web menciona un chatbot.**

### Lo que esto significa

**1. Ya tienen WhatsApp con un bot. Esto no es una venta desde cero.**
Es una sustitución o una mejora, y cambia el discurso entero: no hay que
convencerlos de que WhatsApp sirve —ya lo decidieron— sino de que lo que tienen
se queda corto. La primera pregunta de la reunión debería ser **qué usan hoy y
qué les falta**, no una demo de funciones.

**2. Hay que averiguar si ese número está en la app del celular o ya en la API.**
De eso depende toda la propuesta técnica:

- **Si está en WhatsApp Business App (celular)** → la coexistencia es
  exactamente el camino: conservan el número, siguen atendiendo desde el
  teléfono si quieren, y se importan hasta **seis meses de historial**. Es
  nuestro mejor argumento y aquí encaja perfecto.
- **Si ya está en la API con otro proveedor** → es una migración, hay contrato
  de por medio, y hay que preguntar qué les molesta de lo que tienen.

**3. La fase 2 se integra contra Coopcentral, no contra un proveedor pequeño.**
Coopcentral tiene APIs abiertas y estrategia de open banking: hay servicios de
consulta de cuentas, pagos y transferencias, e iniciación de pagos. Técnicamente
es viable y hasta cómodo.

Pero hay que decirlo con realismo: **es un banco**. Su proceso de integración
tiene gobierno, revisión de seguridad y tiempos que no dependen de nosotros ni
de la cooperativa. No se puede prometer una fecha sin haber hablado con ellos.
Lo correcto es vender una fase de descubrimiento corta.

## Cómo encaja lo nuestro

**Lo que les resuelve el día uno, sin tocar su core:**
- Bandeja compartida para las siete sedes, con reparto automático por carga. Hoy
  un solo número atendido a mano no da abasto con 12.000 socios.
- Menús informativos: horarios y direcciones de cada agencia, requisitos para
  asociarse, líneas de crédito, tasas, estado de trámites.
- **Radicar PQRSF por WhatsApp** en vez del formulario web. Para una entidad
  vigilada, las PQRSF tienen plazos legales de respuesta: un canal que las
  registre con trazabilidad tiene valor de cumplimiento, no solo de comodidad.
- Campañas de cuota y mora para el crédito de taquilla, y avisos de asamblea.
- Coexistencia: conservan el número y el historial.

**Lo que necesita el conector a Coopcentral (fase 2):**
- Saldo de ahorros, cuota y estado del crédito.
- Certificados y extractos.
- Pago en línea desde el chat.

## Lo que hay que preguntarles

En este orden, porque las tres primeras cambian la propuesta:

1. **¿Qué usan hoy en el 312 602 1105?** ¿App del celular o API? ¿Con qué
   proveedor? ¿Qué les falta?
2. **¿El bot actual resuelve o solo informa?**
3. **¿Ya hablaron con Coopcentral de integrarse por API?** Si hay un
   interlocutor técnico, la fase 2 se acorta mucho.
4. ¿Cuántas personas atienden hoy el WhatsApp, y desde qué sedes?
5. ¿Cuántos de los 12.000 socios tienen crédito por taquilla?
6. ¿Cuántos mensajes reciben al día, y cuáles son las tres preguntas que más se
   repiten? Esas tres son el bot de la fase 1.

## Lo que van a preguntar ellos, y hay que llevar preparado

Es una entidad vigilada de casi noventa años. La conversación no va a ser solo
de funciones:

- Contrato de encargo del tratamiento de datos personales (Ley 1581).
- Dónde viven los datos y quién de nuestro equipo puede ver conversaciones.
- Trazabilidad: quién respondió qué y cuándo.
- Qué pasa con las conversaciones si se termina el contrato.
- Continuidad: qué ocurre si la plataforma se cae un día de recaudo.

Tres cosas del producto juegan a favor aquí y conviene sacarlas: el **borrado de
conversaciones pasa por aprobación** y queda registrado, cada mensaje sabe **qué
agente lo envió**, y el aislamiento entre empresas está **probado con tests
automáticos**.
