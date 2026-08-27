# Introducción

**ÁTICA Calidad** es una aplicación web de gestión documental pensada para servir de apoyo al
sistema de gestión de la calidad (SGC) de un centro educativo: un lugar centralizado donde
organizar la documentación, los plazos y los responsables del sistema de calidad, con acceso
diferenciado por docente y por centro.

Este manual describe el esqueleto actual de la aplicación. Muchas secciones todavía están vacías o
son mínimas a propósito: el proyecto está en una fase inicial de construcción y este manual crecerá
a la vez que la aplicación.

## Quién es quién

- **Docente** — accede a la aplicación con su usuario y consulta el calendario del centro.
- **Responsable de calidad** — coordina el sistema de gestión de la calidad del centro.
- **Auditor/a interno/a** — realiza las auditorías internas del sistema de gestión de la calidad.
- **Administración del centro** — configura el centro educativo y tiene acceso completo a sus
  datos. Este papel corresponde, normalmente, al **equipo directivo**.
- **Administración de la plataforma** — mantiene el servidor y puede gestionar todos los centros
  alojados en él.

El detalle completo de lo que puede hacer cada perfil está en
[Permisos de un vistazo](08-permisos-de-un-vistazo.md).

## Acceso a la aplicación

Solo el profesorado registrado puede acceder, con usuario y contraseña propios o mediante
autenticación externa (iSéneca). Un mismo servidor puede alojar **varios centros educativos** con
datos completamente separados; quien pertenece a más de un centro elige con cuál trabajar al
entrar y puede cambiar de centro en cualquier momento desde el menú lateral.

## Cómo usar este manual

No hace falta leerlo de principio a fin; cada persona puede ir directamente a lo que necesita:

- **¿Formas parte del equipo directivo?** Empieza por
  [Preparar el curso académico](02-preparar-el-curso-academico.md) y consulta
  [Administrar el centro educativo](05-administrar-el-centro.md).
- **¿Eres responsable de calidad, o vas a repartir tutorías, jefaturas u otras responsabilidades
  del centro?** Consulta [Responsabilidades](06-responsabilidades.md).
- **¿Vas a instalar la aplicación o mantener el servidor?** Los capítulos
  [Instalación y puesta en marcha](01-instalacion-y-puesta-en-marcha.md) y
  [Administrar la plataforma](07-administrar-la-plataforma.md) son los únicos con contenido
  técnico. Si tu centro ya tiene ÁTICA Calidad en marcha, puedes saltártelos por completo.

## Sobre el proyecto

ÁTICA Calidad es software libre, publicado bajo licencia
[AGPL-3.0](http://www.gnu.org/licenses/agpl.html) y desarrollado con [Symfony](https://symfony.com).
