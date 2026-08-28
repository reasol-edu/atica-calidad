# Changelog

Todos los cambios notables de este proyecto se documentan en este fichero.

El formato sigue [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) y el proyecto se adhiere
a [Semantic Versioning](https://semver.org/lang/es/).

## [Unreleased]

### Added

- Nueva sección **Responsabilidades** en el menú lateral, accesible al responsable de calidad, al
  equipo directivo y a la administración, con tres herramientas:
  - **Listas**: jerarquías propias de nombres para el centro, con la profundidad que se necesite
    (p. ej. «Grupo» → «1º ESO» → «1º ESO-A»), navegables por migas de pan, con estado activo/inactivo
    por elemento, borrado protegido (elementos con hijos o en uso) y ordenación alfabética. Cualquier
    elemento puede llevar etiquetas propias, creadas sobre la marcha y heredadas por sus
    descendientes; las etiquetas huérfanas se eliminan solas.
  - **Perfiles específicos**: cada centro puede crear responsabilidades personalizadas (tutorías,
    jefaturas...) y asignarles docentes directamente, o asociarlas a un elemento de una lista para
    generar automáticamente un **subperfil** por cada hoja descendiente, cada uno con sus propios
    docentes asignados. Los perfiles, como los elementos de lista, pueden marcarse como inactivos.
  - **Asignar perfiles**: vista de trabajo transversal sobre las asignaciones ya existentes, por
    perfil (todos los perfiles/subperfiles activos con sus docentes) o por docente (todos los
    docentes del curso activo con sus perfiles), con búsqueda, paginación, aviso visual de docentes
    que ya no pertenecen al curso activo y un botón para quitarlos de golpe de todos los perfiles
    activos.
- Primera versión del esqueleto de la aplicación, adaptado a partir de la infraestructura genérica
  de [GestConv+](https://github.com/reasol-edu/gestconv-plus): acceso con usuario y contraseña o
  autenticación externa (iSéneca), soporte multi-centro, calendario con eventos de centro (generales
  o restringidos a perfiles/subperfiles de Responsabilidades) y días no lectivos (sin modo tablón),
  sección Informes (todavía vacía), y la administración del centro educativo: cursos académicos,
  docentes, perfiles de responsable de calidad y auditor/a interno/a, registro de avisos por correo
  y ajustes del centro.
- Paleta de color propia (`#8da1b9`, `#95adb6`, `#cbb3bf`, `#dbc7be`, `#ef959c`).
- Sistema de generación de manual de usuario (PDF y web), fichas de referencia rápida y
  presentación, adaptado del proyecto original.
- **Árbol documental**, visible en el menú lateral para todo el profesorado (el contenido visible
  depende de las restricciones de cada nodo — ver más abajo), con dos pestañas:
  - **Editar árbol** (responsable de calidad, equipo directivo/admin. del centro y admin. de la
    plataforma): estructura de **secciones** anidables con la profundidad que se necesite,
    reordenables arrastrando (también entre secciones distintas), restringibles a
    perfiles/subperfiles de Responsabilidades (sin heredarse a las subsecciones), y exportación/
    importación completa del árbol en JSON (la importación sustituye por completo la estructura
    actual, reconstruyendo las asociaciones de perfil por nombre).
  - **Ver**: navegación por el árbol y gestión de su contenido.
    - **Carpetas**, creadas y configuradas por responsable de calidad/equipo directivo/admin.
      dentro de cada sección, con cuatro listas independientes de perfiles/subperfiles —
      **responsables** (gestión completa), **de subida**, **de visibilidad** y **de revisión**—,
      un interruptor para agrupar visualmente sus documentos por perfil de subida, y un estado
      **obsoleta** que oculta la carpeta y su contenido salvo que se active «Mostrar obsoletas».
    - **Documentos y revisiones**: subida por arrastrar-y-soltar (hasta 20 MB por fichero, sin
      restricción de tipo), con confirmación de nombre y perfil de subida; historial de
      **revisiones** numeradas (sin poder repetir número dentro de un mismo documento); flujo de
      **visto bueno** opcional por carpeta (una revisión nueva queda pendiente hasta aprobarse o
      rechazarse; aprobarla la convierte en la revisión activa); descarga siempre de la revisión
      activa, con el nombre del documento y la extensión del fichero subido; mover un documento a
      otra carpeta de la misma sección, renombrarlo, reordenarlo manualmente o
      alfabéticamente, y eliminarlo por completo con todo su historial.
    - **Permisos con una excepción deliberada**: solo quien es responsable de una carpeta puede
      ver su historial completo de revisiones, elegir la revisión activa, o editar/eliminar una
      revisión suelta; quien únicamente subió la revisión activa de un documento conserva, aun sin
      ser responsable, permiso para subirle una nueva revisión o eliminar el documento entero.
    - **Búsqueda** en tres niveles: una barra global sobre todo el árbol del centro (secciones,
      carpetas y documentos, incluyendo perfil de subida y docente de la última revisión), una
      búsqueda local por sección que despliega automáticamente las carpetas y resalta las
      coincidencias, y la paleta de comandos (**⌘K**/**Ctrl+K**) con los mismos tres grupos de
      resultados. Seleccionar un documento desde cualquiera de las tres no abre su panel de
      versiones: lo resalta con un parpadeo suave para localizarlo entre el resto del contenido.
    - Menús de acciones «···» en carpetas y documentos, pensados para pantalla táctil (las
      acciones dejan de depender de hacer *hover*).

### Changed

- Ajustado el uso del color coral de la paleta: ya no es el color de texto por defecto del menú
  lateral ni de los paneles de las pantallas de acceso (podía leerse como aviso o error); ahora se
  reserva como acento puntual (elemento activo del menú, decoración del panel de acceso).

### Fixed

- El buscador de docentes (al asignarlos a un perfil, o como administrador/a de un centro) ya no
  se queda mostrando la lista completa sin filtrar nada más que el resaltado de las letras
  escritas: hasta alcanzar el mínimo de caracteres configurado, no muestra ningún resultado, en
  vez del listado sin filtrar que se cargaba al hacer clic en el buscador.
- Los listados paginados que ordenan por un campo embebido (por ejemplo, docentes o centros por
  apellidos) ya no aparecían vacíos pese a contar correctamente el total de resultados.
