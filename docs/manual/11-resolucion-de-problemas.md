# Anexo: Resolución de problemas

## No puedo iniciar sesión

- Comprueba que el usuario y la contraseña son correctos. Tras varios intentos fallidos, la
  aplicación bloquea temporalmente los siguientes intentos (protección contra fuerza bruta):
  espera unos minutos y vuelve a intentarlo.
- Si tu cuenta usa autenticación externa (iSéneca) y no puedes acceder, comprueba con la
  administración de la plataforma que `APP_EXTERNAL_ENABLED` está activo y que el servicio externo
  responde.
- Si la cuenta está desactivada, solo la administración del centro o de la plataforma puede
  reactivarla.

## He olvidado mi contraseña

Desde la pantalla de inicio de sesión, pulsa **¿Olvidaste tu contraseña?** e introduce tu nombre de
usuario. Si tu cuenta tiene un correo electrónico configurado y no usa autenticación externa,
recibirás un enlace válido durante una hora. Si no te llega, comprueba la carpeta de spam o
contacta con la administración del centro.

## No veo un centro educativo al que debería tener acceso

Solo la administración de la plataforma puede añadirte al equipo directivo de un centro
(**Administración → Centros educativos**) o la administración de ese centro puede añadirte a su
curso activo (**Centro educativo → Docentes del centro**).

## No me llegan los correos de la aplicación

Comprueba con la administración de la plataforma que el correo del servidor está
[configurado y activo](09-administrar-la-plataforma.md#correo-electronico-del-servidor), y que el
worker (Messenger) está en marcha — ver
[Correos en cola](09-administrar-la-plataforma.md#correos-en-cola-messenger).

## La aplicación no arranca tras una actualización

Comprueba que las migraciones de base de datos se han aplicado:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

Si el problema persiste, consulta los registros del servidor (ver
[Administrar la plataforma](09-administrar-la-plataforma.md)) para identificar el error concreto.
