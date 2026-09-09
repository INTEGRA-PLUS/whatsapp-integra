---
name: diagnosticar-whatsapp
description: Diagnostica por qué no entran o no salen mensajes de WhatsApp en una empresa o instancia. Úsalo cuando alguien reporte "no me llegan los mensajes", "el bot no responde", "la instancia aparece activa pero no funciona" o tras un despliegue que rompió los webhooks.
---

# Diagnosticar WhatsApp

Sigue este orden. Está puesto de más barato a más caro, y las tres primeras causas explican la mayoría de los
incidentes reales de este proyecto.

Si el entorno es Docker, prefija cada comando con `make artisan cmd="..."`.

## 1. ¿Están las variables de entorno? (la causa número uno tras un despliegue)

```bash
php artisan tinker --execute="dump(config('services.meta.webhook_app_secrets'));"
```

Si sale vacío, **todos los webhooks están cayendo con 403** y no entra ni un mensaje. `META_APP_SECRETS` es una
lista `"<app_id>:<secreto>,<app_id>:<secreto>"`.

Y recuerda que **el contenedor no lee el `.env` del host**: comprueba que la variable esté en el bloque
`x-app-env` de `docker-compose.yml`, no solo en el `.env`.

## 2. El diagnóstico que ya existe

```bash
php artisan whatsapp:diagnose
```

Está escrito justo para esto. Léelo entero antes de seguir.

## 3. ¿Sigue viva la suscripción del webhook en el WABA?

```bash
php artisan whatsapp:check-subscription
```

## 4. ¿El token y el número siguen existiendo en Meta?

```bash
php artisan whatsapp:health-check
```

Ojo: este comando **solo avisa cuando el estado cambia**, así que en una ejecución manual puede callar aunque
haya problema. Una instancia puede mostrarse "Activa" en verde con el token ya revocado — pasó con cinco
empresas el 2026-09-05, la más antigua rota desde hacía seis meses.

## 5. Si entran pero no salen: la ventana de 24 h

```bash
php artisan whatsapp:window-guard
php artisan whatsapp:fallback-template
```

Fuera de la ventana solo se aceptan plantillas aprobadas. Si la plantilla de respaldo está en `PENDING`,
`REJECTED` o `MISSING`, los envíos de texto libre a clientes inactivos fallan aunque el chat funcione con quien
acaba de escribir.

## 6. El log

```bash
tail -n 200 storage/logs/whatsapp.log
```

Canal `whatsapp`, diario, 30 días. Los campos de coexistencia no se vuelcan ahí a propósito (privacidad), así
que su ausencia en el log no significa que no estén llegando.

## 7. Hilos partidos

Si el síntoma es "me faltan mensajes" más que "no llega ninguno":

```bash
php artisan whatsapp:fix-duplicate-conversations
```

## Al terminar

Resume: qué comprobaste, qué encontraste y qué queda descartado. Si la causa fue una variable de entorno,
dilo explícitamente — es la que más veces se repite y conviene que quede escrita.
