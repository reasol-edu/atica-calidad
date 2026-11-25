# Changelog

Todos los cambios notables de este proyecto se documentan en este fichero.

El formato sigue [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) y el proyecto se adhiere
a [Semantic Versioning](https://semver.org/lang/es/).

## [Unreleased]

### Added

- Responsabilidades: los elementos del catálogo pueden tener **docentes asociados** (los que imparten esa materia, ese grupo…). En el panel del elemento, el campo **Docentes** (buscador por nombre o usuario entre los del curso activo) los guarda, y se **acumulan a lo largo de la rama** como las asociaciones con perfiles: el panel dice en gris lo que hereda. El árbol muestra junto a cada elemento una insignia con el nombre del docente (o cuántos son), más clara si solo son heredados; la selección de varios elementos añade, quita o sustituye docentes de golpe; el botón **«Sin docente»** deja solo las hojas que nadie imparte, y **«Quitar docentes fuera de curso (N)»** limpia a quien ya no está en el curso (que sale en rojo, como en Asignar perfiles, y no cuenta para nada).
- Actividades: con un elemento del catálogo y una entrega por perfil, la casilla **«Los docentes asociados a cada elemento también lo entregan»** (desmarcada por defecto) hace que cada docente asociado vea en «Mis actividades» la entrega de su elemento y pueda rellenarla y entregarla, también con los modelos rellenables. La jefatura del departamento sigue pudiendo, y la tarea es suya solo si el elemento no tiene docentes en el curso; las demás las ve en «Entregas que gestionas», sin pendientes ni retrasos. Bajo cada entrega se ven sus docentes (o «Sin docente asociado»), y el resumen del formulario cuenta los elementos sin docente. Con la casilla desmarcada nada cambia. Los docentes no crean ni quitan entregas ni cambian su identidad: un sustituto abre el borrador del anterior tal cual.
- Los datos de demostración incluyen los docentes de las materias de Informática y, con el nuevo comando opcional `app:load-demo-module-programs`, la actividad «Entrega de programaciones de los módulos» que los usa.

### Changed

- Avisos de aprobación o rechazo: para el documento de una actividad con elemento del catálogo, el aviso llega ahora solo a quien tiene la fila de ese documento (su perfil y subperfil de subida), a los docentes del elemento si la actividad lo pide y a quien lo entregó; antes llegaba a todos los que tenían algún perfil de subida de la carpeta (con «Jefe/a de Departamento», todas las jefaturas).
- Responsabilidades: las asociaciones de los elementos del catálogo con perfiles **se acumulan** a lo largo de la rama. Antes, un elemento con asociaciones propias usaba solo esas y tapaba las de sus padres; ahora vale lo del elemento más lo de todos los elementos de los que cuelga, sin repetir. En el panel del elemento, el selector «Asociado a» sigue mostrando solo las propias y, debajo, en gris, lo que hereda agrupado por elemento («Además, de «Formación Profesional»: …»); una propia que ya se hereda se marca en ámbar porque sobra. Ya no se puede tapar lo heredado: si un grupo lo lleva otro departamento, la asociación se pone en las hojas y no en la rama. Mover una rama pide confirmación siempre que cambie el conjunto de asociaciones que hereda.
- **Justo después de actualizar**, ejecuta `php bin/console app:list-items:inheritance-report` (el comando nace con esta versión y calcula aparte la regla anterior): es de solo lectura y lista, por centro, los elementos que ahora tienen más asociaciones que antes, aparte los que tienen una propia que queda cubierta por otra más amplia de un elemento superior (conviene reorganizarla) y, para cada actividad con elemento del catálogo, cuántas entregas pedía con la regla anterior y cuántas pide ahora. Las entregas que ya existen no se tocan.

## [2.11.1] - 2026-11-24

### Fixed

- Responsabilidades: la importación de grupos y de materias desde Séneca reconoce los elementos que se han organizado en **categorías** dentro de la raíz: los busca también en los niveles inferiores (un elemento hoja solo se empareja con otro hoja) en vez de añadirlos de nuevo y desactivar los antiguos. Lo encontrado conserva su sitio, y las categorías que lo contienen no se eliminan ni se desactivan.

## [2.11.0] - 2026-11-24

### Added

- Actividades: con un elemento del catálogo, el formulario ofrece cómo se nombra el documento de cada entrega: **Incluir la ruta completa del elemento del catálogo** («Ciencias › Física», lo que se hacía hasta ahora y por defecto) o **Solo el nombre del elemento hoja** («Física»), con dos opciones con borde que explican cada una con un ejemplo real. Con la segunda, el formulario avisa de las hojas con el mismo nombre y no deja guardar; al cambiar la opción de una actividad con entregas, estas se renombran para que sigan en su fila. El cambio queda en el historial de la actividad.

- Formulario de relleno de los modelos: las tablas cuyas filas vienen de una fuente automática (una lista del catálogo o un recuento) llevan un **filtro dinámico** en la cabecera de su primera columna; mientras se filtra, el contador dice «Mostrando X de Y filas». Sin distinguir mayúsculas ni tildes, con Esc para quitarlo, y se retira solo al ir a un problema.

### Changed

- Modelos de documento: el total al pie de una columna que es una **media** muestra siempre el número de decimales que se indique en el nuevo ajuste **Decimales de la media** (de 0 a 6; dos por defecto, o los de la propia columna si es calculada): «82,33 %» en vez de «82,333333 %», o «82,50 %». Vale en pantalla y en el PDF, Word y OpenDocument. La suma, el mínimo y el máximo siguen con sus decimales (hasta seis).
- Actividades: en el formulario, «Completado» (A mano / Al entregar) pasa justo debajo de «Obligatoriedad», y «Opciones avanzadas» conserva solo lo de ocultar la actividad.
- **Las guías de despliegue pasan a ser anexos del manual** (anexos 15 a 18: despliegue en Plesk, instalación manual en Ubuntu Server, despliegue continuo en Ubuntu Server y exposición con Cloudflare Tunnel). Antes vivían aparte, en `docs/despliegue/`, y solo se leían en GitHub; ahora salen en la web del manual, en su buscador y en el PDF, con los mismos avisos y estilo, y los capítulos de instalación y de administración de la plataforma enlazan a ellos. La carpeta `docs/despliegue/` desaparece; los comentarios de `dist/`, `compose.cloudflare.yaml` y `.env.example` apuntan a los nuevos capítulos. El anexo de Plesk deja de hablar de tareas programadas que no existen en esta aplicación («partes sin notificar») y lista las reales, y la tabla de capítulos del README incluye Mejora continua, Modelos de documento y los anexos.

## [2.10.2] - 2026-11-24

### Fixed

- Instalación nativa en Ubuntu Server (y cualquier despliegue que no cargue el `.env` del repositorio): una instalación nueva fallaba al entrar, en el cambio de contraseña obligatorio del primer acceso, con «Environment variable not found: APP_PASSWORD_BREACH_CHECK». La variable tiene ahora un valor por defecto (`false`, la comprobación de contraseñas filtradas sigue siendo opcional) y un test comprueba que toda variable que el código exige tenga un valor por defecto o la escriba el instalador.

## [2.10.1] - 2026-11-24

### Fixed

- Actividades: una entrega rellenada en línea que está en borrador no se veía de ninguna forma (la fila decía lo mismo que si nadie hubiera empezado). Ahora muestra «Borrador en curso», quién lo editó por última vez y cuándo, y «Continuar rellenándolo»; una corrección de un documento ya entregado muestra «Nueva versión en borrador».

### Changed

- Documentación: el manual (capítulo 1) y la guía de Ubuntu Server explican la instalación con un solo comando desde GitHub en Ubuntu Server 26.04, instalando antes `curl`, y cómo revisar el script antes de ejecutarlo.

## [2.10.0] - 2026-11-24

### Added

- Editor de modelos: quien puede revisar la carpeta del modelo tiene en el panel de envío el botón **Aprobar nueva revisión**, que crea la revisión ya aprobada y en vigor (sin avisar a los revisores de una revisión pendiente que ya no lo está).

- Modelos de documento: cada fila del listado enlaza con su carpeta del árbol documental (abre el documento del modelo) y con las actividades que se entregan rellenándolo (abiertas ya desplegadas); el buscador también encuentra un modelo por el nombre de su actividad.

### Changed

- Actividades › Ver: las tarjetas de una categoría salen plegadas (título, plazo, estado y avance) y se despliegan pulsando su título o «Ver detalles y entregas»; una búsqueda o un enlace a una actividad o a una entrega la despliega y se desplaza hasta ella. Una actividad con muchas entregas ya no alarga la página sin fin.
- Campana: las revisiones pendientes se separan en entregas de actividades, modelos de documento y otros documentos, y la de un modelo dice «Modelo de documento».

### Fixed

- Campana: la revisión pendiente de un modelo de documento (o de cualquier documento suelto) salía bajo «Entregas pendientes de revisar», como si fuera la entrega de una actividad.

- Formulario de relleno: los textos fijos de un modelo (y la vista previa de los textos de partida) muestran sus referencias resueltas —el centro, el elemento de la copia, el valor de un campo, que se actualiza mientras se escribe— en lugar del código `{{…|…}}`, igual que en el documento entregado y en el PDF.

## [2.9.0] - 2026-11-24

### Added

- Modelos de documento: **Mover a otra carpeta** en el menú ⋯ de cada modelo, a cualquier carpeta del centro, con todas sus revisiones; desde entonces lo revisa quien revise la carpeta de destino y las actividades lo siguen usando.
- Editor de modelos: cada bloque se puede llevar a otra sección con el desplegable «Mover a otra sección…».
- Editor de modelos: el «Resumen de la fila» ofrece **Calcular %**, que con dos columnas A y B calcula A / B × 100 (vacío si B es 0 o está vacía).
- Editor de modelos: **editor visual de fórmulas**. Los datos se ven como etiquetas de colores con su nombre (no como identificadores) y se cambian pulsándolas; el nombre de una función abre la lista de funciones con su descripción en castellano; los paréntesis se colorean por profundidad y se resalta la pareja del cursor; los errores se subrayan donde están y se explican (con arreglo cuando es obvio: «¿Querías decir sum?»); al escribir se sugieren funciones (también por su nombre en castellano) y datos, y se muestran los valores de la función en la que está el cursor. «Ver como texto» enseña la fórmula tal como se guarda.
- Modelos de documento: un campo o una columna de tipo **Número** dice cuántos decimales admite: cualquiera (como hasta ahora, y lo que tienen los modelos existentes), ninguno (entero) o hasta 1…6. Al rellenar, el valor que no cumple se marca en rojo al momento y bloquea la entrega; un entero se escribe con el teclado numérico del móvil.
- Editor de modelos: las referencias `{ }` de los títulos de sección y de los textos también se ven como etiquetas (se insertan con el botón o escribiendo `{`), y el índice y las listas de secciones muestran «Elemento de la lista» en lugar del código de la referencia.

### Fixed

- Editor de modelos: en el móvil, el resumen de cambios de la barra superior ocupa todo el ancho y el botón «Guardar como nueva revisión» va debajo (antes el texto quedaba en una columna estrecha).

## [2.8.0] - 2026-11-24

### Added

- Formulario de relleno de los modelos: índice de secciones (a la izquierda en pantalla grande, en «Secciones» en el móvil) con los pendientes de cada una, y una barra fija con el estado del guardado y los pendientes; las copias de una sección repetida se pueden plegar; las tablas de filas fijas dicen cuántas filas llevan algo y las largas mantienen la cabecera a la vista; los adjuntos muestran su número de anexo, una miniatura y el progreso de la subida.
- Modelos de documento: una sección puede repetirse una vez por elemento de una lista del catálogo, con la misma opción de quedarse solo con los elementos asociados a quien entrega, y cada copia puede llevar el nombre de su elemento en el título (referencia «Elemento de la lista»).
- Modelos de documento: las filas de una tabla de filas fijas pueden salir de una lista del catálogo (una por elemento) y, si se marca, solo las de los elementos asociados a quien entrega (todas si no está asociado a ninguno). Una fila que deja de corresponder con datos escritos se marca y bloquea la entrega hasta vaciarla; lo entregado conserva sus filas.
- Modelos de documento: nuevo tipo de campo **adjuntos**, para añadir archivos (PDF, imágenes, documentos de oficina o texto, comprobados por su contenido) como evidencias de un documento rellenado. Cada archivo es un «Anexo N» numerado: las imágenes se ven en su sitio, el PDF entregado lleva una lista de anexos y los archivos originales dentro (y, si el campo lo pide, las páginas de los PDF adjuntos al final), y el documento entregado se puede descargar en un ZIP con sus anexos. En Word y LibreOffice las imágenes adjuntas se ven debajo de su campo. Los anexos se congelan con cada entrega.
- Modelos de documento: nuevo tipo de campo **escala de valoración** (de 1 a N puntos, con palabras opcionales para los extremos), en campos y columnas de tabla. Se contesta marcando un punto en una fila de botones de opción, cuenta como número en fórmulas, totales e indicadores (que proponen la media) y en el modelo en blanco se imprimen los puntos para marcarlos a mano.
- Modelos de documento: asistente «Resumen de la fila» en las columnas calculadas de una tabla, que suma, promedia, cuenta o da el mínimo o el máximo de las columnas que se marquen de la misma fila sin escribir la fórmula.

### Security

- El autoguardado de un modelo y el de un documento rechazan, antes de leerlas, las peticiones de más de 4 MB (muy por encima de cualquier documento real); la página lo explica y conserva lo escrito en el navegador.

### Changed

- Formulario de relleno: el historial de cada campo se abre con un reloj junto a su etiqueta en lugar del enlace «Historial» bajo cada campo; las columnas numéricas de las tablas son más estrechas; en el móvil, las palabras de los extremos de una escala van debajo de la fila de puntos.
- Todos los editores de texto enriquecido (ajustes, descripción de carpeta, revisión por la dirección, textos y campos de texto largo de los modelos) pasan de Quill a Tiptap, con un único editor compartido: mismos formatos (título, negrita, cursiva, subrayado, cita, listas, alineación y enlaces, ahora con un campo de dirección propio). El texto ya guardado se conserva; las listas con viñetas escritas con Quill se convierten en listas reales al editarlas. Quill y sus dependencias salen del importmap.
- Al aprobar la revisión de un modelo de documento se genera de nuevo su PDF en blanco, de modo que `{fecha_aprobacion}` ya lleva la fecha de aprobación (mientras estaba pendiente salía vacía).

## [2.7.0] - 2026-11-23

### Added
- **Modelos de documento rellenables (fase 1).** Quien gestiona la calidad puede definir un *modelo* (Gestión › Modelos de
  documento): un documento del árbol cuyo contenido es una estructura de secciones numeradas jerárquicamente, con texto fijo,
  texto de partida (que se adapta) o a rellenar, campos con tipo (texto, número, fecha, hora, sí/no, una o varias opciones) y
  tablas de filas libres o fijas. El editor guarda el borrador solo mientras se escribe, tiene vista previa y «Enviar a
  revisión» crea una revisión nueva del documento (con su PDF en blanco) que aprueba el perfil revisor de la carpeta, como
  cualquier otro. Una actividad con carpeta puede entregarse **rellenando el modelo en línea** en lugar de subir un fichero
  (campo «Cómo se entrega» del formulario de la actividad): el borrador se guarda al escribir, avisa de lo que falta antes de
  entregar, y al entregar se genera el PDF y se crea el documento (o su revisión siguiente, si se corrige) con el flujo de
  revisión de siempre. Funciona igual de bien en el móvil. Migración nueva en las tres carpetas; probada en SQLite, PostgreSQL,
  MySQL y MariaDB. Detalle y decisiones en `skills/forms.md`.
- **Modelos de documento: secciones que se repiten, referencias y condiciones (fase 2).** Una sección puede repetirse **una vez por
  fila de una tabla** (la lista de unidades didácticas genera una sección por unidad, y cada copia sigue a su fila si se reordenan y
  desaparece con ella si se borra), según un número o a mano, con repetición anidada y con la numeración de las copias (3.1, 3.2…) a
  elección del modelo. Los títulos, etiquetas y textos admiten **referencias** (número de la copia, columnas de la fila de origen, otros
  campos, centro, curso, actividad, fecha) que se rellenan solas y siguen lo que se escribe; las secciones y bloques pueden mostrarse
  solo **si otro campo cumple una condición** (lo oculto no se pide, no se valida ni se imprime, y no se pierde lo escrito). El
  formulario muestra una barra de progreso de lo obligatorio y los avisos de «pendiente» indican en qué copia está cada dato.
- **Modelos de documento: cálculos, reglas de coherencia, relaciones y tablas de doble entrada (fase 3).** Los modelos pueden tener
  **campos y columnas calculados** con una fórmula (sumas, medias, porcentajes, condiciones…, escrita con ayuda para insertar datos y
  funciones y con aviso inmediato si no se puede leer) que se recalculan al escribir, **totales al pie** de las columnas, **columnas que
  eligen filas de otra tabla** y **tablas de doble entrada** (por ejemplo unidades × resultados de aprendizaje con el porcentaje de cada
  celda). Las **reglas de coherencia** («las horas de las unidades suman las del módulo», «cada RA suma 100 %») avisan a quien rellena o le
  impiden entregar, según las defina el modelo. La misma gramática se evalúa en el servidor y en el navegador, contrastadas con un mismo
  conjunto de casos de prueba.
- **Modelos de documento: aprobación en varios pasos, comentarios, comparación, bloqueo e historial (fase 4).** El modelo puede definir una
  **cadena de aprobación**: pasos ordenados, cada uno lo decide quien tenga su perfil; un rechazo (que exige explicar qué corregir) devuelve el
  documento y la cadena vuelve a empezar desde el primer paso con la entrega siguiente. Ninguna pantalla de revisión puede aprobar saltándose
  la cadena, y los pasos que le tocan a cada persona aparecen en su cola de revisión. Quien revisa puede **comentar partes concretas** del
  documento, ver **qué ha cambiado respecto a una versión anterior** valor a valor, y quien rellena ve **el historial de cada campo** con la
  posibilidad de restaurar un valor anterior. Mientras alguien edita un borrador, los demás lo ven en solo lectura (con aviso de quién lo tiene
  y la opción de tomar el control). Migración nueva en las tres carpetas; probada en SQLite, PostgreSQL, MySQL y MariaDB.
- **Modelos de documento: actualización a la revisión vigente, prerrelleno y borradores para todos (fase 5).** Al enviar una revisión del
  modelo se ve cuántos documentos de este curso afecta y se elige si actualizar es **opcional u obligatorio** (con fecha). Quien rellena ve un
  aviso, con la lista de lo que **no tendrá hueco** en la versión nueva, y la actualización conserva el resto; un documento obligatorio no se
  puede entregar en la versión antigua pasada la fecha. Los borradores nuevos se **prerrellenan** con lo entregado el curso anterior o con
  otra actividad (por el nombre interno de los campos), con «Volver a copiar» y «Empezar desde cero». La actividad puede pedir **el borrador
  de todos** al abrirse el plazo (`app:create-form-drafts`, para ejecutar a diario). Migración nueva probada en las cuatro bases.
- **Indicadores calculados desde documentos (fase 6, parcial).** Un indicador puede alimentarse de un campo de las entregas aprobadas de una
  actividad que se rellena en línea (suma, media, mínimo, máximo, recuento, recuento o porcentaje que cumple una condición, o cociente entre dos
  campos), con **desglose** por perfil/elemento/profesor o por una opción, y la medición dice con cuántas entregas se ha calculado. Un grupo
  por debajo del umbral permite **abrir un hallazgo propio** aunque el global cumpla. Índice de valores `FormValue` (`app:forms:reindex`) y
  cálculo periódico (`app:forms:calculate-indicators`). Migración nueva probada en las cuatro bases.
- **Explorador «Datos de modelos» en Informes (fase 6).** Los valores de las entregas aprobadas de una actividad rellenada en línea, fila a
  fila, para un curso: se pueden filtrar en pantalla y exportar a CSV o Excel. Y en el editor de modelos, un botón «Indicador con
  “campo”» por cada campo numérico, de sí/no, de opciones o calculado, que abre el formulario del indicador ya apuntando a ese campo.
- **Modelos de documento: opciones que salen de las listas de Responsabilidades.** Un campo de opciones (o una columna de tabla) puede
  tomar sus opciones de un elemento de lista —una lista entera o una rama—: son sus hojas activas, en su orden y nombradas por su ruta,
  y se **leen de la lista cada vez que se rellena** (un elemento nuevo aparece solo, uno renombrado cambia de nombre), como hacen las
  actividades. Un borrador conserva lo elegido aunque el elemento deje de ofrecerse (queda marcado y no se puede entregar hasta
  cambiarlo), y un documento entregado **congela los nombres** con que se entregó, así que renombrar o borrar después no cambia lo
  aprobado (PDF, revisión, comparación, explorador de datos y agrupación de indicadores). La biblioteca JSON exporta la lista por
  nombre y ruta y la enlaza al importar con la del mismo nombre del centro destino (si no la hay, avisa y deja el campo sin lista).
  Al borrar o mover elementos en Responsabilidades, la confirmación avisa de en cuántos documentos entregados aparecen, y la
  vista previa de la importación de Séneca también; un elemento que figura en un documento entregado se desactiva en lugar de
  eliminarse. En el formulario de un indicador, la condición sobre un campo de opciones se elige de una lista con buscador (antes
  había que teclear la clave interna). El índice de valores guarda una fila por cada opción elegida en una selección múltiple, sin límite (los datos ya indexados se corrigen con `app:forms:reindex`). Las lecturas de los modelos no repiten consultas por campo, entrega o elemento: el explorador y los indicadores leen la última revisión aprobada de todos los huecos con una sola consulta, las listas del centro se leen una vez por petición, la pantalla de modelos no consulta por modelo y la importación de Séneca no consulta los documentos por elemento.
  Migración nueva (columna de nombres congelados), probada en las cuatro bases. El manual gana las capturas del capítulo y el
  comando opcional `app:load-demo-form-models` las hace posibles.
- **Modelos de documento: tablas con tantas filas como diga un campo.** Una tabla puede tener las filas que indique un campo numérico del
  modelo (por ejemplo, una fila por cada «reunión celebrada»): quien rellena no las añade ni las quita, salen numeradas y siguen al
  número. Si el número sube se añaden filas en blanco; si baja se quitan las últimas, y la pantalla pide confirmación cuando alguna de
  ellas tiene algo escrito. Se puede fijar un máximo (hasta 100). El editor avisa si el campo no existe o no es numérico.
- **Modelos de documento: rellenado más cómodo.** Los desplegables del formulario y del editor del modelo se pueden **filtrar
  escribiendo** y tienen el chevron separado del borde; las preguntas de varias opciones se eligen en un desplegable y se muestran
  como **etiquetas**. Un número con mínimo o máximo usa los atributos `min` y `max` del campo y se marca en rojo al salirse. Los datos
  obligatorios vacíos o inválidos se marcan **en rojo, con su mensaje, en el propio campo** (al salir de él, o todos a la vez al
  pulsar un punto de «Pendiente antes de entregar»). Nuevo botón **Previsualizar**, que abre el borrador como quedará entregado.
- **Indicadores: eliminar un indicador que nada usa.** La página de un indicador sin ningún valor registrado tiene un botón **Eliminar**
  (con el cuadro de confirmación de la aplicación) que lo borra junto con su meta y su origen en documentos; se anota en el registro de
  actividad. Con valores registrados —y las incidencias y acciones que cuelgan de ellos— no se puede eliminar: la página lo explica y
  propone dejar de medirlo. Sin migración.
- **Modelos de documento: editor con pestañas y barra de envío fija.** El editor se divide en *Contenido*, *Reglas de coherencia*,
  *Pasos de aprobación* e *Indicadores*, cada una con un punto ámbar si difiere de la última revisión enviada y un número rojo con sus
  problemas. Una barra fija arriba muestra el estado de guardado, qué falta por enviar y el botón (**Enviar a revisión**, o **Guardar
  como nueva revisión** si la carpeta no exige revisión), que se desactiva sin cambios; abre un panel con los problemas (con enlace a su
  pestaña), el impacto, la política de actualización y la descripción. La pestaña activa queda en la dirección (`#rules`). La pestaña
  *Indicadores* lista los indicadores ya asociados al modelo (de cualquier actividad que lo use), con el campo y la agregación, y cada uno
  lleva a su ficha. Los botones «Indicador con una columna de tabla» mostraban el nombre de la tabla vacío. Sin migración.
- **Modelos de documento: el campo «Texto largo» admite formato enriquecido** (negrita, cursiva, subrayado, títulos, listas, citas,
  alineación y enlaces), con la misma barra que los textos a rellenar. Se guarda como HTML limpio; lo escrito antes como texto plano se
  sigue viendo (como párrafos). En comparaciones, Word/OpenDocument, el índice de valores y el explorador cuenta como el texto que dice;
  las celdas de tabla siguen siendo texto plano. Sin migración.
- **Modelos de documento: total al pie de una columna con nombre.** El total al pie de una columna (cuenta, suma, media, mínimo, máximo)
  admite un nombre opcional; con él, el valor se sigue viendo solo al pie de la tabla, pero además es un campo del documento
  (fórmulas, indicadores, reglas, explorador de datos). Nueva opción **Personalizado** con fórmula propia sobre toda la tabla. «Cuenta»
  cuenta los valores rellenados, no las filas. Sin migración.
- **Indicadores que se recalculan al aprobarse una entrega (fase 6).** Antes solo con la tarea programada o al guardar el indicador.
- **Modelos de documento: Word y OpenDocument, biblioteca JSON y duplicado (fase 7).** Un documento entregado se descarga también en
  `.docx` y `.odt`, igual que un modelo en blanco. Un modelo se exporta a JSON y se importa en otro centro (o en el mismo) como borrador
  nuevo, sin los pasos de aprobación, que nombran perfiles de un centro concreto. Se puede **duplicar** un modelo y crear un primer
  borrador a partir de un `.docx` o `.odt` (títulos → secciones, párrafos → textos fijos, tablas → tablas de filas libres).
- **Modelos de documento: modo sin conexión (fase 7).** Un formulario ya abierto antes con conexión se vuelve a abrir sin ella (un
  *service worker* guarda la página y sus ficheros), lo que se escribe se conserva en el navegador y se guarda solo al volver la conexión; si
  alguien guardó el borrador mientras tanto, vale lo del servidor. Al cerrar sesión se borran las páginas guardadas. Entregar sigue
  necesitando conexión.
- **Modelos de documento: carpeta y archivo al crear o importar.** La carpeta del árbol documental se elige con un desplegable con
  búsqueda (como en el formulario de actividad) y el archivo a importar se suelta en una zona de arrastre, como en el resto de la
  aplicación.
- **Encabezado y pie de los documentos.** Nuevo ajuste global y de centro («Encabezado y pie de los documentos») con un editor de
  texto enriquecido con tablas (celdas combinadas, líneas, fondos) y los campos `{centro}`, `{localidad}`, `{pagina}`, `{paginas}`,
  `{codigo}`, `{titulo}`, `{revision}` y `{fecha_revision}`; por defecto, una tabla como la de los documentos ODT del centro, sin logos.
  Cada modelo tiene una pestaña **Ajustes** para personalizarlos, elegir la orientación (vertical o apaisado) y el tamaño del contenido
  (deslizador del 50 % al 150 %); todo ello va dentro de la revisión del modelo. El encabezado y el pie salen en el PDF y en el
  encabezado y pie de página de los ficheros Word y LibreOffice. Nuevo botón «Vista previa en PDF» en el editor. El editor usa Tiptap
  (nueva dependencia JS, instalada en local con importmap).
- **Logotipos del centro y de la administración educativa.** Nuevo tipo de ajuste «imagen» (PNG o JPEG hasta 2 MB, comprobado por su
  contenido; sin SVG) con zona de arrastre y vista previa de la imagen elegida, y dos ajustes globales y de centro: «Logotipo del
  centro» (`{logo}`) y «Logotipo de la administración educativa» (`{logo2}`), que se pueden poner en cualquier encabezado o pie.
- **El encabezado predeterminado lleva los logotipos**, como los documentos ODT del centro: el del centro a la izquierda y el de la
  administración educativa a la derecha, con las mismas proporciones de columnas (migración que actualiza el valor predeterminado; lo
  que un centro o la plataforma ya hubieran escrito no cambia).
- **Encabezado y pie: fecha de aprobación, primera página, márgenes y vista previa.** Campo `{fecha_aprobacion}` (las revisiones
  guardan ahora cuándo se aprobaron; si no necesitan revisión es la misma fecha que `{fecha_revision}`); encabezado distinto en la
  primera página (en PDF, Word y LibreOffice); márgenes de página en los ajustes y por modelo; y vista previa con datos de ejemplo
  bajo cada editor. El PDF de vista previa de un borrador lleva la marca de agua «BORRADOR». El contenido empieza ahora debajo del
  encabezado y acaba encima del pie, midiendo su altura real.
- **Exportación a Word y LibreOffice con estilo.** Los `.docx` y `.odt` salen con el aspecto de la aplicación (títulos y reglas en verde,
  tablas con cabecera de color, filas alternas y fila de totales, listas de campos con la etiqueta resaltada, negrita, cursiva y listas del
  texto enriquecido), con repetición de la cabecera de las tablas en cada página.
- **Modelos de documento: lista preparada para muchos modelos.** «Nuevo modelo» e «Importar» pasan a ventanas que se abren desde la
  cabecera. La lista tiene buscador, filtros por estado y sección, orden (nombre, código, última modificación, estado), agrupación
  plegable por sección o estado, un acceso a los que «necesitan atención» (revisión pendiente o borrador con cambios sin enviar),
  filas compactas con Exportar y Duplicar en un menú «⋯» y «Mostrar más» a partir de 50. El orden y la agrupación se recuerdan.
- **Manual:** capítulo nuevo «Modelos de documento», «Datos de modelos» en Informes, indicadores calculados en Mejora continua y entradas
  en el glosario y en los permisos.

## [2.6.0] - 2026-11-21

### Added
- **Restaurar copias de seguridad hechas con una versión anterior.** Antes, una copia cuya migración no coincidía con la de la
  base de datos aparecía como «no se puede restaurar desde aquí» y había que instalar la versión antigua. Ahora, si la
  migración de la copia es anterior y esta versión la conoce, la restauración borra las tablas, las recrea ejecutando las
  migraciones desde cero hasta la versión de la copia (sin ejecutar ningún `down()`), carga los datos y migra hasta la última,
  de modo que las migraciones que transforman datos se aplican sobre los de la copia. Funciona desde el panel y con
  `app:restore`, también con `--force` (la reconstrucción tiene prioridad). Este camino no es transaccional, así que la **copia
  previa de los datos actuales es obligatoria** y, si la restauración falla después de reconstruir el esquema, se vuelve sola a
  los datos de antes con esa copia (si tampoco eso es posible, el resultado indica qué copia restaurar a mano). La pantalla de
  restauración avisa cuando la copia es de este tipo. Una copia de una versión **posterior** sigue rechazándose antes de tocar
  nada. Probado con una copia real de la v2.1.1 con datos de demostración en SQLite, PostgreSQL, MySQL y MariaDB, y con fallos
  forzados tras la reconstrucción (con vuelta atrás automática) en SQLite y MySQL.

### Changed
- Mientras se restaura una copia, el proceso en segundo plano no toca la base de datos: las tareas programadas (índice de
  búsqueda, OCR, recordatorios) se saltan ese turno y los demás mensajes esperan en la cola, en lugar de escribir en un
  esquema que se está reconstruyendo.
- El aviso de la pantalla de restauración sobre «si algo falla no se cambia nada» solo aparece en la restauración
  transaccional (copia de la misma versión), y el de la última restauración fallida ya no asegura que no se cambió ningún dato.
- **«Listas» de Responsabilidades pasa a llamarse «Catálogo del centro».** Sigue incluyendo las listas de elementos (grupos,
  departamentos, materias…), pero al nombrarlas en conjunto la interfaz y la documentación dicen «el catálogo»: «Elemento del
  catálogo» (en las actividades y en los perfiles), «De una lista del catálogo» (en los modelos de documento), el menú, los títulos
  y los avisos. Las direcciones y el resto de funciones no cambian.

## [2.5.1] - 2026-11-21

### Changed
- Ajustes de tipo PDF (plantillas de informes): el botón «Subir» solo aparece cuando se ha elegido o
  arrastrado un fichero al recuadro, y desaparece si se quita.
- Administración: la tarjeta de Ajustes pasa a ser siempre la última del panel.

## [2.5.0] - 2026-11-21

Incluye una migración nueva (`Version20261121100000`: la columna `document_file_text.ocr_page` y el ajuste
`search.ocr`): se aplica al actualizar. Como todos los ajustes nuevos, el OCR llega **desactivado**.

### Added
- OCR **opcional** de los documentos escaneados: con el ajuste global «Leer el texto de los documentos
  escaneados (OCR)» (Ajustes → Documentos, desactivado por defecto) y Tesseract + poppler-utils instalados, los
  PDF escaneados (o con casi texto para sus páginas) y las imágenes PNG, JPEG y TIFF entran en la búsqueda
  dentro de los documentos. Trabaja por turnos de un minuto cada dos, guardando la página por la que va, así que un
  escaneo de cientos de páginas no frena los correos ni las copias y lo ya leído se puede buscar; hasta 500 páginas
  y 200 MB por documento. Los escaneos que ya estaban subidos se leen solos al activarlo. La imagen Docker ya
  trae las herramientas (unos 90 MB); en el resto de instalaciones se instalan aparte (manual de administración) y
  sin ellas todo sigue como antes. Estado del sistema muestra si el OCR funciona y cuántos ficheros esperan;
  `app:index-documents` admite `--retry-ocr` y `--skip-ocr`. Variables opcionales `APP_OCR_TESSERACT`,
  `APP_OCR_PDFTOPPM` y `APP_OCR_PDFINFO` para indicar la ruta de las herramientas (Windows).

## [2.4.1] - 2026-11-21

### Changed
- Interno: `SectionBrowserComponent` y `ActivityBrowserComponent` (4.000 líneas entre los dos) se reparten
  en traits por responsabilidad (`src/Twig/Components/Concerns/`). Sin cambios de comportamiento.

## [2.4.0] - 2026-11-20

Incluye una migración nueva (`Version20261120100000`, el ajuste `documents.min_revision`): se aplica al
actualizar, como siempre.

### Added

- **Número mínimo de revisión de los documentos, configurable.** Un ajuste nuevo, «Número mínimo de revisión de un
  documento» (categoría Documentos), admite **0** o **1** y se fija en los ajustes globales o en los de cada centro
  (un valor global bloqueado se impone). Con 0, la primera revisión de un documento es la «v0» y la siguiente la
  «v1», como numeran la edición inicial muchos sistemas de calidad; con 1 (el valor por defecto, como hasta ahora),
  la primera es la «v1». Es la versión que se propone al subir un documento nuevo (y la de las entregas de
  actividades) y el mínimo que admiten los campos de versión; un campo en blanco ya no se toma por 0. Los documentos
  y revisiones que ya existen no cambian, y una revisión «v0» sigue siendo editable si el centro sube después el
  mínimo a 1.

## [2.3.0] - 2026-11-20

### Changed

- **Gráfica del indicador más legible.** La página de un indicador tiene ahora un eje vertical con valores
  redondos que incluye los datos, la meta y el umbral; cada punto lleva su valor escrito y se colorea según su
  estado (verde en meta, ámbar en alerta, rojo fuera de meta, además del texto en la lista de periodos); los
  nombres de los periodos quedan justo debajo de su punto, y en el móvil la gráfica se desplaza hacia los lados
  en lugar de apretarlos. Tiene un texto alternativo para lectores de pantalla.
- **Informes: todas las tarjetas terminan igual.** Los botones PDF y Excel son siempre lo único del pie, así que
  las tarjetas de una fila tienen la misma altura; «Ver en pantalla» y «Asignar perfiles» pasan a ser un enlace
  dentro de la tarjeta.
- **Administración: cada tarjeta dice su estado.** Ajustes globales indica cuántos ajustes tienen un valor
  propio y Registro de actividad, cuándo se escribió el último registro.
- **Inicio en el móvil:** los botones de «Marcar hecha» y «Subir» de las tareas muestran una palabra corta
  junto al icono, en lugar de un icono solo.
- **Demostración:** el acta de ejemplo habla de «responsable de calidad», como el resto de la aplicación.

## [2.2.1] - 2026-11-20

### Changed

- **Accesibilidad: nombres propios en botones de icono y campos.** Los botones que solo llevan un icono (descargar,
  subir y bajar, eliminar, más acciones, añadir, cerrar…) y los campos de búsqueda, de nombre, de fichero y de
  fecha tienen ahora un nombre accesible en el propio código de la página, en lugar de depender del parche de
  JavaScript, que se mantiene como red de seguridad. Los filtros de los registros de actividad y de avisos, y los
  selectores de responsables de calidad, auditores y equipo directivo, asocian además su etiqueta visible al
  campo, de modo que un lector de pantalla la anuncia al llegar a él.

## [2.2.0] - 2026-11-19

Incluye una migración nueva (`Version20261119120000`, la tabla `document_file_text` con el texto de los ficheros):
se aplica al actualizar, como siempre. Tras actualizar, el proceso de segundo plano (worker) lee poco a poco
los documentos que ya había subidos; no hay que hacer nada, y el avance se ve en Administración → Estado del
sistema. Se añade la dependencia `smalot/pdfparser` (LGPL-3.0, PHP puro) para leer el texto de los PDF.

### Added

- **Buscar dentro de los documentos.** La paleta de búsqueda (⌘K / Ctrl+K) encuentra ahora un documento por lo
  que está escrito en él: un grupo «En el contenido» muestra el fragmento donde aparecen las palabras buscadas,
  resaltadas, sin distinguir mayúsculas ni tildes. Se leen los PDF con texto, Word, Excel y PowerPoint
  (`.docx`, `.xlsx`, `.pptx`), LibreOffice (`.odt`, `.ods`, `.odp`) y ficheros de texto; no los formatos
  antiguos (`.doc`, `.xls`, `.ppt`), las imágenes ni los PDF escaneados. Solo se busca en la revisión en vigor
  y nunca sale un documento, ni un trozo de su texto, que la persona no pueda abrir. Por defecto se buscan los
  documentos del árbol y las entregas del curso actual de cada actividad; la casilla «Incluir cursos
  anteriores» añade las de cursos pasados y, para quien gestiona los documentos, las carpetas retiradas.
  También se buscan los ficheros adjuntos a fichas, acciones y auditorías que se pueden abrir. El texto se lee
  en segundo plano: Estado del sistema muestra cuántos ficheros están leídos, sin texto, dañados o pendientes,
  y permite reintentar los que fallaron; `php bin/console app:index-documents` lo hace de una vez desde la
  consola. El texto no se incluye en las copias de seguridad (se vuelve a calcular tras restaurar).
- **Fichas, acciones, indicadores y auditorías en la paleta de búsqueda.** Se encuentran por su título, código
  o descripción, y cada resultado solo sale a quien puede abrir esa pantalla.

- **«Primeros pasos» en la portada de un centro nuevo.** Quien gestiona el centro ve una tarjeta con lo que
  falta para que funcione (curso activo, profesorado, responsabilidades, árbol documental y primera
  actividad), cada paso enlazado a donde se hace; los hechos se tachan y la tarjeta desaparece cuando están
  todos. El profesorado sin gestión no la ve.
- **Ver un PDF o una imagen sin descargarlo.** Junto al icono de descarga de un documento (y en cada versión
  del historial) aparece un ojo que abre el fichero en una pestaña nueva. Solo para PDF y PNG, JPG, GIF y
  WebP: el resto de formatos, y siempre SVG y HTML, se siguen descargando. Se anota en el registro de
  actividad («Revisión consultada en el navegador»).
- **Índice de categorías en los ajustes.** Cuando hay varias categorías, un índice encima de la lista lleva
  directo a cada una.

### Changed

- **Títulos de pestaña coherentes.** Inicio, Actividades, Árbol documental, Calendario, Seleccionar centro,
  Iniciar sesión y la recuperación de contraseña acababan sin el nombre de la aplicación; ahora todos lo
  llevan, y los de dentro de un centro incluyen también su nombre.
- **Accesibilidad: etiquetas reales.** Los ajustes usan un `<label>` asociado a su control (y un grupo
  nombrado en los compuestos: día y mes, editor, plantilla PDF); las cabeceras de 8 tablas llevan
  `scope="col"` y varios campos de texto, el candado y la ayuda de marcadores tienen nombre propio. El
  parche de JavaScript sigue como red de seguridad para los botones de icono generados en muchos sitios.

- **Mejora continua: barra de secciones y migas completas.** Quien ve todas las fichas tiene ahora, en lo alto
  de todas las pantallas de Mejora continua, una barra con Resumen, Fichas, Plan de mejora, Indicadores,
  Auditorías y Revisión por la dirección, con la sección actual marcada (también dentro de una ficha, un
  indicador o una auditoría): ya no hay que volver a la portada para cambiar de sección. Sustituye a los
  cinco botones de la portada. En el móvil la barra se desplaza hacia los lados y deja visible la pestaña
  actual. Las migas de pan de las pantallas de auditoría, listas de comprobación, calendarios de medición
  y revisión por la dirección ya incluyen «Mejora continua» y la sección a la que pertenecen.

## [2.1.1] - 2026-11-15

### Fixed

- **Ajuste de copias automáticas.** Las opciones del desplegable («Básica», «Recomendada», «Ampliada») eran
  tan largas que se veían mal en el formulario de ajustes: ahora son cortas y el detalle de cada política
  está en la descripción del ajuste. En móvil, el botón «Restaurar» de la lista de copias vuelve a tener
  altura táctil y en escritorio sigue compacto.

## [2.1.0] - 2026-11-15

Incluye una migración nueva (`Version20261115100000`, los dos ajustes de las copias automáticas): se aplica
al actualizar, como siempre.

### Added

- **Estado del sistema en Administración.** Una pantalla nueva y una tarjeta en la portada que muestran si el
  worker está vivo (el planificador deja una señal cada 5 minutos; con más de 15 sin señal se avisa en rojo),
  los mensajes de Messenger que fallaron y agotaron sus reintentos (se pueden reintentar, devolviéndolos a la
  cola, o descartar; ambas acciones con POST y token CSRF y anotadas en el registro de actividad), un botón de
  correo de prueba a la dirección del propio administrador (envío directo, sin cola, con el motivo del fallo,
  3 cada 15 minutos) y el espacio que ocupan la base de datos, las copias y lo que queda libre en el disco.
  Muestra además el resultado de la última copia automática. No hay ninguna ruta de salud sin autenticar.
- **Copias de seguridad automáticas y aviso de «última copia».** Dos ajustes globales nuevos, en Ajustes
  globales → Copias de seguridad: una política de conservación (desactivadas por defecto; «Básica» 7
  diarias; «Recomendada» 7 diarias, 4 semanales y 3 mensuales; «Ampliada» 14, 8 y 12) y el espacio máximo
  que pueden ocupar las copias automáticas en MB (`0` = sin límite). Con una política activa, la aplicación
  guarda una copia cada noche (02:30) en la carpeta de copias y, al podar, deja la última de cada día,
  semana y mes que pida la política, para poder volver atrás si un problema se descubre tarde. Si se supera
  el espacio máximo se borran las más antiguas (la más reciente se conserva siempre); las copias manuales y
  las de `app:backup` nunca se borran. Los ajustes son solo globales, no de centro, porque la copia es de
  toda la plataforma. La copia se escribe como
  fichero temporal y se renombra al terminar, así que una caída no deja un archivo a medias en la lista.
  Una copia fallida no se reintenta: el motivo se anota en la pantalla de copias. La tarjeta de Administración
  y la pantalla de copias muestran cuándo se hizo la última (manual o automática) y avisan si hace más de 7
  días o no hay ninguna. Migración `Version20261115100000` (definición de los dos ajustes); revertirla borra
  los valores que se les hayan dado.

### Fixed

- **Copias de seguridad y restauración de bases de datos grandes.** Con PostgreSQL y MySQL, el volcado cargaba
  cada tabla entera en memoria antes de escribirla, y la restauración leía entero el fichero de cada tabla:
  el consumo crecía con el total de documentos (con 1 GB de ficheros, el volcado ocupaba 1,5 GB de memoria en
  PostgreSQL y se agotaba en MySQL; la restauración pedía 1,4 GB de golpe). Ahora el volcado lee por páginas de
  clave primaria (un fichero cada vez en la tabla de contenidos) y la restauración lee línea a línea: con 1 GB
  de ficheros y PHP limitado a 256 MB, copiar usa unos 50–80 MB y restaurar unos 60–70 MB, y el contenido
  es idéntico al de antes. El formato de las copias no cambia, así que las copias antiguas se siguen
  restaurando. Además, la copia sube el límite de memoria de PHP a 512 MB si era menor.
- **Accesibilidad y contraste.** Los grises de texto (ayudas, fechas, etiquetas) y el ámbar de los botones
  de aviso se oscurecen para cumplir el contraste AA de WCAG (el gris más claro, `gray-400`, lo cumple sobre
  blanco y crema, pero no sobre las etiquetas grises, donde hay que usar `gray-500`); los días de otro mes
  del calendario se leen mejor. Se añade el enlace «Saltar al contenido», y los botones de icono, los campos sin etiqueta y las
  cabeceras de tabla reciben nombre accesible y `scope`. Las acciones del árbol de secciones y de las listas
  (añadir, restringir, borrar) ya se ven en pantallas táctiles y al navegar con teclado.
- **Documentos en el móvil.** En una carpeta con confirmación de lectura, el botón «Confirmar lectura» ya no
  se monta sobre el título ni las etiquetas: pasa a su propia línea.

## [2.0.0] - 2026-11-13

Versión **mayor** porque elimina funcionalidad: el generador de calendarios y la calculadora de fechas
desaparecen, y una migración borra sus datos (irreversible). Antes de actualizar, haz una copia de
seguridad (Administración → Copias de seguridad).

### Fixed

- **Botones en el móvil.** Pasada de usabilidad por unas 350 pantallas con un móvil emulado (320–768 px),
  con administración, dirección, coordinación de calidad y docentes. Se arreglan los desbordes que hacían
  desplazarse la página hacia un lado (el árbol documental con títulos largos, el seguimiento de
  actividades, los días no lectivos, las listas de Responsabilidades, los cursos, el listado de docentes,
  la cola de revisiones, los ajustes del centro y la pantalla de copias de seguridad) y se compactan las
  filas de botones, que ahora pasan a la línea siguiente. En pantallas táctiles, los botones compactos
  miden al menos 40 px y los iconos y etiquetas pequeñas tienen una zona de pulsación mayor, sin cambiar su
  aspecto. Se añade `scripts/audit-touch-targets.mjs` para repetir la comprobación.

### Changed

- Las capturas del manual, de la presentación y de las fichas se han regenerado: el menú lateral ya no
  muestra «Utilidades».

### Removed

- **Generador de calendarios** y **calculadora de fechas**, de la sección Utilidades: esa funcionalidad
  se ha trasladado a otra aplicación. Una migración elimina las tablas de calendarios guardados y los
  dos ajustes de plantilla PDF del generador (con sus valores y los PDF subidos); **no se puede
  deshacer**. Los ajustes de plantilla de informes quedan en dos (general vertical y apaisada). Se
  quita el capítulo «Utilidades» del manual.
- La sección **Utilidades** queda vacía y **oculta** del menú (la ruta `/utilidades` sigue existiendo,
  con un aviso de que no hay herramientas) hasta que vuelva a tener contenido.

## [1.27.1] - 2026-11-13

### Security

- Se actualizan las dependencias de PHP a sus últimas versiones compatibles. `composer audit` avisaba
  de 14 paquetes con vulnerabilidades publicadas (Symfony `http-foundation`, `http-kernel`,
  `framework-bundle`, `routing`, `security-http`, `html-sanitizer`, `http-client`, `validator`,
  `config`, `filesystem`, `mime` y `yaml`, además de `phpseclib` y `phpunit`); ahora no avisa de
  ninguno. Symfony pasa de 8.1.8 a 8.1.11, y también suben Doctrine ORM / DBAL / DoctrineBundle,
  Symfony UX (Live Component, Autocomplete, Icons), PHPStan y PHPUnit. No hay cambios de comportamiento.

## [1.27.0] - 2026-11-13

### Added

- **Copias de seguridad desde el panel de administración** (**Administración → Copias de seguridad**,
  solo administradores globales). Se puede **descargar** una copia completa (con una contraseña
  opcional que la cifra con AES-256), **guardarla en el servidor** y **restaurar** una de las
  guardadas. Las copias guardadas están en `var/backups/`, o en `APP_BACKUP_DIR` (en el ejecutable
  nativo, `data/backups/`, para que sobrevivan a las actualizaciones): la misma carpeta de
  `app:backup`, así que una tarea programada con ese comando aparece en la lista. Cada copia se puede
  descargar y eliminar, y la lista avisa de las que no se pueden restaurar desde la web (cifradas, o
  hechas con otra versión del esquema). Las descargas y los guardados quedan en el registro de actividad.
- **Restaurar desde la web**, con confirmación: pantalla de revisión, contraseña de quien la pide y la
  palabra «RESTAURAR», y aviso si su usuario no figura en la copia. No se suben copias desde el
  navegador. La restauración la hace un proceso aparte: mientras dura, la aplicación responde a todo el
  mundo (administradores incluidos) con una página «Restaurando una copia de seguridad» que se
  actualiza sola, antes se guarda una copia de los datos actuales, es una sola transacción (si falla,
  no cambia nada), y al terminar se cierran todas las sesiones. Si el servidor no permite lanzar
  procesos, solo queda la consola. Documentado en el manual, con tres capturas.

### Changed

- `app:restore` pone la aplicación en «Restaurando…» mientras restaura y cierra las sesiones al
  terminar; admite `--previous-backup` (guarda antes una copia de los datos actuales), `--unlock`
  (quita un bloqueo atascado) y `--by`. `app:backup` usa `APP_BACKUP_DIR` como carpeta por defecto.
- La página de mantenimiento envía la cabecera `Retry-After`.
- `symfony/process` pasa a ser una dependencia directa. El límite de memoria de las pruebas sube a 512 MB.

## [1.26.1] - 2026-11-06

### Changed

- La publicación de ejecutables ya no compara los binarios de FrankenPHP con una suma fijada.
  Upstream vuelve a subir los de una versión ya publicada cuando actualiza sus bibliotecas, y esa
  comprobación hacía fallar la publicación sin que hubiera nada roto (así se quedó la 1.24.0 sin
  ejecutables). El proceso imprime la suma de lo que empaqueta. Para subir de versión de FrankenPHP
  basta con cambiar `FRANKENPHP_VERSION`.

## [1.26.0] - 2026-11-06

### Added

- Zona horaria de la aplicación: la variable `APP_TIMEZONE` (por defecto `Europe/Madrid`; en Canarias,
  `Atlantic/Canary`) fija la hora que ven las personas y la de «hoy», los plazos y los avisos. Hasta
  ahora la aplicación trabajaba en UTC, porque PHP ignora la zona del sistema operativo, y las horas
  salían una o dos horas desfasadas. Las fechas con hora se siguen guardando en UTC, así que no hace
  falta migrar datos. Una zona que PHP no conozca se trata como UTC. Con Docker se pasa en
  `compose.yaml`; está documentada en el capítulo de instalación y en `.env.example`.

### Changed

- Las suscripciones de calendario (`.ics`) declaran la zona horaria configurada en vez de
  `Europe/Madrid` fijo.

### Security

- Ejecutables e imagen Docker pasan a **FrankenPHP 1.13.5**, otra versión de seguridad (17
  vulnerabilidades): corrige, entre otras, la ejecución como PHP de un fichero subido con doble
  extensión, una redirección abierta, la suplantación del `Host` en HTTP/2 y un cierre del proceso
  con la configuración de PHP de las imágenes oficiales, e incorpora Caddy 2.11.9. Las sumas de los
  binarios se han vuelto a fijar: upstream había reconstruido los de la 1.13.4 tras publicarlos.

## [1.25.1] - 2026-10-27

### Security

- Ejecutables e imagen Docker pasan a **FrankenPHP 1.13.4**, una versión de seguridad. Corrige un
  fallo de la 1.13.2 y la 1.13.3 que tumbaba todo el proceso con una sola petición sin autenticar
  en modo worker, varios accesos a memoria liberada y fugas de datos entre peticiones, e incorpora
  Brotli parcheado (CVE-2026-111497) y Go 1.27.3 en los binarios de Windows y macOS. La aplicación
  no usa el modo worker por defecto, pero se recomienda actualizar.

### Changed

- El perfil que revisa y clasifica las incidencias, prepara las auditorías y recibe los avisos se
  llama ahora siempre **persona responsable de calidad** (antes, según la pantalla, «coordinación de
  calidad», «coordinadores de calidad» o «responsable de calidad»), en los avisos de la aplicación,
  la lista de verificación de auditoría y el manual.

## [1.25.0] - 2026-10-24

### Fixed

- Instalaciones con SQLite (ejecutable de demostración y de escritorio): guardar cualquiera de los
  ajustes de la aplicación fallaba con un error de clave foránea, porque el catálogo de ajustes se
  había sembrado con identificadores de texto que Doctrine no reconoce en SQLite. Una migración los
  convierte (y a lo que apuntara a ellos). PostgreSQL y MySQL / MariaDB no estaban afectados.
- La versión 1.24.0 se publicó sin ejecutables ni release: FrankenPHP había vuelto a subir sus
  binarios y las sumas de comprobación fijadas ya no coincidían. Esta versión las actualiza (y la
  imagen Docker de la 1.24.0 es correcta).

### Added

- Modo mantenimiento: un ajuste solo global (**Administración → Ajustes globales → Mantenimiento**)
  que cierra la aplicación a todo el profesorado salvo a los administradores globales. El resto
  recibe una página «Aplicación en mantenimiento» (503), la pantalla de acceso lo avisa, y los
  administradores ven una franja que les recuerda que está activado (también cuando usan «Acceder
  como»). Se bloquea todo menos el acceso y la salida; los correos automáticos y los comandos de
  consola siguen funcionando. Documentado en el capítulo de administración de la plataforma, con
  cuatro capturas.

## [1.24.0] - 2026-10-24

### Added

- Manual: 57 capturas nuevas con explicación de cada una (inicio de sesión y selección de centro,
  Centro educativo, Informes —con la primera página de cinco PDF—, Administración y ajustes, la
  suplantación de docentes y todo el capítulo de Responsabilidades), con sus scripts de captura
  (`scripts/capture-{acceso,centro,informes,plataforma,responsabilidades}-shots.mjs`) y los CSV de
  ejemplo de Séneca (`scripts/fixtures/`). Las capturas anteriores, las fichas y la presentación se
  han vuelto a generar (salían con el logotipo roto), y se incorporan al capítulo del calendario
  las que estaban sin usar. El manual documenta por fin «Acceder como» y corrige la
  regla de eliminación de elementos de lista (se elimina la rama entera).
- Datos de demostración (`app:load-demo-data`): una lista anidada «Enseñanza» con una asociación
  heredada y otra propia, un elemento inactivo, tres perfiles nuevos (con subperfiles sin docente, de
  un elemento no raíz y de asignación directa) y uno inactivo, un docente que ya no está en el curso,
  días no lectivos, fechas de próxima revisión en dos documentos, ocho avisos por correo (uno fallido) y un segundo centro para el usuario
  `multicentro`. El catálogo está en `skills/screenshots.md` y en `AGENTS.md`.

### Changed

- Responsabilidades → Listas: la pantalla principal muestra solo las listas del centro (con su
  número de elementos, formulario para crear una nueva y flechas para ordenarlas) y cada lista se
  gestiona en su propia pantalla, en vez de un único árbol largo con todos los elementos. El
  mover varios elementos a la vez ofrece ahora de forma explícita como destino los elementos de
  otras listas, y avisa de a qué elemento se han movido. Si el movimiento cambia los perfiles que
  se aplican a algún elemento (los hereda de otro padre o deja de ser subperfil de un perfil), pide
  confirmación antes de hacerlo.

## [1.23.0] - 2026-10-24

### Removed

- La zona de la parte superior de las entregas para soltar varios ficheros a la vez, que los asignaba
  al hueco con su mismo nombre: el nombre del hueco era difícil de adivinar. Cada hueco sigue teniendo
  su zona de arrastre, y se pueden preparar varios y enviarlos juntos con «Enviar entregas».

### Changed

- En una actividad con carpeta, «Mis entregas» incluye ahora solo las de los perfiles que tienes de
  verdad, y su contador cuenta solo esas. Las demás que puedes gestionar (si gestionas la carpeta,
  eres responsable de calidad o administras el centro) van aparte, en una sección plegable
  «Entregas que gestionas», con un resumen de las que faltan y de las que esperan revisión, y con
  sus zonas de arrastre. Antes se mezclaban todas en «Mis entregas».

## [1.22.3] - 2026-10-24

### Changed

- Bajo las entregas de una actividad con autocompletado ya no se listan líneas «Se completa al
  entregar», una por cada perfil y elemento: no tenían ninguna acción y, en actividades con muchas
  hojas, alargaban la página. Y en las de completado manual, quien gestiona la carpeta ve y puede
  marcar solo las obligaciones de los perfiles que tiene, no las de todos.

## [1.22.2] - 2026-10-23

### Fixed

- En las entregas de una actividad, los perfiles que entregan un mismo elemento salen ordenados
  alfabéticamente, y cuando un elemento tiene más de un perfil sus filas se agrupan con una línea
  lateral y un hueco que las separa de los demás elementos. El selector de la subida de varios
  ficheros a la vez también muestra el perfil de cada hueco, para poder elegir entre ellos.

### Changed

- Los ejecutables descargables pasan a FrankenPHP 1.13.3, que corrige el fallo de los binarios de Linux
  de la 1.13.2 (`status=4/ILL` en CPUs sin SHA-NI al calcular un `sha256`) y otros dos de la 1.13.2 con
  los workers y las extensiones de PHP.

### Removed

- El ejecutable para macOS con procesador Intel: FrankenPHP 1.13.3 solo publica binario para Apple
  Silicon. En un Mac con Intel, usa Docker. Las versiones 1.20.2 a 1.22.1 siguen con su descarga para
  Intel, pero no recibirán más actualizaciones de PHP.

## [1.22.1] - 2026-10-23

### Fixed

- En las entregas de una actividad, cuando un mismo elemento lo entregan varios perfiles (por ejemplo
  dos jefaturas de FP), cada fila indica ahora el perfil al que corresponde; antes solo se veía la
  ruta del elemento y no se distinguían. Si ningún elemento tiene más de un responsable, no se añade.
- Por la misma razón, los ficheros descargados de esas entregas (uno a uno o en el ZIP de la carpeta)
  llevan el perfil al final del nombre cuando otro perfil entrega el mismo elemento, en vez de
  llamarse igual.

## [1.22.0] - 2026-10-23

### Added

- Un elemento de una lista puede asociarse ahora a **varios** perfiles o subperfiles, y a un **elemento
  intermedio** de la lista de un perfil (por ejemplo «FP» dentro de «Departamento»), además de a una
  hoja concreta. Una hoja de la lista de una actividad se pide entonces una vez por cada subperfil que
  abarcan sus asociaciones: con «FP» asociado a «Jefe de departamento FP», cada jefatura de FP entrega
  un documento por cada hoja de esa rama.
- Un elemento sin asociaciones propias hereda las del elemento padre más cercano que las tenga, así
  que basta con asociar una rama para que lo apliquen todas sus hojas. El árbol de Listas marca los
  elementos que heredan y su panel dice de cuál.
- La asociación masiva de elementos **añade** los perfiles elegidos a los que ya tenga cada elemento, y
  hay un botón nuevo para quitar todas las asociaciones de los elementos marcados.

### Changed

- Las asociaciones de los elementos de lista pasan a guardarse en una tabla propia. Las existentes se
  conservan; no hace falta ningún paso manual al actualizar, la migración se aplica sola.

### Removed

- Las **etiquetas** de los elementos de lista y de las actividades, que ya no hacen falta: ahora una
  hoja se pide, o no, según los perfiles a los que está asociada (propios o heredados). Se eliminan
  también del formulario de actividades, de Listas y de la exportación e importación de listas (los
  ficheros exportados antes, que traen etiquetas, se siguen importando e ignoran ese campo). **Antes de
  actualizar, revisa las actividades que usaban etiquetas para elegir qué hojas se pedían:** sin ellas
  se piden todas las hojas con propietario, y para limitarlo hay que asociar (o no) cada rama a los
  perfiles que correspondan. Las etiquetas existentes se pierden al aplicar la migración.

## [1.21.0] - 2026-10-23

### Added

- Al elegir perfiles para los permisos de una carpeta, de una sección o de un evento del calendario, o
  para las actividades restringidas o con responsables, un perfil asociado a una lista permite elegir
  también un **elemento intermedio** de la lista (por ejemplo «FP» dentro de «Departamento»), que
  incluye todas las hojas que cuelgan de él. Hasta ahora solo se podía elegir el perfil entero o una
  hoja concreta (y en secciones y eventos, solo hojas). Los docentes se siguen asignando a hojas.
  Las secciones y los eventos ofrecen además la opción «(todos)» del perfil entero.

### Fixed

- Un perfil asociado a una lista elegido entero («(todos)») como perfil de revisión, de subida o
  responsable de una carpeta no llegaba a nadie en los avisos por correo (documentos pendientes de
  revisar, resultado de la revisión, auditorías), porque se buscaba a quien tuviera el perfil
  «sin hoja», que nadie tiene. Ahora llega a quien tenga cualquiera de sus hojas.

## [1.20.2] - 2026-10-23

### Fixed

- El ejecutable para macOS y Linux ya no se queda sin memoria al arrancar: el precalentamiento de la
  caché usaba el límite por defecto de PHP (128M), porque la configuración del servidor no afecta a la
  línea de comandos. El arranque (y el script de Ubuntu Server, también para el worker) genera ahora un
  `php.ini` mínimo en `data/php` con 256M, igual que el servidor.
- Los ejecutables descargables vuelven a FrankenPHP 1.13.1, que lleva el mismo PHP 8.5.12 con PHP2Shell
  corregido. Los binarios de Linux de la 1.13.2 (los de la 1.20.1) se compilaron para la CPU del servidor
  de CI y mueren con `status=4/ILL` en CPUs sin SHA-NI, habitual en máquinas virtuales, al calcular un
  `sha256`. Si te ocurre, actualiza. Se recupera además el ejecutable para Mac con procesador Intel.

## [1.20.1] - 2026-10-22

### Security

- Los ejecutables descargables pasan a FrankenPHP 1.13.2, que lleva un PHP con la vulnerabilidad
  PHP2Shell (CVE-2026-109618 y CVE-2026-109619) corregida. La imagen Docker se construye ahora siempre
  sobre la imagen base más reciente, con los parches de seguridad de PHP. Actualiza cuanto antes: el
  binario y la imagen llevan su propio PHP, y cambiar el `php.ini` no evita el fallo. En Plesk o con el
  PHP del sistema, actualiza PHP a 8.4.27 o posterior (8.5.12 o posterior en 8.5).

### Changed

- Ya no se publica ejecutable para Mac con procesador Intel, porque FrankenPHP dejó de publicarlo.
  Las versiones anteriores que lo incluían llevan el PHP vulnerable: en esos equipos, usa Docker.
- Los tests se ejecutan con PHP 8.4 y 8.5.

### Documentation

- El manual de administración explica que el binario y la imagen Docker llevan su propio PHP y cómo
  mantenerlos al día.
- Las guías para agentes indican que las migraciones se verifican en PostgreSQL, MySQL y MariaDB con
  Docker.

## [1.20.0] - 2026-10-19

### Changed

- En el generador de calendarios, mostrar las horas de cada día deja de ser un ajuste de todo el
  calendario y pasa a ser una casilla de cada periodo, que solo aparece en los periodos con horas.
  Los calendarios ya creados conservan lo que tenían; los ficheros exportados antes se siguen
  importando. No hace falta ningún paso manual al actualizar: la migración se aplica sola.

### Fixed

- El color de cada día del calendario sigue ahora este orden de prioridad, de menor a mayor:
  periodos, días no lectivos y fines de semana, y fechas individuales. En la versión anterior un día
  no lectivo tapaba incluso una fecha individual.

## [1.19.7] - 2026-10-19

### Changed

- En el generador de calendarios, los días no lectivos y los fines de semana conservan su color en la
  cuadrícula del mes aunque caigan dentro de un periodo o tengan una fecha individual.
- El resumen de jornadas de cada periodo con horas termina con la suma total de horas.

## [1.19.6] - 2026-10-15

### Fixed

- En el manual en PDF, los enlaces a títulos numerados, a anclas explícitas y a capítulos cuyo nombre
  de fichero no coincide con su título ya apuntan a su destino. Antes, cinco enlaces internos no
  llevaban a ninguna parte y dos identificadores contenían caracteres inválidos.

## [1.19.5] - 2026-10-13

### Changed

- En «Docentes del curso», el formulario para añadir un docente pasa debajo del listado.

### Fixed

- El menú de los tres puntos (categorías de actividades, actividades, carpetas y documentos) ya no
  queda cortado por la tarjeta que lo contiene: se veía incompleto en la última fila. Ahora se abre
  hacia arriba cuando no cabe debajo.

## [1.19.4] - 2026-10-13

### Changed

- Las actividades que se completan al entregar lo dicen con claridad: la etiqueta pasa de «Se completa
  sola», que se entendía como «no tengo que hacer nada», a «Se completa al entregar». En el formulario
  de la actividad, las opciones «Automática» y «Manual» pasan a ser «Al entregar» y «A mano».

## [1.19.3] - 2026-10-12

### Changed

- Se deja de pasar la dirección de orden como texto (`'ASC'`/`'DESC'`) a Doctrine, deprecado desde
  ORM 3.7: el registro del servidor dejaba de llenarse de avisos de deprecación en cada consulta
  ordenada.

## [1.19.2] - 2026-10-12

### Changed

- **Menos consultas a la base de datos en casi todas las pantallas.** Sobre los datos de
  demostración, las páginas con más consultas bajan entre un 20 % y un 37 % en conjunto, y el
  número deja de crecer con la cantidad de actividades, carpetas, documentos o eventos que se
  listan:
  - la resolución del centro en cada petición pasa de unas 8 consultas a 2–3;
  - el panel, la campana y el calendario cargan los hallazgos pendientes con una sola consulta, las
    completaciones de todas las actividades juntas y los perfiles de cada carpeta sin una consulta
    por carpeta;
  - las comprobaciones de perfil del árbol documental se resuelven con las asignaciones del docente,
    cargadas una vez, en lugar de una consulta por carpeta o sección;
  - los listados de actividades, carpetas, documentos y eventos traen ya sus perfiles y revisiones;
  - los informes de estado de actividades y la revisión por la dirección cuentan completaciones y
    hallazgos de auditoría con una consulta, no una por fila.

## [1.19.1] - 2026-10-11

### Security

- Las descargas de adjuntos envían ahora `X-Content-Type-Options: nosniff` desde la propia
  aplicación, sin depender de que el servidor web lo añada.

### Changed

- Actions de GitHub actualizadas (siguen fijadas por hash): `checkout` 7, `setup-node` 7,
  `upload-pages-artifact` 5, `deploy-pages` 5, `setup-qemu` 4, `setup-buildx` 4 y
  `metadata-action` 6.

### Documentation

- Capturas del manual, la presentación y las fichas regeneradas con la versión actual.
- `APP_EXTERNAL_ENABLED` activa por defecto: queda indicado en `.env` y `.env.example` que es
  deliberado.

## [1.19.0] - 2026-10-11

### Security

Cambios de la auditoría de seguridad:

- **Actualización con comprobación de integridad.** Cada release publica un `SHA256SUMS` con todos
  los paquetes y con `update-ubuntu.sh`. El actualizador y el instalador no instalan nada sin
  comprobar su suma, y abortan sin tocar la instalación si falta o no coincide. El script de
  actualización ya no se renueva desde la rama `main`, sino desde el asset de la última release,
  también comprobado. Las sumas viajan con la release: detectan descargas corruptas o alteradas,
  no a quien pueda publicar una release.
- La build comprueba la suma del binario de FrankenPHP (fijada en el flujo de trabajo) y todas las
  GitHub Actions se fijan por hash de commit; Dependabot propone su actualización.
- `DB_PASSWORD` es obligatoria en Docker: ya no hay contraseña por defecto («changeme»). El
  contenedor se niega a arrancar si la contraseña o `APP_SECRET` conservan el valor de ejemplo.
  **Aviso para quien actualice:** PostgreSQL solo lee esa contraseña al crear la base de datos, así
  que si instalaste sin definirla hay que cambiarla dentro de la base antes de arrancar (receta en
  el manual, «Si instalaste sin definir `DB_PASSWORD`»).
- Las respuestas ya no incluyen `X-Powered-By: PHP/x.y.z` (`expose_php = Off`).
- `phpseclib` actualizada (corrige un aviso de X25519 que la aplicación no usaba).

### Changed

- Dependencias actualizadas a sus últimas versiones para PHP 8.4: Symfony 8.1.8, Doctrine ORM 3.7,
  Twig 3.30, UX 3.5, PHPUnit 13.4 y, con cambio de versión mayor, `johngrogg/ics-parser` 3,
  `phpseclib` 4 y `symfonycasts/tailwind-bundle` 1.
- En la pantalla de inicio de sesión, con pantallas pequeñas, el número de versión aparece junto al
  nombre de la aplicación (el panel de marca que lo mostraba solo se ve en pantallas grandes).

## [1.18.0] - 2026-10-11

### Added

- En la Raíz del árbol documental, dos accesos rápidos: «Pendientes de leer» (los acuses de lectura
  que faltan por confirmar) y «Actualizados recientemente» (los cinco documentos con la versión
  aprobada más nueva que puedes abrir).
- Las tarjetas de Centro educativo, Responsabilidades y Utilidades dicen cuánto hay dentro (cursos,
  docentes, días no lectivos, listas, perfiles, calendarios…).

### Changed

- La paleta de comandos (⌘K) muestra primero las actividades —las que tienes pendientes de hacer,
  antes que las demás—, luego sus categorías y los documentos, y al final carpetas, secciones y
  docentes.
- Seguimiento y Edición en bloque conservan la barra de pestañas de Actividades.
- Los buscadores del árbol documental y de las actividades tienen nombre accesible y un texto más
  claro de su alcance; el botón de borrar la búsqueda también.

## [1.17.1] - 2026-10-11

### Fixed

- El arranque del ejecutable autónomo fallaba al precalentar la caché con «Allowed memory size of
  134217728 bytes exhausted»: el PHP de línea de comandos del binario no trae `php.ini` y usa 128 MB,
  y el precalentamiento necesita unos 160 MB. `bin/console` sube ahora el límite a 512 MB (nunca
  lo baja si ya es mayor), así que también vale para instalaciones cuyo script de arranque se generó
  antes.
- El script de actualización (`update-ubuntu.sh`) se renueva solo: antes de actualizar descarga la
  versión publicada de sí mismo y, si difiere, se sustituye y se relanza. La copia que usa el
  temporizador de systemd se descargaba una sola vez, y una copia anterior al borrado de ficheros
  huérfanos dejaba en el servidor las clases eliminadas por una versión (el despliegue de la 1.17.0
  falló por `UploadSizeGuardTrait.php`).

## [1.17.0] - 2026-10-11

### Added

- Dos ajustes globales, «Tamaño máximo por fichero» (20 MB por defecto) y «Tamaño máximo total por
  envío» (100 MB), en Administración → Ajustes → «Subida de ficheros». Los avisos de los
  formularios muestran siempre el máximo real, que es el menor entre el ajuste y el de PHP.
- El menú lateral agrupa bajo «Gestión» Informes, Responsabilidades, Papelera y Centro educativo.
- Aviso en el perfil cuando no hay correo: sin él no llegan los avisos ni se puede recuperar la contraseña.

### Changed

- «Comunicar incidencia» usa la zona de arrastre de ficheros, con la lista de lo elegido y su tamaño.
- En Informes, «Revisiones de documentos» no ofrece PDF ni Excel mientras no haya ninguna pendiente,
  y «Asignar perfiles» ya no usa el mismo icono que «Ver».
- Los enlaces vacíos de la cuadrícula del calendario se ocultan a los lectores de pantalla.
- Utilidades se ve para todo el centro; antes solo la veía quien podía abrir Responsabilidades.

### Fixed

- Una subida de ficheros mayores que los límites de PHP (2 MB por fichero y 8 MB por envío si el
  servidor no trae `php.ini`) fallaba sin avisar. Ahora el ejecutable para Linux y macOS configura
  PHP desde el `Caddyfile`, los límites de Docker y Windows cubren los de la aplicación, el
  formulario avisa antes de enviar y, si el envío llega demasiado grande, se vuelve a la página con
  un mensaje claro. Los ficheros que PHP rechaza ya no se descartan en silencio (adjuntos de
  auditorías y de Mejora continua) ni se confunden con «ningún fichero» (importaciones).

## [1.16.0] - 2026-10-11

### Added

- En «Mis actividades», las tarjetas de recuento (Por hacer, En revisión, Hechas, Próximamente)
  filtran la lista al pulsarlas; al volver a pulsar la misma se vuelve a ver todo.
- Al marcar una actividad como hecha desde el inicio, la tarjeta «Tus próximos pasos» muestra una
  línea con «Deshacer», para recuperar la fila si se pulsó sin querer.
- «Mis actividades» ofrece en cada fila los mismos atajos que el inicio: «Marcar hecha» en las
  actividades manuales (con su «Deshacer») y «Subir» o «Nueva versión» cuando solo falta un fichero.
  Tras subirlo se vuelve a «Mis actividades».

### Changed

- Calendario: una actividad abierta durante semanas se dibuja como una pista fina en las semanas
  anteriores a su vencimiento, y como barra con el título («Vence: …») solo en la semana en que
  vence, en lugar de repetir la misma barra en cada fila. Una actividad vencida y sin completar se
  pinta en rojo.
- Calendario en el móvil: en lugar de la cuadrícula de cinco columnas, que no deja leer los
  títulos, se muestra una lista de los días del mes que tienen algo, con sus entradas; una
  actividad abierta durante semanas aparece solo el día en que vence.
- El número de la campana cuenta solo lo urgente (actividades vencidas, fuera de plazo, rechazadas o
  con vencimiento en una semana, tareas de Mejora continua vencidas o próximas, y las entregas que
  esperan tu revisión) y por eso puede llegar a cero; con pendientes pero nada urgente muestra un
  punto gris. El desplegable sigue listando todo, resume «N urgentes · M en total» y termina con
  «Ver todas mis actividades».
- La ficha de una actividad dice con palabras dónde está para quien la tiene asignada («Vencida ·
  Venció el 30/09/2026», «Pendiente · Vence en 3 días»…), en lugar de solo teñir la tarjeta de color.
- En la ficha de una actividad, «Automática» y «Manual» pasan a «Se completa sola» y «Se marca a
  mano», y al pasar el ratón explican cómo se da por completada.
- Las cabeceras de las páginas principales (Mejora continua, Informes, Utilidades y Papelera) ya no
  repiten el título en una ruta de navegación, así que el título queda a la misma altura que en
  Inicio, Actividades, Calendario y el resto. En «Mi perfil» y «Ajustes» se quita el «Inicio»
  repetido de la ruta.
- Los nombres de carpeta y de categoría de las tarjetas del árbol documental y de «Actividades › Ver»
  ocupan hasta dos líneas antes de cortarse y muestran el nombre completo al pasar el ratón.
- La cola de revisión se abre en «Todas las del centro» cuando quien la mira puede verlas todas, no
  tiene ninguna revisión propia y sí hay pendientes en el centro. Si vuelve a «Las mías», se le dice
  cuántas hay en el centro, con un botón para verlas.
- En móvil, los títulos de las actividades y tareas (inicio, «Mis actividades», campana y Mejora
  continua) ocupan hasta dos líneas en lugar de cortarse, y la fecha de vencimiento ya no queda
  oculta.
- En móvil, los botones que se pulsaban con dificultad (marcar hecha, subir, menú, búsqueda, campana,
  mes anterior/siguiente y «Hoy» del calendario, filtros de «Mis actividades» y enlaces del pie del
  menú) miden al menos 44 píxeles. En escritorio no cambian.

## [1.15.1] - 2026-10-10

### Changed

- El inicio muestra una frase al azar de entre 15 (antes 5), todas en la lista de traducciones del
  panel (`home_subtitle_1` a `home_subtitle_15`).
- El resaltado de un documento al llegar desde la búsqueda late como un corazón durante 5 segundos
  (tres latidos dobles con el verde del tema al 30 %) y termina con una transición a un tinte más
  suave (15 %) que se mantiene.

## [1.15.0] - 2026-10-10

### Fixed

- Al llegar a un documento desde la búsqueda global o la del árbol, la fila no se resaltaba en la
  versión publicada: el minificado del CSS descartaba el último paso de la animación de resaltado
  (usaba `color-mix()` dentro del `@keyframes`) y la fila se quedaba transparente. Ahora usa un
  color fijo y el resaltado se ve.
- En el árbol documental, al saltar a un documento desde la búsqueda la URL conservaba el
  parámetro `highlight` de una llegada anterior, de modo que al recargar o volver atrás se intentaba
  resaltar un documento que no estaba en esa carpeta y no se resaltaba ninguno. Ahora la URL lleva
  siempre el resaltado vigente.
- En el PDF del listado maestro de documentos, todas las tablas (una por sección) tienen ahora las
  mismas anchuras de columna, en vez de ajustarse cada una a su contenido.

### Added

- Nuevo informe «Perfiles y docentes asignados» (PDF y Excel): todos los perfiles y subperfiles del
  centro con los docentes asignados a cada uno. Su tarjeta incluye un botón «Asignar perfiles» que
  lleva a Responsabilidades → Asignar perfiles.

### Changed

- En la pestaña «Docentes» de «Asignar perfiles», los perfiles de cada docente se listan por orden
  alfabético de perfil y, a igualdad, de subperfil.
- En la pestaña «Perfiles» de «Asignar perfiles», los docentes de cada perfil se listan por orden
  alfabético de apellidos y, a igualdad, de nombre.

## [1.14.3] - 2026-10-10

### Fixed

- En «Mis actividades», con la ventana estrecha el cuadro de búsqueda se quedaba tan pequeño que no
  se podía usar. Ahora conserva un ancho mínimo y los controles de agrupar y filtrar pasan a otra
  línea cuando no caben.

## [1.14.2] - 2026-10-10

### Added

- En la pestaña «Docentes» de «Asignar perfiles» se pueden marcar varios docentes (o todos los que
  coinciden con el filtro) y asignarles de una vez un perfil o subperfil.

### Changed

- En la pestaña «Docentes» de «Asignar perfiles», el botón «Mostrar todos» pasa a llamarse
  «Mostrar inactivos».

## [1.14.1] - 2026-10-10

### Fixed

- La base de datos y el modelo de la aplicación vuelven a coincidir en los valores por defecto del
  generador de calendarios (orientación, tamaño de letra, cabecera, pie y horas) y de «A todo el
  profesorado» en las actividades. No cambia nada a la vista y no requiere migraciones; solo evita que
  la comprobación de esquema (`doctrine:schema:validate`) marque diferencias.

## [1.14.0] - 2026-10-10

### Added

- **Historial de cambios de una actividad**: desde su menú ⋮, quién la creó, duplicó o modificó y
  cuándo, con cada ajuste que cambió y su valor anterior y nuevo.
- **Edición en bloque de actividades**: mover varias a otra categoría, ocultarlas o mostrarlas,
  cambiar su plazo general o marcarlas como obligatorias u opcionales, con una vista previa de lo que
  cambiaría en cada una antes de aplicar. Cada cambio queda en el historial. Requiere ejecutar las
  migraciones.

## [1.13.0] - 2026-10-10

### Added

- **Motivos de rechazo propios de cada centro** (Centro educativo → Motivos de rechazo): la lista de
  respuestas hechas que ofrece la cola de revisión. Sin lista propia se usan los motivos estándar.
  Requiere ejecutar las migraciones.

### Fixed

- «Plazos por elemento» en el formulario de actividad solo lista los elementos que la actividad pide
  según sus etiquetas, y se actualiza al cambiarlas.

### Changed

- Menos consultas al abrir el formulario de actividad con etiquetas (la lista de etiquetas se lee una
  sola vez).

## [1.12.1] - 2026-10-10

### Added

- **Suscripción al calendario**: cada docente puede suscribir Google Calendar, Apple Calendar o el
  calendario del móvil a sus plazos (actividades pendientes y tareas de Mejora continua con fecha
  límite), desde el botón «Suscribirme a mi calendario» del Calendario. La dirección es
  secreta, se puede renovar o desactivar, y hay una ficha rápida con los pasos para cada
  aplicación. Requiere ejecutar las migraciones.

## [1.12.0] - 2026-10-10

### Added

- **Cola de revisión** (`/revisiones`): las entregas pendientes de visto bueno ordenadas por
  urgencia (plazo cercano, luego las que más llevan esperando), con aprobación en un clic y rechazo
  con motivo predefinido más texto libre. Quien gestiona calidad puede ver todas las del centro.
- **Informe de cumplimiento de actividades** por curso, con comparación entre cursos, en pantalla,
  PDF y Excel.
- **Subir desde el Inicio**: en «Tus próximos pasos», un botón «Subir» (o «Nueva versión» si la
  entrega fue rechazada) entrega el fichero sin abrir la actividad cuando solo hay un hueco por
  rellenar.
- **Subida de varios ficheros** en la ficha de la actividad: se asignan a cada hueco por el nombre
  del fichero y el resto se coloca a mano.
- Una **entrega rechazada** muestra quién la rechazó y el motivo, con el formulario «Subir nueva
  versión» en su propia fila. La persona que subió la versión rechazada puede reenviarla aunque no
  gestione la carpeta.

### Changed

- Los enlaces del Inicio, la campana, «Mis actividades», los correos de aviso y la vista de día del
  calendario llevan directamente al hueco de la entrega pendiente, lo resaltan y lo enfocan.

## [1.11.0] - 2026-10-09

### Added

- **Seguimiento de actividades**: una pestaña «Seguimiento» en Actividades con una fila por
  actividad (plazo y días que quedan, progreso, pendientes) y, por actividad, quién falta, cuándo
  se le avisó por última vez y el historial de recordatorios. «Recordar a pendientes» permite ahora
  elegir a quién avisar, añadir un mensaje propio y dejar registro (a quién, cuándo, quién y con
  qué texto, y si el correo salió).
- **Revisión guiada de las actividades** como sexto paso de «Preparar el nuevo curso»: cada
  actividad con su plazo, a quién se le pide y cuántos docentes del curso tienen cada perfil, con
  aviso cuando un perfil se ha quedado sin nadie.
- En el **panel de inicio**, «Tus próximos pasos» es una sola lista que mezcla actividades y tareas
  de Mejora continua (vencidas, esta semana, más adelante) y permite **marcar hecha** una actividad
  manual sin abrirla.
- **Duplicar una actividad** desde su menú (la copia queda oculta y sin carpeta).
- En el formulario de actividad: **perfiles de la carpeta** (subida, responsables, revisión,
  visibilidad) visibles y editables al elegir carpeta, **vista previa en vivo del nombre** con el
  que se descargará una entrega, **resumen** de lo que se pedirá, elección inicial entre
  recordatorio manual y entrega de documentos, secciones (Qué es, Cuándo, Qué se pide, A quién,
  opciones avanzadas), botones para aplicar el plazo general a todos los elementos, salto al primer
  error al guardar y aviso al descartar cambios en los perfiles de una carpeta.

### Changed

- El selector de elementos de la lista del formulario sigue el orden del árbol.
- Las etiquetas del formulario de actividad van justo encima de «Plazos por elemento».
- Menos consultas a la base de datos en inicio, actividades, calendario y seguimiento (el detalle
  de seguimiento pasa de 324 a 37).

## [1.10.4] - 2026-10-09

### Changed

- **Menos consultas a la base de datos** en las pantallas más pesadas: el índice de «Informes»
  pasa de unas 194 consultas a 36, el listado maestro (PDF y Excel) de 87 a 7, las pantallas de
  plan, fichas y comunicar de Mejora continua de unas 35 a menos de 10, la vista «Ver» de una
  categoría de actividades de 83 a 28 y el calendario de 92 a 56.
- En el **panel de inicio**, cada bloque de «Tus próximos pasos» (actividades y Mejora continua)
  muestra en su cabecera cuántas tareas tiene vencidas.
- **Mejora continua** usa el mismo ancho de contenido que el resto de secciones en su portada, el
  plan de mejora y el programa de auditorías.
- «Mis actividades» rotula con «Agrupar por» los botones de agrupación, y los nombres truncados de
  las barras laterales de categorías y del árbol documental muestran el nombre completo al pasar el
  ratón.
- **Móvil**: los filtros de las fichas se reparten en dos columnas, las pestañas de «Actividades»
  caben en una línea y los botones de la lista de docentes ya no se parten.

## [1.10.3] - 2026-10-05

### Added

- Una **actividad** se puede **ocultar** desde su formulario («Ocultar la actividad»). Solo la ven
  quienes pueden editar actividades, con un fondo rayado y la etiqueta «Oculta» para distinguirla
  de un vistazo; para el resto no existe ni cuenta en el panel de inicio, «Mis actividades», la
  campana, los recordatorios, el calendario, las revisiones pendientes ni los informes. Sirve para
  preparar una actividad antes de publicarla o para retirarla sin borrarla.

## [1.10.2] - 2026-10-04

### Added

- La **importación de docentes desde Séneca** muestra ahora una vista previa antes de aplicar nada:
  una casilla por docente (todos marcados, con «Seleccionar todo» y «Seleccionar nada») y lo que se
  haría con cada uno. Si el fichero trae la columna «Cuenta Google/Microsoft», se puede importar como
  correo del docente, solo cuando no tiene ninguno y nadie más usa esa dirección. También se puede
  retirar del curso a los docentes que no figuran en el listado, salvo a quien tenga alguna
  responsabilidad vigente (administración, calidad, auditoría interna, perfiles asignados, o
  acciones de mejora, indicadores, fichas o auditorías abiertos a su cargo).

## [1.10.1] - 2026-10-02

### Added

- Al crear o editar una **actividad** se puede crear una carpeta nueva desde el propio formulario
  («Crear carpeta nueva», junto a «Carpeta asociada»): basta con su nombre y la sección del árbol
  documental donde colocarla, y queda asociada a la actividad. El resto de su configuración se
  sigue ajustando desde el árbol documental.
- El informe **Estado de las actividades** tiene una nueva columna «Vencidas»: las entregas sin
  enviar (o rechazadas) cuyo plazo ya ha pasado, contando para cada elemento con su propio plazo.
- Una actividad puede dejar vacío el **plazo general** si todos los elementos de su lista tienen
  un plazo propio; en ese caso se toma como general el tramo que abarcan.

### Changed

- La cabecera de una actividad indica cuántos de sus elementos tienen plazo propio; si los tienen
  todos, muestra el tramo que abarcan en lugar del plazo general.

### Fixed

- La barra de progreso de una actividad y el informe de su estado contaban como no entregado un
  elemento con plazo propio que cayera en otro curso académico que el plazo general.

## [1.10.0] - 2026-10-02

### Added

- Una **actividad** con elemento de lista asociado puede tener ahora un plazo propio para alguno
  de sus elementos, distinto del plazo general — por ejemplo, si un departamento necesita más
  tiempo que el resto para entregar. Se configura en «Plazos por elemento», dentro del formulario
  de la actividad; a partir de ahí, el plazo, el estado y el marcado de completada de cada
  elemento son independientes entre sí, aunque compartan el mismo perfil de subida.
- La pestaña **«Ver»** de Actividades tiene ahora un índice de categorías en una barra lateral,
  igual que el árbol documental: lista todas las categorías y subcategorías del centro para saltar
  directamente a cualquiera de ellas.

### Changed

- La pestaña **«Ver»** de Actividades muestra ahora todas las categorías y actividades por
  defecto; el botón «Solo mis perfiles» es el que restringe la vista a lo propio (antes era al
  revés).
- Se renombra «Solo lo que me toca» a «Lo que tengo que hacer ahora», en «Mis actividades».

### Fixed

- En una actividad manual, quien tenga uno de sus perfiles responsables ya puede marcar o
  desmarcar la completada de otro docente, igual que ya podía ver sus estadísticas (antes solo
  podían responsable de calidad y admin.).
- El resaltado de un documento o entrega al abrirlo desde el buscador (⌘K o «Buscar en todo el
  árbol») es ahora más visible (45% de opacidad y una barra de acento) y permanece mientras ese
  siga siendo el documento abierto, en vez de desvanecerse a los pocos segundos.

## [1.9.1] - 2026-10-02

### Added

- Una **actividad** sin carpeta asociada muestra ahora, a quien la gestiona, estadísticas de qué
  docentes la tienen pendiente y cuáles ya la han completado. Un nuevo campo opcional «Perfiles
  responsables» permite delegar esa visibilidad en perfiles concretos, además de responsable de
  calidad y equipo directivo/admin., que además pueden marcar o desmarcar la completada de
  cualquier otro docente directamente desde ahí.

### Fixed

- En una actividad manual restringida a ciertos perfiles, el botón de completar ya no se ofrece a
  quien no le corresponde (antes solo se bloqueaba al confirmar).

## [1.9.0] - 2026-10-02

### Added

- Una **actividad** sin carpeta asociada puede restringirse a uno o varios perfiles o
  subperfiles: deja de ser pendiente —y de poder completarse— para quien no tenga ninguno de
  ellos, aunque el resto del profesorado la siga viendo. Se eligen con un desplegable con
  buscador, igual que al restringir un evento del calendario.

### Fixed

- En los desplegables de perfiles/subperfiles con buscador (actividades, eventos del calendario,
  permisos de carpeta), la opción «(todos)» de un perfil con subperfiles ya no desaparece al
  escribir para filtrar.

## [1.8.2] - 2026-09-28

### Added

- La **calculadora de fechas** muestra ahora un calendario mensual con las horas de cada jornada, el
  total semanal y, al final de cada mes, las jornadas y horas totales. El listado de jornadas se
  puede exportar a Excel con columnas extra, separación por meses y separación visual de semanas, y
  con las fórmulas de suma y recuento ya calculadas.

### Fixed

- En el PDF del generador de calendarios, el resumen de jornadas de un periodo aparece ahora debajo
  del calendario en lugar de encima.

## [1.8.1] - 2026-09-28

### Added

- **Calculadora de fechas**, nueva herramienta en Utilidades: calcula las jornadas y horas lectivas
  entre dos fechas, o la fecha que completa un número de horas desde un extremo conocido — el mismo
  cálculo que ya hacían los periodos del generador de calendarios. El resultado se puede exportar a
  Excel.

### Changed

- Las exportaciones a PDF (informes, auditorías, revisión por la dirección, registro de actividad y
  el generador de calendarios) usan ahora Source Sans Pro como tipo de letra.

## [1.8.0] - 2026-09-27

**Al actualizar:** esta versión cambia la base de datos (generador de calendarios). Los cambios se
aplican solos al arrancar la aplicación (Docker, binario o instalador de Ubuntu); si la ejecutas de
otra forma, lanza `php bin/console doctrine:migrations:migrate` tras actualizar.

### Added

- **Utilidades**, nueva sección del menú lateral con herramientas personales ajenas al sistema de
  gestión de la calidad — hoy, el **generador de calendarios**: cada docente puede crear tantos
  calendarios como quiera para un curso académico del centro, con periodos y fechas destacadas de
  su elección (color y descripción propios), y descargarlos en PDF con dos meses por fila. Un
  periodo se define con una fecha de inicio y una de fin, o con uno de esos extremos más un total
  de horas y las horas de cada día de la semana (lunes a viernes) — el PDF marca solo los días
  laborables con horas asignadas, saltando fines de semana y días no lectivos del curso, y muestra
  un resumen de jornadas y horas cuando se pide. Los días no lectivos y los fines de semana se
  resaltan con un color propio (redefinible por calendario), y cada mes —en su propio recuadro,
  todos con las mismas columnas— lleva a su lado, en un recuadro propio, los días destacados de ese
  mes. El PDF puede generarse en formato vertical (más compacto) o apaisado, y la letra se puede
  agrandar o reducir entre el 50% y el 200% del tamaño por defecto de cada formato. Cada calendario
  es privado: solo lo ve y edita quien lo creó.
- **Generador de calendarios**: se puede duplicar un calendario existente, y exportarlo o importarlo
  como un fichero JSON — útil para reutilizarlo en otro curso académico o compartirlo con otro
  docente. La descripción admite alinear cada párrafo (izquierda, centro, derecha o justificado), y
  el formulario de edición incluye un botón para guardar los cambios y generar el PDF en un solo paso.

## [1.7.3] - 2026-09-25

### Fixed

- **Índice del árbol documental**: no se podía hacer scroll para ver ni seleccionar las secciones
  que no cabían en la pantalla; ahora el índice se desplaza verticalmente como el resto de la
  aplicación.

## [1.7.2] - 2026-09-25

### Fixed

- **Índice del árbol documental**: el botón para ocultarlo o mostrarlo estaba descentrado respecto
  al buscador de al lado; ahora queda alineado con él. El buscador de una sección ("Buscar en esta
  sección") también se ensancha un poco, para que quepa el texto completo.

## [1.7.1] - 2026-09-25

### Added

- **Índice del árbol documental**: se ensancha o se estrecha arrastrando el borde con el
  contenido, y el botón sobre ese borde lo oculta del todo (o lo recupera) sin perder el ancho
  elegido — la aplicación recuerda ambas cosas para la próxima vez, en ese navegador. Por defecto
  es algo más ancho que antes.
- **Desplegables de proceso, responsable y auditor/a jefe/a en Mejora continua** (indicador, acción
  del plan de mejora, clasificar una ficha, análisis de causas, auditoría): ahora se puede buscar
  escribiendo, con todos los valores ya cargados — sin pedir nada al servidor mientras se escribe.

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
