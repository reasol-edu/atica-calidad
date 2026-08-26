# Changelog

Todos los cambios notables de este proyecto se documentan en este fichero.

El formato sigue [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) y el proyecto se adhiere
a [Semantic Versioning](https://semver.org/lang/es/).

## [Unreleased]

### Added

- Perfiles específicos: cada centro puede crear su propia jerarquía de perfiles personalizados
  (hasta dos niveles) y asignarles docentes desde **Centro educativo → Perfiles → Perfiles
  específicos**, con un editor en tiempo real y un botón de ordenación alfabética por columna. Solo
  se pueden asignar docentes a los perfiles sin subperfiles.
- Primera versión del esqueleto de la aplicación, adaptado a partir de la infraestructura genérica
  de [GestConv+](https://github.com/reasol-edu/gestconv-plus): acceso con usuario y contraseña o
  autenticación externa (iSéneca), soporte multi-centro, calendario con eventos de centro y días no
  lectivos (sin modo tablón), sección Informes (todavía vacía), y la administración del centro
  educativo: cursos académicos, docentes, oferta formativa (cursos y grupos), perfiles de
  responsable de calidad y auditor/a interno/a, registro de avisos por correo y ajustes del centro.
- Paleta de color propia (`#8da1b9`, `#95adb6`, `#cbb3bf`, `#dbc7be`, `#ef959c`).
- Sistema de generación de manual de usuario (PDF y web), fichas de referencia rápida y
  presentación, adaptado del proyecto original.

### Changed

- La pantalla **Perfiles** se organiza ahora en dos pestañas: **Perfiles globales** (responsable de
  calidad y auditor/a interno/a, sin cambios de comportamiento) y **Perfiles específicos** (nuevos,
  ver arriba).
- Ajustado el uso del color coral de la paleta: ya no es el color de texto por defecto del menú
  lateral ni de los paneles de las pantallas de acceso (podía leerse como aviso o error); ahora se
  reserva como acento puntual (elemento activo del menú, decoración del panel de acceso).
