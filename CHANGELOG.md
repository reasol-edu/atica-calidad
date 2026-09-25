# Changelog

Todos los cambios notables de este proyecto se documentan en este fichero.

El formato sigue [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) y el proyecto se adhiere
a [Semantic Versioning](https://semver.org/lang/es/).

## [Unreleased]

### Added

- **Índice del árbol documental**: se ensancha o se estrecha arrastrando el borde con el
  contenido, y el botón sobre ese borde lo oculta del todo (o lo recupera) sin perder el ancho
  elegido — la aplicación recuerda ambas cosas para la próxima vez, en ese navegador. Por defecto
  es algo más ancho que antes.

## [1.7.0] - 2026-09-25

### Added

- **Árbol documental**: en pantallas anchas, un índice lateral con toda la estructura de secciones
  (solo las visibles para cada docente) permite saltar a cualquier sección sin volver antes a la
  Raíz. Dentro de una sección se pueden tener varias carpetas abiertas a la vez, en vez de una sola.
  La aplicación recuerda además, por centro, la última sección visitada: entrar en «Árbol
  documental» desde el menú lleva ahí en vez de a la Raíz.
- **Revisión por la dirección**: los apartados que anota el equipo directivo (asistentes, contexto,
  satisfacción, proveedores, recursos, conclusiones) admiten ahora texto con formato (negrita,
  listas, enlaces...), igual que la descripción de una carpeta.
- **Revisar varias entregas a la vez** (Actividades): un botón **Marcar/desmarcar todo** por grupo
  (las del curso actual, y aparte las de cursos anteriores si quedaba alguna). En una entrega
  individual, ahora se ve primero quién la subió, y el grupo o perfil al que corresponde queda al
  lado en un tono más discreto.

### Fixed

- **Recordar tus filtros** (Calendario → Eventos del centro, y cualquier otra pantalla que use el
  mismo mecanismo) no guardaba nada: escuchaba un evento del navegador que esta versión de los
  componentes en vivo nunca llega a lanzar. Ahora sí se guarda y se restaura al volver.

## [1.6.1] - 2026-09-24

**Al actualizar:** esta versión corrige datos de la base de datos (el curso de algunas entregas y
completados). La corrección se aplica sola al arrancar la aplicación (Docker, binario o instalador
de Ubuntu); si la ejecutas de otra forma, lanza `php bin/console doctrine:migrations:migrate` tras
actualizar.

### Fixed

- En las actividades cuyas fechas empiezan antes del inicio del curso académico y terminan después
  (por ejemplo, del 10 al 30 de septiembre, con el curso empezando el 15), las entregas y los
  completados de los primeros días hechos antes de actualizar a la versión 1.5.0 quedaban asignados
  al curso anterior: la actividad volvía a pedir la entrega («Mis entregas» mostraba la zona para
  subirla) aunque la entrega aparecía en la lista de entregas por revisar. Al actualizar, pasan al
  curso que les corresponde; si alguien ya la había vuelto a entregar o a completar, se conserva
  la nueva.
- La revisión conjunta de entregas separa las que quedan pendientes de **cursos anteriores**, con
  su curso y sin marcar, para que no se aprueben o rechacen por error junto con las del curso actual.

## [1.6.0] - 2026-09-24

**Al actualizar:** esta versión cambia la base de datos (Mejora continua). Los cambios se aplican
solos al arrancar la aplicación (Docker, binario o instalador de Ubuntu); si la ejecutas de otra
forma, lanza `php bin/console doctrine:migrations:migrate` tras actualizar.

### Added

- **Mejora continua**, nueva sección del menú lateral para todo el profesorado:
  - **Comunicar incidencia**: cualquier docente cuenta qué no funciona como debería, dónde (si lo
    sabe) y adjunta fotos o documentos, desde la sección o desde la paleta de comandos (⌘K).
  - La coordinación de calidad la **clasifica** desde una bandeja como **no conformidad** (menor o
    mayor), **observación** u **oportunidad de mejora** —con su código, `NC-2026-001`, `OB-…`,
    `OM-…`—, o la **descarta** con un motivo que se comunica a quien la envió.
  - Una no conformidad pasa por el **análisis de causas** (con los 5 porqués como ayuda y la causa
    raíz), las **acciones** reparadoras, correctivas, preventivas o de mejora —con responsable (un
    docente o un perfil), plazo y evidencia— y la **verificación de eficacia**, que se propone sola
    cuando todas las acciones están hechas (30 días después, ajustable). Si no ha sido eficaz,
    vuelve al análisis.
  - Cada ficha dice arriba **qué falta y a quién le toca**, y lleva un historial con comentarios.
    Quien gestiona o audita ve además todas las fichas en tabla o en tablero, con filtros.
  - Lo que toca a cada uno aparece en **Tus próximos pasos** del panel principal, y los avisos por
    correo se pueden desactivar en **Ajustes → Avisos por correo → Avisos de Mejora continua**.
  - Nuevo informe **No conformidades** (PDF y Excel), con el estado de cada ficha, sus acciones y si
    han sido eficaces.
- **Plan de mejora** (Mejora continua → Plan de mejora): las acciones preventivas y de mejora que el
  centro se propone cada curso sin una incidencia detrás, con su código (`PM-2026-001`), lo que se
  quiere conseguir, el proceso, el responsable (un docente o un perfil), el plazo y las evidencias. La
  coordinación de calidad y el equipo directivo las añaden, editan y eliminan; quien es responsable
  las empieza y las marca como hechas contando qué se ha hecho. La página muestra el avance del curso
  («1 de 4 acciones hechas · 1 vencida»), con filtros, y se descarga en PDF o Excel; también en
  **Informes → Plan de mejora**.
- **Indicadores** (Mejora continua → Indicadores):
  - Cada indicador tiene nombre, cómo se calcula, proceso, unidad, si más o menos es mejor y quién lo
    registra (un docente o un perfil); y en cada curso, su **meta**, su **umbral de alerta** y su
    **calendario de medición**.
  - Los **calendarios de medición** son de cada curso y se personalizan por completo: listas de
    periodos con nombre y fechas —por ejemplo 1.ª, 2.ª, 3.ª, Final 1 y Final 2—, creadas desde una
    plantilla (por evaluación, por trimestre, mensual o anual) o en blanco. Un curso nuevo puede
    copiar los del anterior, con las fechas un año después y las metas de sus indicadores.
  - Al terminar cada periodo, quien registra el indicador tiene la tarea **Registrar** (panel
    principal, campana, calendario y recordatorio diario) durante 15 días (ajustable); pasado ese
    plazo, consta como **Sin dato**.
  - Cada valor queda **En meta**, en **Alerta** o **Fuera de meta**. Uno fuera de meta avisa a la
    coordinación de calidad, que decide desde el indicador: **abrir una no conformidad** o **proponer
    una acción de mejora** (con los datos ya escritos y enlazadas al valor), o anotar que **no
    requiere actuación**.
  - Un **cuadro** por curso, agrupado por proceso, con el último valor de cada indicador, su estado,
    una gráfica y el valor del curso anterior; y la página de cada indicador, con la evolución del
    curso frente al anterior, la meta y el umbral. Se descarga en PDF o Excel; también en
    **Informes → Cuadro de indicadores**.
- **Auditoría interna** (Mejora continua → Auditorías):
  - Un **programa de auditorías** por curso —qué procesos se auditan, en qué mes, con qué equipo—,
    que prepara la coordinación de calidad (o copia del curso anterior) y **aprueba el equipo
    directivo**; si después se añade o quita una auditoría, vuelve a quedar pendiente. Se ve como
    línea del curso y como lista, y se descarga en PDF.
  - **Aviso de independencia** cuando alguien del equipo auditaría su propio proceso (es responsable
    de una carpeta del alcance). Las **personas auditadas** son quienes lo son.
  - Una **biblioteca de listas de comprobación**, con una por apartado de la ISO 9001 pensada para
    un centro educativo, que el centro edita, amplía y exporta o importa en JSON.
  - Cada auditoría se **prepara** (fecha y hora, que van al calendario del equipo y de las personas
    auditadas, y su lista, copiada de la biblioteca y ajustable) y se **realiza en una pantalla
    pensada para tableta**: por punto, Conforme, Observación, No conformidad (menor o mayor),
    Mejora o No aplica, la evidencia y fotos o documentos, todo guardado al momento.
  - Al **emitir el informe**, cada no conformidad, observación o mejora se convierte en una **ficha
    ya clasificada** y enlazada a la auditoría; las personas auditadas, el equipo y la coordinación
    de calidad reciben el aviso, y se genera el **informe en PDF** con el membrete del centro. La
    auditoría se **cierra sola** cuando se cierran todas sus fichas.
  - El equipo tiene la auditoría como tarea desde un mes antes, y el equipo directivo, la de
    aprobar el programa.
- **Revisión por la dirección** (Mejora continua → Revisión por la dirección):
  - Cada revisión tiene su fecha y el **periodo** que repasa (por defecto, desde la anterior hasta
    hoy), y reúne en el orden de la ISO 9001 (9.3.2) lo que la aplicación sabe de él: qué ha sido de
    las decisiones de la revisión anterior, quejas y reclamaciones, el cuadro de indicadores,
    actividades y documentos, fichas y no conformidades abiertas, eficacia de las acciones,
    auditorías internas, plan de mejora y oportunidades de mejora, con enlace a cada elemento.
  - En la reunión se **anotan** los asistentes, los cambios en el contexto, la satisfacción, los
    proveedores, los recursos y las **conclusiones**. Cada **decisión** es una acción del plan de
    mejora enlazada a la revisión, con responsable y plazo.
  - El **equipo directivo la cierra**: los datos quedan fijados como estaban ese día y ya no se
    puede modificar. El **acta en PDF**, con el membrete del centro, sale como borrador mientras
    está abierta.
- **Las tareas de Mejora continua, donde ya se mira**: clasificar, analizar causas, hacer una acción
  (de una ficha o del plan de mejora) y verificar la eficacia aparecen también en la **campana**, en
  el **calendario** (el día en que vencen, y las acciones ya hechas, tachadas) y en el
  **recordatorio diario por correo** de actividades pendientes, que llega también a quien solo tiene
  tareas de Mejora continua. Quien desactiva los avisos de Mejora continua no las recibe en el correo.
- **Datos de demostración**: cinco fichas de ejemplo de Mejora continua, una en cada paso, un plan
  de mejora con cuatro acciones, cinco indicadores, con los valores de todo el curso anterior y la
  evaluación inicial del actual, y un programa de auditorías aprobado con una auditoría con el
  informe emitido, otra en curso y otra planificada (y la biblioteca de listas de la ISO 9001), y
  dos revisiones por la dirección: la del curso anterior, cerrada y con dos decisiones en el plan de
  mejora, y la de inicio de curso, abierta.
- **Manual**: nuevo capítulo [Mejora continua](docs/manual/09-mejora-continua.md), con sus capturas.

### Fixed

- **Iconos**: algunos iconos (entre ellos el de Mejora continua en el menú lateral) no se veían en
  producción, porque no estaban incluidos en la aplicación. Una prueba comprueba ahora que lo están
  todos.
- **Registro de avisos por correo**: los avisos de Mejora continua y los «Recordar a pendientes» de
  una actividad muestran su tipo con un nombre legible.
- **Actualizador de Ubuntu** (`update-ubuntu.sh`): borra los ficheros de la aplicación que la versión
  nueva ya no trae. Antes se quedaban en el servidor y podían impedir que arrancara (p. ej. una
  clase renombrada). Conserva `data/`, `.env.local`, la caché y los registros.

## [1.5.0] - 2026-09-24

**Al actualizar:** esta versión cambia la base de datos (papelera, acuse de lectura, cursos en las
entregas y completados, y se retira el «Archivado automático» de las carpetas). Los cambios se
aplican solos al arrancar la aplicación (Docker, binario o instalador de Ubuntu); si la ejecutas de
otra forma, lanza `php bin/console doctrine:migrations:migrate` tras actualizar.

### Added

- **Avisos por correo**: nuevo **recordatorio semanal de revisión de documentos**. El primer día
  lectivo de cada semana, quien es responsable de una carpeta recibe la lista de sus documentos con
  la fecha de próxima revisión vencida o que vence en los próximos 30 días (o los que se configuren),
  con un enlace a cada uno; si la carpeta no tiene responsables, lo reciben los responsables de
  calidad del centro. Se activa o desactiva, y se ajustan los días de preaviso, en **Ajustes → Avisos
  por correo**, a nivel global, de centro o personal.
- **Registro de avisos por correo**: cada aviso muestra su tipo con un nombre legible
  («Recordatorio de actividades», «Documento aceptado»…) en lugar de su clave interna.
- **Informes**: la sección deja de estar vacía, con tres informes que se descargan en PDF (con la
  plantilla de membrete del centro) o en Excel: el **listado maestro de documentos** (versión en
  vigor, fecha, estado, responsables y próxima revisión de cada documento), las **revisiones de
  documentos** vencidas o que vencen en los próximos 60 días y el **estado de las actividades** del
  curso (entregas esperadas, enviadas, aceptadas, en revisión y rechazadas, o docentes que han
  completado cada actividad manual). La ven, además de la administración y el equipo directivo, el
  responsable de calidad y el auditor/a interno/a.
- **Árbol documental**: cada documento puede llevar una **fecha de próxima revisión**, que pone quien
  gestiona la carpeta al editarlo. Se muestra junto al documento —en ámbar los 30 días anteriores y
  en rojo cuando ha pasado— y alimenta los informes de listado maestro y de revisiones.
- **Actividades (pestaña Ver)**: quien gestiona o revisa la carpeta de una actividad ve en su
  tarjeta el **avance global** de las entregas de este curso —una barra y una línea como
  «14/20 entregadas · 3 en revisión · 1 rechazada»—, sin tener que abrir las estadísticas.
- **Revisiones pendientes** (panel principal y campana): las entregas de una misma actividad se
  agrupan en **una sola línea** («Programación didáctica · 6 entregas por revisar»), que abre la
  actividad con todas sus entregas desplegadas y la más antigua resaltada, en lugar de una línea
  por documento que llevaba al árbol documental.
- **Árbol documental**: la carpeta de una actividad muestra por defecto solo las entregas del curso
  académico actual. Si hay entregas de otros cursos, un desplegable **«Curso:»** encima de la lista
  permite ver las de otro curso —solo aparecen los cursos que tienen alguna entrega, además del
  actual— o las de **«Todos los cursos»**. La descarga **«Descargar la carpeta (ZIP)»** incluye
  exactamente lo seleccionado y, si abarca varios cursos, pone cada uno en su propia subcarpeta.
- **Administración**: nuevo ajuste **«Inicio del curso académico»** (Administración → Ajustes,
  global y de centro), que se elige con un día y un mes: 15 de septiembre por defecto. Decide a qué
  curso pertenece cada fecha de las actividades, que se repiten cada curso. Solo admite fechas que
  existen todos los años (no el 29 de febrero).
- **Papelera**: eliminar un documento (con todas sus versiones) o una actividad (con sus
  completados) ya no los borra en el acto. Pasan a la nueva sección **Papelera**, desde donde la
  dirección y la coordinación de calidad pueden recuperarlos tal como estaban o eliminarlos
  definitivamente. Se vacía sola a los 30 días; el ajuste **«Días en la papelera»** (global y de
  centro) cambia ese plazo, y con 0 no se vacía sola.
- **Actividades**: quien revisa la carpeta de una actividad puede **aprobar o rechazar varias
  entregas a la vez**, con un comentario común, desde el recuadro «N entregas por revisar».
- **Actividades**: nuevo botón **«Recordar a pendientes»**, que envía un correo a cada docente que
  aún tiene la actividad por hacer. Lo ven la dirección, la coordinación de calidad y quien gestiona
  o revisa la carpeta; admite un envío por hora y actividad.
- **Mis actividades**: las obligaciones de un docente en una misma actividad (por ejemplo, dos
  jefaturas) se agrupan en una fila desplegable con el avance («1/2»).
- **Cursos académicos**: nueva página **«Preparar el nuevo curso»**, una lista de pasos que se marcan
  solos al hacerse: crear el curso (con el nombre ya sugerido), activarlo, añadir los docentes
  (copiándolos de un curso anterior en un clic), los días no lectivos y revisar las asignaciones de
  perfiles de quien ya no está.
- **Registro de actividad**: se puede **exportar a CSV o PDF** con los filtros aplicados.
- **Árbol documental**: **acuse de lectura**. Una carpeta puede requerir que quien la ve confirme
  que ha leído la versión en vigor de cada documento («Confirmar lectura»); cada versión nueva hay
  que volver a leerla. Quien gestiona la carpeta ve «Leído X/Y» y quién falta, el panel principal
  muestra una tarjeta «Documentos por leer» y hay un informe nuevo, **Acuses de lectura**, en PDF y
  Excel. En los datos de demostración, la carpeta «Política de Calidad y Objetivos» lo tiene activo.
- **Administración**: aviso en el panel y en el registro de actividad cuando la aplicación está
  detrás de un proxy que no figura en `SYMFONY_TRUSTED_PROXIES`, con el valor que hay que poner, o
  cuando esa variable confía en cualquier dirección.

### Changed

- **Actividades**: todas las pantallas muestran ahora el mismo estado para cada actividad, con más
  detalle: **Pendiente** (con «Vence hoy», «Vence mañana», «Vence en 3 días»…), **Rechazada**
  (hay que volver a entregarla), **Fuera de plazo** (vencida, pero aún se admite en el periodo de
  gracia), **Vencida**, **En revisión** (entregada, a la espera del visto bueno), **Completada**,
  **Próximamente** (aún no abierta) y **Cerrada** (el plazo obligatorio terminó sin completarla).
  Una entrega a la espera del visto bueno ya no aparece como pendiente ni como vencida, ni genera
  recordatorios por correo: le toca a quien revisa.
- **Panel principal**: el bloque de actividades pasa a llamarse **«Tus próximos pasos»** y muestra
  solo las cinco más urgentes que se pueden hacer ahora, con una línea de progreso, en vez de
  repetir los recuentos y la lista de «Mis actividades». Si no queda nada por hacer, avisa de la
  próxima actividad que se abrirá.
- **Mis actividades**: los recuentos pasan a ser **Por hacer** (con las vencidas destacadas),
  **En revisión**, **Hechas** y **Próximamente**; lo que aún no se ha abierto ya no cuenta como
  pendiente. El interruptor **«Solo lo que me toca»** sustituye a «Mostrar solo lo pendiente», y
  la agrupación por estado separa lo que hay que hacer, lo que está en revisión, lo que aún no se
  ha abierto, lo cerrado y lo hecho.
- **Actividades (pestaña Ver)**: una actividad que no le corresponde al docente ya no se colorea
  según su fecha (antes podía salir en rojo aunque estuviera entregada por todos).
- **Campana de notificaciones**: solo avisa de lo que el docente puede hacer ahora mismo.
- **Actividades**: el nombre de todas las descargas de una entrega empieza ahora por el curso
  académico al que corresponde, p. ej. «2026-2027 - Programación didáctica - Tutor/a.pdf», también
  dentro del ZIP de la carpeta. El propio ZIP se llama igual, precedido del curso (o del rango de
  cursos, p. ej. «2024-2025 a 2026-2027 - Programaciones.zip»).

### Removed

- **Árbol documental**: el ajuste de carpeta **«Archivado automático»**, que nunca llegó a tener
  efecto.

### Fixed

- **Seguridad**: la sesión se cierra sola tras un rato sin actividad (dos horas por defecto), para
  que un equipo compartido que se deja abierto no quede utilizable por otra persona. Se configura, o
  se desactiva con 0, en **Administración → Ajustes → Seguridad**; la pantalla de inicio de sesión
  explica por qué se ha cerrado.
- **Seguridad**: una contraseña nueva ya no puede contener el propio nombre de usuario. Además, la
  administración puede activar con `APP_PASSWORD_BREACH_CHECK=true` (desactivado por defecto) que
  tampoco pueda figurar en filtraciones de datos conocidas: consulta el servicio externo «Have I Been
  Pwned» con k-anonimato, sin enviar nunca la contraseña, y se omite en pocos segundos si el servidor
  no tiene salida a Internet.
- **Seguridad**: si a un docente se le retira el acceso a un centro con la sesión abierta, deja de
  ver sus datos en la siguiente petición, en vez de conservarlos hasta cerrar sesión.
- **Seguridad**: la importación de árboles en JSON (secciones del árbol documental, listas de
  Responsabilidades) rechaza anidamientos de más de 20 niveles.
- **Árbol documental**: «Descargar la carpeta (ZIP)» ya funciona con carpetas grandes. Antes, el
  servidor cargaba en memoria todos los ficheros de la carpeta a la vez —unas tres veces su
  tamaño—, así que una carpeta de unos 40 MB (80 MB con Docker) acababa en un error. Ahora prepara
  los ficheros de uno en uno, y la descarga también es bastante más rápida.
- **Actividades**: las actividades cuyo plazo cae en la segunda parte del curso (p. ej. de enero a
  febrero) ya no aparecen como **vencidas** entre septiembre y diciembre —ni en el panel, ni en
  «Mis actividades», ni en la campana, ni en los recordatorios por correo—. Se referían por error
  al plazo del curso anterior; ahora se muestran como «Se abre el…» con la fecha de este curso.
  Con el inicio de curso por defecto (15 de septiembre), hasta ese día las actividades siguen
  refiriéndose al curso que termina, y también pertenecen a ese curso las que caen antes de ese día
  aunque sea el mismo mes (p. ej. una del 1 al 14 de septiembre).
- **Actividades**: las entregas y los completados de una actividad cuentan ya solo para el curso en
  que se hicieron. Antes, al repetirse la actividad el curso siguiente, lo entregado o completado
  el curso anterior seguía dándola por hecha. Las entregas de cursos anteriores se conservan en la
  carpeta del árbol documental, con una etiqueta «Curso 2025-2026» (o el que corresponda) para
  distinguirlas de las del curso actual; y el calendario muestra, en los días de un curso
  anterior, si la actividad se completó en aquel curso. Al actualizar, lo existente se asigna al
  curso en que se hizo, sin ningún paso manual.
- **Seguridad**: se refuerzan las comprobaciones de permisos en la página de Actividades. Ya no es
  posible, manipulando la petición, modificar o eliminar actividades o revisiones de otro centro,
  ni marcar como completada (o deshacer) una actividad que corresponde a otro perfil.
- **Seguridad**: tras cambiar de curso, la aplicación ya solo redirige a páginas de la propia
  aplicación.
- **Seguridad**: la respuesta del servicio de autenticación de Séneca se procesa de forma más
  estricta.
- **Inicio de sesión**: el aviso «Tu cuenta está desactivada» solo aparece si la contraseña es
  correcta; con una contraseña errónea se muestra el mensaje genérico de credenciales no válidas.

## [1.4.1] - 2026-09-20

### Fixed

- **Actividades**: al descargar en ZIP la carpeta de una actividad de ámbito **individual**, cada
  entrega dentro del ZIP ya lleva el nombre de quien la subió, igual que al descargarla suelta —
  antes todas compartían el nombre del perfil y solo se distinguían por un «(2)», «(3)»… añadido
  al repetirse.

## [1.4.0] - 2026-09-20

### Changed

- **Actividades**: el fichero descargado de una entrega ahora lleva delante el nombre de la
  actividad, separado con « - » (p. ej. «Programación didáctica - Tutor/a.pdf»); si la actividad es
  de ámbito **individual**, añade también el nombre de quien la subió al final (p. ej.
  «Programación didáctica - Tutor/a - García, Ana.pdf»). Un documento del árbol sin actividad
  detrás conserva su nombre tal cual, como hasta ahora.

### Added

- **Actividades**: nuevo campo opcional **«Prefijo de entrega»** en el formulario de una actividad
  (junto a «Lista para nombrar entregas»). Si se indica, sustituye al título como lo primero que
  lleva el nombre del fichero al descargar una entrega — útil para usar una forma más corta que el
  título completo. Vacío por defecto: se sigue usando el título. Un guion («-») a solas quita el
  prefijo del todo, dejando el nombre del fichero empezar directamente por el de la entrega.

## [1.3.1] - 2026-09-20

### Fixed

- **Árbol documental**: descargar un documento cuyo nombre contiene una barra («/») —p. ej. una
  entrega de una actividad de ámbito individual, nombrada como el perfil al que corresponde, como
  «Tutor/a»— ya no da un error 500. Alcanzaba sobre todo a quien podía ver el historial de
  versiones de una entrega pendiente de revisión (responsable o revisor de la carpeta, admin.,
  responsable de calidad), ya que un docente normal no ve ese enlace para su propia entrega
  todavía sin decidir.

## [1.3.0] - 2026-09-20

### Added

- **Búsqueda**: en PostgreSQL, todas las búsquedas y filtros por texto (docentes, centros,
  documentos, carpetas, secciones, actividades, categorías, eventos, registro de actividad y de
  avisos por correo) ahora ignoran tildes: buscar «Jose» encuentra también «José». En MySQL/MariaDB
  se fuerza la collation `utf8mb4_unicode_ci` en la propia comparación, con el mismo efecto, sin
  depender de la que traiga el servidor por defecto. SQLite no se ve afectado.

## [1.2.0] - 2026-09-13

### Added

- **Administración**: nuevo ajuste **«Prefijo del asunto»** (Administración → Ajustes, global y de
  centro) que antepone un texto fijo, seguido de un espacio, al asunto de cada aviso por correo
  electrónico. Vacío por defecto: no cambia nada hasta que se configura.

## [1.1.0] - 2026-09-13

### Added

- **Responsabilidades**: en las listas, con «Seleccionar varios» activado, un nuevo botón
  **«Eliminar seleccionados»** borra de una vez todos los elementos marcados (junto con sus
  elementos hijos), tras pedir confirmación. Se bloquea, sin borrar nada, si algún elemento
  marcado o alguno de sus hijos sigue en uso (asociado a un perfil o con docentes asignados a
  través de uno).
- **Responsabilidades**: en las listas, con «Seleccionar varios» activado, un nuevo desplegable con
  el árbol completo permite **mover** todos los elementos marcados dentro de otro de una sola vez
  (o al nivel raíz), manteniendo la jerarquía que ya tuvieran entre sí. El propio elemento marcado
  y sus descendientes nunca aparecen como destino posible.

## [1.0.3] - 2026-09-13

### Fixed

- **Responsabilidades**: en las listas, eliminar un elemento con elementos hijos ya no se bloquea.
  Se elimina la rama completa (el elemento y todos sus descendientes) de una vez, salvo que él
  mismo o alguno de sus descendientes siga en uso (asociado a un perfil o con docentes asignados a
  través de uno), en cuyo caso se bloquea igual que antes.

## [1.0.2] - 2026-09-13

### Fixed

- **Búsqueda global**: la paleta de comandos (⌘K) ya no ofrece «Cambiar de curso» a quien no es
  administrador de la plataforma o del centro, que no tiene permiso para hacerlo. Al ser hoy la
  única acción del grupo, el grupo «Acciones» deja de mostrarse cuando no aplica.

### Changed

- Documentación y traducciones: se sustituye el anglicismo «compleción» por «completado», el
  término que ya usaba el glosario del manual para el mismo concepto.

## [1.0.1] - 2026-09-13

### Added

- **Actividades**: mientras nadie haya revisado todavía una entrega propia —pendiente de visto
  bueno, o ya rechazada—, quien la subió puede eliminarla sin necesidad de ningún permiso sobre la
  carpeta. Al eliminarla, la fila vuelve a mostrar la zona de arrastrar-y-soltar en su lugar,
  lista para sustituirla por otra, siempre que el plazo de la actividad siga abierto; si el plazo
  ya se cerró, la fila queda vacía y de solo lectura en su lugar. En cuanto la entrega queda
  aceptada, este permiso deja de aplicarse.

## [1.0.0] - 2026-09-11

### Added

- **Copias de seguridad**: nuevo comando de consola `app:backup` que vuelca toda la base de datos
  —incluidos los ficheros subidos, que se guardan dentro de ella— a un único ZIP en `var/backups`
  (o en la carpeta o el fichero `.zip` que se indique). El volcado es lógico e independiente del
  motor (una tabla por fichero NDJSON, valores binarios en base64), así que una copia hecha en
  SQLite se puede inspeccionar en PostgreSQL y viceversa. La cola de Messenger queda excluida.
  Opcionalmente cifra el ZIP con AES-256 pasando `--password=…`, o `--password` sin valor para que
  se pida por consola (con confirmación). Sin contraseña la copia no va cifrada; en ningún caso
  incluye el `APP_SECRET`.
- **Copias de seguridad**: comando `app:restore` que reemplaza todos los datos actuales por los de
  una copia de `app:backup`. Antes de tocar nada muestra la fecha, la versión y el contenido de la
  copia y pide confirmación (`--force` la omite); si el esquema de la copia no coincide con el de
  la base de datos se niega salvo `--force`. La carga es transaccional, con las comprobaciones de
  clave ajena suspendidas mientras dura (en PostgreSQL requiere un rol con privilegios). Acepta
  `--password` para copias cifradas.
- **Actividades**: cada actividad puede exigir que se respete su plazo. En «Plazos» del
  formulario, dos casillas independientes impiden hacer la primera entrega y marcar como
  completada (si es manual) **antes de la fecha de inicio** o **después de la fecha de fin**. Al
  activar el bloqueo por fecha de fin aparece un campo **«Extensión de plazo (días)»**: durante
  esos días tras el vencimiento las entregas y el completado siguen permitidos, pero se registran
  y se muestran **con retraso**. Los coordinadores de calidad, los responsables de la carpeta y
  los administradores pueden entregar y marcar en cualquier momento aunque esté fuera de plazo. La
  actividad muestra avisos cuando la ventana está cerrada o cuando se actúa con retraso.
- **Actividades**: cuando una actividad todavía no ha abierto su plazo, la ficha, la lista «Mis
  actividades» y el resumen del panel de inicio indican la fecha en que se abre («Se abre el …»)
  en lugar de solo la de vencimiento.
- **Actividades**: código de color de fondo por estado en el panel de inicio, la pestaña «Mis
  actividades» y la pestaña «Ver categorías» — sin color si aún no ha empezado, ámbar si está en
  plazo sin completar, rojo si el plazo ya venció sin completar, verde si está completada.

### Changed

- **Calendario**: una actividad con un rango real (fecha de inicio y de fin distintas) aparece
  como una banda que cubre todos los días de ese periodo, en la cuadrícula del mes y en el detalle
  de cada día. Una actividad de fecha única (inicio y fin coinciden) se sigue mostrando como una
  sola marca en esa fecha.
- **Calendario**: las actividades se colorean por **categoría** (mismo color = misma categoría), y
  las barras de la cuadrícula usan tonos más suaves con una franja de color a la izquierda, para
  que varias actividades apiladas en un día se lean como un grupo y no como un bloque saturado.
- **Datos de demostración**: `app:load-demo-data` añade la categoría «Seguimiento del SGC» con
  cuatro actividades de ejemplo, una por estado (sin empezar, en plazo, vencida, completada), para
  que el código de color se aprecie desde el primer momento.
- **Registro de actividad**: las acciones de las pantallas interactivas (árbol documental,
  actividades, listas, perfiles, ajustes) se registran ahora con un tipo semántico traducido
  («Carpeta creada», «Sección reordenada», «Asignación de perfil modificada»…) en vez de la clave
  técnica `component.<Componente>.<acción>`. Se auditan solo las acciones con valor para una
  auditoría; el resto (abrir cuadros de confirmación, paginar, filtrar, seleccionar filas) deja de
  registrarse. Todas las etiquetas siguen un mismo estilo y toda acción registrable tiene
  traducción.

### Fixed

- **Selección de centro**: un identificador de centro no válido en la sesión (una cookie de una
  versión anterior, una sesión truncada) devolvía un error 500 en vez de llevar a la pantalla de
  selección de centro. Ahora un valor mal formado —tanto de centro como de curso académico— se
  trata como «sin seleccionar» y redirige limpiamente.

## [0.6.1] - 2026-09-08

### Changed

- **Actividades**: al desplegar las estadísticas de una actividad que no tiene entregas previstas
  (su carpeta no define perfiles de subida), en vez de una tabla vacía se muestra un mensaje
  indicando que no hay estadísticas que mostrar.

### Fixed

- **Árbol documental**: los botones de subir y bajar un documento dentro de una carpeta no tenían
  efecto. Los documentos no recibían una posición al subirse, así que todos compartían la posición
  0 y el intercambio no cambiaba nada. Ahora cada documento nuevo se coloca al final de su carpeta,
  el listado tiene un orden estable y al reordenar se normalizan las posiciones del grupo antes de
  intercambiar, de modo que las carpetas ya existentes se corrigen solas la primera vez que se
  reordena o se ordena alfabéticamente.

## [0.6.0] - 2026-09-07

### Added

- **Registro de actividad**: nuevo registro de auditoría de seguridad en **Administración →
  Registro de actividad** (solo administradores de la plataforma). Anota, por usuario y dirección
  IP, los inicios y cierres de sesión (y las suplantaciones), los accesos de lectura (abrir una
  sección, una carpeta o un documento, descargas, exportaciones e informes) y las escrituras
  (altas, cambios y bajas de carpetas, secciones, listas, actividades, perfiles, docentes y
  centros; subida de documentos y revisiones; aceptar/rechazar; completar una actividad; cambios
  de ajustes). Se escribe después de enviar la respuesta, sin penalizar el rendimiento. Cada
  centro puede activarlo o desactivarlo con el ajuste «Registrar la actividad de los usuarios»;
  la retención se configura con «Retención del registro de actividad» (limpieza semanal) y
  `APP_LOG=false` lo desactiva por completo en el servidor. La documentación incluye cómo
  configurar los proxies de confianza (`SYMFONY_TRUSTED_PROXIES`) para registrar la IP real
  cuando la aplicación va detrás de un proxy inverso.
- **Despliegue en Ubuntu Server**: `dist/install-ubuntu.sh` ahora pregunta el modo de acceso y
  añade la opción **«detrás de un proxy inverso propio»** (nginx, Apache, HAProxy, Traefik…):
  FrankenPHP sirve HTTP plano en un puerto local, sin Let's Encrypt, y el script pide el puerto y
  la IP del proxy para fijar `SYMFONY_TRUSTED_PROXIES` y ajustar el cortafuegos. La guía de
  despliegue manual incluye la variante con ejemplos de configuración de nginx y Apache.

### Fixed

- **Árbol documental**: abrir el editor de secciones («Editar el árbol») lanzaba una consulta por
  cada sección para leer sus restricciones de perfil y otra por cada sección raíz para contar sus
  hijas — más de 50 consultas en árboles medianos. Ahora el árbol se carga entero de una vez con
  esas restricciones incluidas y los recuentos se derivan en memoria (de 52 a 9 consultas en el
  caso de prueba).

## [0.5.1] - 2026-09-06

### Added

- **Despliegue**: el manual documenta el proceso de actualización a la última versión publicada
  para cada forma de despliegue (Docker, Plesk y binario nativo en Ubuntu Server). Para Ubuntu
  Server se referencia el script `dist/update-ubuntu.sh`, incluido el comando
  `curl -fsSL … | sudo bash` que lo ejecuta directamente desde el repositorio.

## [0.5.0] - 2026-09-05

### Added

- **Árbol documental**: cada carpeta puede restringir los formatos de fichero que acepta a uno o
  varios de un conjunto fijo — documento editable, documento no editable, presentación, hoja de
  cálculo, imágenes o ficheros de texto — desde sus ajustes. Sin ninguno marcado, se acepta
  cualquier formato, como hasta ahora. Se comprueba por extensión o por tipo MIME, así que basta
  con que uno de los dos encaje; se aplica a la subida de un documento nuevo, de una nueva
  revisión y a las entregas de una actividad. Cuando hay restricción y se puede subir algo, un
  aviso «Formatos aceptados: ...» lo indica antes del listado de documentos (o de «Mis entregas»,
  en una actividad).
- **Responsabilidades → Listas**: en pantallas grandes se muestra el árbol completo del centro y
  cada elemento se reordena o se mueve a otro padre **arrastrándolo**, igual que en el árbol
  documental. Las migas de pan quedan como alternativa (sin arrastrar) para pantallas pequeñas.
- **Responsabilidades → Listas**: **Seleccionar varios** marca varios elementos con casillas (en
  cualquier rama, no solo hermanos) y les asigna a todos el mismo perfil o subperfil —o se lo
  quita a todos a la vez— en una sola acción, en vez de uno por uno.

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
- **Actividades → Ver**: navegar por las categorías no dejaba rastro en el historial del
  navegador, así que atrás/adelante no volvían a la categoría anterior. Ahora cada categoría que
  se abre actualiza la URL, igual que ya hacía el árbol documental.

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
    que una revisión), ámbito por perfil o individual, completado automático o manual (con
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
