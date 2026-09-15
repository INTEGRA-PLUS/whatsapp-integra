# Flujos de n8n

**Los flujos no viven aquí.** Viven en `workflow_entity` del Postgres de n8n, en
`178.238.224.239`. Lo que hay en esta carpeta es la copia del JSON tal y como se
importó, que es lo único que queda versionado.

Eso significa que **un cambio hecho a mano en la interfaz de n8n no se refleja
aquí**. Si tocas un flujo y quieres conservarlo, expórtalo y actualiza el fichero.

## Cómo se importa

Con el CLI, no por la interfaz ni escribiendo en Postgres —con tres workers en
marcha, escribir la tabla a mano es la clase de atajo que rompe cosas difíciles
de diagnosticar—:

```bash
scp docs/n8n/whatsapp-resumen.json root@178.238.224.239:/tmp/f.json
ssh root@178.238.224.239 \
  'docker cp /tmp/f.json n8n-n8n-main-1:/tmp/f.json && \
   docker exec -i n8n-n8n-main-1 n8n import:workflow --input=/tmp/f.json'
```

Dos requisitos que no están en la documentación de n8n:

- El JSON necesita un campo **`id`** en la raíz. Sin él falla con
  `null value in column "id" of relation "workflow_entity"`.
- Conviene `"active": false`. Importar un flujo y encender un webhook público
  son dos decisiones distintas, y la segunda es de quien opera.

Importar con un `id` que ya existe **sobrescribe** ese flujo. Para clonar en vez
de reemplazar, cambia el `id` antes.

## Qué hay

| Fichero | Flujo en n8n | Documentado en |
|---|---|---|
| `whatsapp-resumen.json` | WhatsApp · Resumen de conversación | [`../resumen-de-conversacion.md`](../resumen-de-conversacion.md) |

Los demás flujos —semáforo, menús con IA, chatbot— se montaron a mano y todavía
no están exportados aquí.
