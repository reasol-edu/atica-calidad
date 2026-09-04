# Changelog

Todos los cambios notables de este proyecto se documentan en este fichero.

El formato sigue [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) y el proyecto se adhiere
a [Semantic Versioning](https://semver.org/lang/es/).

## [Unreleased]

### Changed

- **Actividades**: cuando una actividad usa un elemento de lista para nombrar sus entregas, ahora
  cuenta **toda hoja que cuelga de ese elemento** (a cualquier profundidad), no solo las
  asociadas a un perfil/subperfil de subida. Si la carpeta tiene un único perfil de subida, la
  hoja se atribuye a él aunque no esté asociada; si tiene varios, la hoja sigue necesitando estar
  asociada a uno de ellos. El nombre de cada entrega pasa a ser la **ruta completa** hasta la
  hoja, contada desde debajo del elemento elegido (p. ej. «Ciencias › Física» en vez de solo
  «Física»). Las entregas ya subidas con el nombre anterior habrá que renombrarlas para que
  vuelvan a casar con su fila.
- **Actividades**: junto al plazo de una actividad con entregas aparece «Ir a la carpeta», que
  lleva directamente a su carpeta en el árbol documental — el enlace no se muestra si esa carpeta
  en concreto no es visible para el docente (por sus propias restricciones o las de alguna
  sección que la contiene).
- **Árbol documental**: al descargar una carpeta en ZIP, si el nombre de un documento incluye el
  separador de ruta «›» (entregas nombradas por un elemento de lista profundo, ver más arriba), el
  fichero dentro del ZIP lo sustituye por «_» en vez de conservarlo.

### Fixed

- **Responsabilidades → Listas**: el desplegable para asociar un elemento a un perfil/subperfil
  dejaba de funcionar tras seleccionar el primer elemento de un nivel: al cambiar a otro elemento,
  el control quedaba invisible o inerte y solo se podía editar la asociación del primero.

## [0.4.0] - 2026-09-03

### Added

- **Árbol documental**: cada carpeta se puede descargar entera en un archivo ZIP (opción
  «Descargar la carpeta (ZIP)» dentro de la carpeta). Si la carpeta está organizada por perfil de
  subida, cada perfil pasa a ser una subcarpeta del ZIP con su nombre (sustituyendo los caracteres
  que no son válidos en un nombre de fichero); los documentos sin perfil van en la raíz del
  archivo. Se incluye la revisión activa de cada documento.

## [0.3.0] - 2026-09-02

### Added

- Al crear un centro educativo (por consola o desde Administración → Centros educativos) se le
  crean automáticamente tres raíces vacías en Responsabilidades → Listas: **Departamento**,
  **Grupo** y **Materia**, listas para rellenar.

## [0.2.0] - 2026-09-02

### Added

- **Responsabilidades → Listas**: «Importar grupos desde Séneca» e «Importar materias desde
  Séneca» construyen una lista a partir del CSV correspondiente exportado de Séneca (grupos
  anidados bajo la raíz elegida; materias, anidadas dentro de su grupo), con previsualización de
  altas, bajas y reactivaciones antes de confirmar. Los elementos que ya no aparecen en el fichero
  se pueden eliminar (si no están en uso) o desactivar (los que sí lo están, siempre se desactivan).

### Changed

- Subir una nueva revisión o eliminar un documento entero ya no está limitado a quien lo subió en
  persona: cualquier docente que comparta el perfil con el que se etiquetó el documento puede
  hacerlo también, aunque la versión activa no la subiera él — por ejemplo, para que un cambio de
  jefatura de departamento no deje huérfanos los documentos del anterior. Se aplica también dentro
  de una actividad de ámbito por perfil, cuya entrega ya es compartida por todo el perfil; la única
  excepción es una actividad de ámbito individual, donde varios docentes pueden compartir perfil
  sin compartir entrega, así que ahí se mantiene la regla anterior (solo quien la subió en
  persona).

## [0.1.0] - 2026-09-01

### Added

- **Actividades**, nueva sección en el menú lateral (por delante de Calendario), para los plazos y
  tareas periódicas del sistema de calidad, agrupadas en categorías propias del centro:
  - **Mis actividades**: lista personal con total/completadas/pendientes/vencidas, barra de
    progreso, buscador y filtros por fecha límite, perfil, categoría y estado.
  - **Ver**: navegación por categorías con migas de pan, con lo que corresponde al docente o,
    opcionalmente, lo de todo el mundo.
  - **Editar categorías** (mismo papel que Editar árbol): categorías anidables, y el formulario de
    cada actividad — fecha límite (día y mes, se repite cada curso), carpeta del árbol documental
    opcional (entonces se completa entregando un documento, con el mismo flujo de aprobar/rechazar
    que una revisión), ámbito por perfil o individual, compleción automática o manual (con
    confirmación previa y «deshacer» sin confirmar), y documentos relacionados del árbol
    documental.
  - Los plazos se muestran también en el detalle de cada día del calendario y se resumen en el
    panel principal, junto con las revisiones de documentos pendientes del docente y, para
    responsable de calidad/administración, todas las revisiones pendientes del centro.
  - `php bin/console app:load-demo-data`: crea un centro de demostración completo («IES Ada
    Lovelace») con árbol documental ISO 9001:2015, responsabilidades, actividades y calendario,
    para poder probar la aplicación sin datos reales.
- **Avisos por correo electrónico**, configurables a nivel global, de centro y personal: documento
  pendiente de revisar, aceptado o rechazado, y actividad pendiente de completar — cada uno en
  desactivado, individual (al instante) o resumen diario (el valor por defecto de los cuatro, para
  no saturar el correo). Campana de notificaciones en la cabecera con lo pendiente del docente.
  Requiere activar el envío de correo del servidor (ver Administrar la plataforma).
- Ajuste personal **Resultados por página**, para los listados paginados de toda la aplicación.
- Las carpetas del árbol documental admiten una **descripción con formato**, visible a quien la vea.
- Los elementos de una lista de Responsabilidades pueden asociarse a un perfil específico (o a uno
  de sus subperfiles) como referencia cruzada — por ejemplo, una materia con la jefatura de
  departamento de la que depende.
- Identidad visual propia: paleta de color cálida y acogedora («Salvia»), decoración de hojas en la
  pantalla de acceso y logo/favicon nuevos, en sustitución de los heredados del proyecto del que se
  hizo fork.
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

- El menú lateral pasa a mostrar Actividades justo antes de Calendario.
- La pantalla de Actividades carga notablemente más rápido.

### Fixed

- Al guardar un ajuste desde el navegador (global, de centro o personal) a veces no ocurría nada:
  faltaba un fichero JavaScript en el proyecto.
- El panel de revisión de una entrega, en «Mis entregas», aparecía al final de la lista en vez de
  junto a su fila.
- En pantallas pequeñas, la etiqueta de estado de una actividad podía desbordar la tarjeta en vez
  de bajar a su propia línea (afectaba a «Mis actividades», al panel principal y a la cabecera del
  árbol documental).
- El envío de varias entregas a la vez podía guardar el fichero en la fila equivocada en
  Safari/iOS, y subir una entrega «en nombre de» otro docente podía atribuírsela a quien pulsaba el
  botón en vez de al docente de la fila.
- El buscador de docentes en Asignar perfiles no devolvía resultados para responsables de calidad
  que no fueran también administración del centro.
- El buscador de docentes (al asignarlos a un perfil, o como administrador/a de un centro) ya no
  se queda mostrando la lista completa sin filtrar nada más que el resaltado de las letras
  escritas: hasta alcanzar el mínimo de caracteres configurado, no muestra ningún resultado, en
  vez del listado sin filtrar que se cargaba al hacer clic en el buscador.
- Los listados paginados que ordenan por un campo embebido (por ejemplo, docentes o centros por
  apellidos) ya no aparecían vacíos pese a contar correctamente el total de resultados.
