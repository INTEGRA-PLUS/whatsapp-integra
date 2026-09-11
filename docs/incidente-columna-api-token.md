# 9-sep-2026: el despliegue tumbó producción siete minutos por una columna sobrante

**Qué pasó.** Al desplegar, el contenedor de la aplicación entró en bucle de reinicio. La migración
`2026_09_09_120000_add_api_token_to_instances_table` fallaba con:

```
SQLSTATE[42S21]: Column already exists: 1060 Duplicate column name 'api_token'
```

El entrypoint corre `migrate --force` antes de arrancar php-fpm, así que una migración que falla deja el
contenedor sin arrancar. Siete minutos de 502 en el sitio **y en el webhook**. No se perdió nada: Meta
reintenta los webhooks, y al volver el servicio la cola estaba vacía y sin trabajos fallidos.

**La causa.** La migración de julio `2026_07_27_000001_rename_api_token_to_access_token_on_instances_table`
renombró `api_token` a `access_token`, pero en **producción** quedó además una columna `api_token` de tipo
`text`, sin índice y con **cero valores** en las 50 instancias. No está en ningún otro entorno, así que la
migración nueva pasó en local y en los tests y sólo reventó al llegar al servidor.

**Cómo se arregló.** Copia de `instances` a `/root/respaldo-instances-20260909-2025.sql.gz`, y la columna
sobrante **renombrada, no borrada**:

```php
Schema::table('instances', fn ($t) => $t->renameColumn('api_token', 'api_token_sobrante_jul2026'));
```

Renombrar y no borrar fue deliberado: durante una caída no es momento de decidir que algo es basura. Se
comprobó primero que tuviera cero valores. Después, `migrate` creó las tres columnas que quería
—`api_token varchar(64) unique`, `api_token_created_at`, `api_token_last_used_at`— y el contenedor arrancó.

**Queda pendiente**: borrar `instances.api_token_sobrante_jul2026`. Está vacía y no la lee nadie, pero
borrar una columna en producción es una decisión de alguien con nombre, no un efecto secundario de un
despliegue.

## Lo que costó más tiempo del que debía

Al recuperar la aplicación, un `docker compose up -d` a medio camino dejó el contenedor `db` **eliminado**,
con la aplicación en pie contestando 200 en `/login` —una ruta que no toca la base— mientras cualquier
consulta fallaba con `getaddrinfo for db failed`. El volumen `whatsapp-integra_db_data` estaba intacto y
recrear el contenedor con `docker compose up -d db` devolvió las 65.268 conversaciones y el millón de
mensajes tal cual.

La lección: **que `/login` conteste 200 no significa que el sitio funcione.** La comprobación buena es una
consulta de verdad:

```bash
docker compose --env-file .env.docker exec -T app php artisan tinker \
  --execute='echo DB::table("instances")->count();'
```

## Para la próxima

Una migración que falla **tumba el sitio entero**, no sólo la funcionalidad que traía: el entrypoint no
arranca php-fpm si `migrate --force` sale con error. Antes de desplegar algo que altere una tabla grande y
vieja, mirar cómo está esa tabla en producción, que no tiene por qué parecerse a la local:

```bash
docker compose --env-file .env.docker exec -T app php artisan tinker \
  --execute='print_r(array_column(DB::select("SHOW COLUMNS FROM instances"), "Field"));'
```
