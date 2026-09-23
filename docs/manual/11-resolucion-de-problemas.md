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

## Se ha cerrado mi sesión

Por seguridad, la sesión se cierra sola tras un rato sin usar la aplicación (dos horas por
defecto; la administración de la plataforma puede cambiarlo o desactivarlo en
[Ajustes → Seguridad](09-administrar-la-plataforma.md#seguridad)). La pantalla de inicio de sesión
lo indica; basta con volver a entrar.

## No me deja poner una contraseña

Una contraseña nueva debe tener al menos 12 caracteres, no puede contener tu nombre de usuario y no
puede ser una de las que aparecen en filtraciones de datos conocidas (una frase de varias palabras
suele cumplirlo todo). Esta última comprobación consulta el servicio «Have I Been Pwned» sin
enviarle la contraseña —solo el principio de su huella— y se omite si el servidor no tiene salida a
Internet (o si la administración la desactiva con `APP_PASSWORD_BREACH_CHECK=false`).

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
