# 14-sep-2026: el semáforo de emociones, y por qué casi todo el trabajo fue calibrarlo

**Qué se construyó.** Una extensión que marca cada conversación en verde, amarillo o rojo según
cómo esté el cliente, con filtro y orden en la bandeja y una sección en Reportes. Dos capas: una
matriz de palabras que decide al instante dentro del webhook, y una capa de IA opcional que repasa.

**Lo que decidió el diseño no fue el algoritmo.** Fue esto, que sale de mirar cómo fallan estas
herramientas en producción:

## 1. Tener un problema no es estar enfadado

Zendesk lo dice explícito en su documentación de clasificación automática: un ticket **no** es
negativo sólo porque el cliente tenga un problema. *Todo* el que escribe a soporte tiene uno.

Si "no funciona", "falla" o "error" pesaran negativo, la bandeja entera sería roja el primer día y a
la semana nadie la miraría. Por eso esas palabras **no están en el léxico**: describen la avería, no
el ánimo de quien la reporta.

> La pregunta que responde el color no es "¿este cliente tiene un problema?" sino **"¿hay que
> atenderlo antes que a los demás?"**.

Es la regla que más tests tiene (`test_quien_reporta_una_averia_con_calma_sigue_en_verde`), y la
primera que se romperá si alguien "mejora" el diccionario añadiendo vocabulario de averías.

## 2. Un léxico solo rinde ~50 % en soporte real

Medido contra llamadas reales de centro de contacto, las herramientas de léxico puro aciertan
alrededor del 50 % —apenas mejor que una moneda— frente al 85 %+ de un modelo de lenguaje. Por eso
el léxico **no es el clasificador**: es el suelo. Instantáneo, gratis, explicable y disponible
aunque n8n esté caído.

También por eso el léxico es **propio y corto** en vez de uno académico (iSOL, ElhPolar, SEL): ésos
son de polaridad *general* —hechos para reseñas—, su licencia comercial no está clara, y para un ISP
colombiano `tutela`, `SIC` y `Superservicios` valen más que cinco mil adjetivos.

## 3. Lo caro es el falso negativo

Un modelo con 88 % de acierto global que se traga uno de cada cinco clientes furiosos es peor, para
esto, que uno con 82 % que no se traga ninguno. De ahí tres decisiones:

- Ante el empate gana lo negativo (`FRENO_POSITIVO`: un "gracias" en un mensaje con queja cuenta al 40 %).
- Los umbrales son asimétricos respecto al cero: el amarillo empieza pronto.
- Un color que la IA devuelve y no reconocemos **no** se convierte en verde: se descarta la respuesta entera.

## 4. Manda la trayectoria, y el silencio no es calma

Los casos que escalan se distinguen por cómo **evoluciona** el ánimo, no por una frase. Decaimiento
exponencial (0,6) sobre la ventana de mensajes.

Dos ajustes que costaron un test cada uno:

- **Se enfada rápido y se calma despacio** (`INERCIA` 0,5). Sin eso, un cliente furioso al que le
  dicen "ya lo reviso" y responde "ok" volvía a verde de golpe, y quien tomara el chat después
  entraba a ciegas.
- **Los mensajes neutros no votan, pero consumen posición.** Apareció al escribir el histórico: un
  "hola" previo partía por la mitad un mensaje de insultos y lo dejaba en amarillo, justo en el
  límite. Un cliente que te llama ladrón es rojo venga de donde venga.

## 5. No todo es texto

En 70.000 conversaciones de soporte, estimar *si al cliente lo ayudaron* predijo su valoración real
mejor que el sentimiento (0,47 frente a 0,36). Por eso cuentan también las señales que no se leen:
tres mensajes seguidos sin que nadie conteste encienden el semáforo sin una sola palabra fea.

## Decisiones de dominio colombiano

- **`marica` y `parce` NO son insultos** para el léxico. En Colombia funcionan como muletilla entre
  iguales mucho más que como agresión, y meterlas pintaría de rojo a media Medellín escribiendo con
  normalidad. Una empresa que quiera tratarlas así tiene el campo `palabras_rojas`.
- **Escribir en mayúsculas no es gritar.** Mucha gente mayor escribe entera en mayúsculas por
  costumbre y son una parte nada pequeña de quien escribe a un ISP. Las mayúsculas **multiplican** lo
  que ya es negativo; no lo crean.
- **Sin nombres de la competencia** en el léxico de fuga. "Claro" es además la palabra más común para
  decir que sí.

## Las tres desviaciones del módulo de Extensiones

El plan del módulo dice "ni migración, ni ruta, ni pantalla". Ésta rompe las tres, a conciencia:

| Desviación | Por qué |
|---|---|
| **Migración** | La bandeja filtra y ordena por color. Un `WHERE` sobre una ruta JSON en `metadata` no usa índice. |
| **`company_id` en el histórico** | Los diez modelos sin `company_id` se consultan *desde una conversación*; éste se consulta **agregado por empresa** cada vez que se abre Reportes. |
| **Pantalla** | La sección va en Reportes, que es donde ya se miran los números, y no en una pantalla nueva del módulo. |

## Accesibilidad: rojo y verde es el peor caso posible

En torno al **8 % de los hombres** no distingue rojo de verde. El validador de paleta da
`CVD ΔE 8.9` (pasa por poco) y **avisa de contraste**: verde y ámbar quedan por debajo de 3:1 sobre
fondo claro. Por eso en Reportes cada color va con **su icono y su palabra**, y con el número
visible. El color nunca va solo.

Queda un hueco conocido: el punto de la lista de chats lleva la etiqueta sólo en el `title` (al pasar
el ratón). Es información complementaria —la fila se entiende sin él y el filtro da lo mismo en
texto—, pero si alguien reporta el problema, ahí está.

## Lo que NO hace, y es deliberado

No avisa a nadie, no reasigna, no etiqueta y no apaga el bot. **Marca y ya.** Un semáforo que
dispara acciones hay que acertarlo antes de encenderlo, y para acertarlo hace falta verlo sobre
conversaciones reales. Cuando esté calibrado, las acciones son un ajuste más.

Tampoco juzga al agente: la tabla "rojos por agente" lo dice en la propia pantalla, porque sin esa
frase se lee como un ranking a los cinco segundos —y al mejor agente se le asignan los casos
difíciles.

## El flujo de n8n

Los dos nodos Code están en `~/Desktop/proyects/n8n/local-files/sentimiento-*.js`. El prompt repite
**la misma regla de calibración** que `App\Support\Sentimiento\Semaforo`, y esa duplicación es
deliberada: si las dos capas calibraran distinto, el color parpadearía cada vez que la IA repasa lo
que la matriz acaba de decidir, y un semáforo que cambia solo deja de creerse a la primera.

Recordatorio: **los flujos no viven en ningún repo**, están en `workflow_entity` de Postgres.
