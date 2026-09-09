# Repartir las columnas de un tablero en grupos

Un tablero con muchas columnas casi nunca es un embudo largo: suelen ser varios
tableros distintos puestos en la misma fila. Star NET tenía 43 columnas que eran
seis preguntas diferentes sobre la misma conversación —en qué área está, qué
falla tiene, en qué estado va el ticket, de qué municipio es— y había que
elegir una sola, porque una tarjeta sólo puede estar en una columna.

Con grupos, cada pregunta es un tablero y las demás se vuelven filtros de
arriba. El comando reparte las columnas sin tener que hacerlo a mano:

```bash
# Enseña lo que haría, sin tocar nada
php artisan kanban:agrupar-columnas "Star NET" --archivo=docs/agrupaciones/star-net.json

# Lo escribe
php artisan kanban:agrupar-columnas "Star NET" --archivo=docs/agrupaciones/star-net.json --aplicar

# Y lo devuelve todo a un solo tablero
php artisan kanban:agrupar-columnas "Star NET" --deshacer --aplicar
```

Los nombres se comparan sin distinguir mayúsculas ni tildes, así que «COVEÑAS»
encuentra la columna aunque esté escrita «Coveñas».

## La bandeja

`"bandeja"` marca la columna que recoge lo que **no** lleva ninguna etiqueta de
ese grupo. Es la bandeja de entrada: en «Estado» tiene que ser «Nuevo», porque
si no, una conversación que acaba de entrar no aparece en ninguna parte.

**Un grupo puede no tener bandeja, y a veces debe no tenerla.** En «Zona», una
conversación sin municipio no es de Cereté por ser Cereté la primera columna:
ahí es correcto que la suma de las columnas sea menor que el total. Ese es
justo el motivo de que la bandeja sea una marca y no «la primera columna»
(9-sep-2026): al preparar la agrupación de Star NET, la regla anterior habría
volcado sus 1.000 conversaciones sin clasificar dentro de CERETE.

## Lo que se deja fuera a propósito

En `star-net.json` faltan cuatro de sus 43 columnas, y es deliberado:

- **INSTALACION**, **SOPORTE TV**, **EMPRESAS** — no está claro si son trámite,
  área o zona. Que lo diga el cliente.
- La segunda **COVEÑAS**, la segunda **MONTERIA** y la segunda **COVEÑAS BASE
  NAVAL** son duplicados exactos: hay que fusionarlas antes, no agruparlas.
  Mover las tarjetas de una a otra y borrar la sobrante.

Lo que no se menciona en el archivo se queda sin grupo y aparece como un grupo
aparte llamado «Sin agrupar». No desaparece nada: se queda a la vista para que
alguien decida.
