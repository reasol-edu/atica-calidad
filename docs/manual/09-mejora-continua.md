# Mejora continua

**Mejora continua** es donde el centro registra lo que no funciona como debería y sigue qué se hace
para resolverlo: las **incidencias** que comunica cualquier docente, las **no conformidades** y sus
**acciones** (reparadoras, correctivas, preventivas y de mejora), hasta comprobar que han sido
eficaces. Aparece en el menú lateral para todo el profesorado.

La sección usa el lenguaje del centro, no el de la norma: nadie necesita saber qué es una «no
conformidad» para comunicar un problema. Los términos de la ISO 9001 aparecen solo como ayuda al
clasificar.

## Quién hace qué

| Papel | Qué hace |
| --- | --- |
| **Cualquier docente** | Comunica incidencias y sigue las que ha comunicado. Si se le asigna una acción, la hace y adjunta la evidencia. |
| **Responsable del análisis** | Analiza las causas de una no conformidad y propone las acciones correctivas. |
| **Responsable de calidad** y **equipo directivo / admin. del centro** | Clasifican lo que llega, asignan el análisis, verifican la eficacia y cierran. Ven todas las fichas. |
| **Auditor/a interno/a** | Consulta todas las fichas y el informe de no conformidades, sin modificarlas. |

Cada docente ve, además de lo que ha comunicado, las fichas en las que tiene algo que hacer: las que
analiza y las que tienen una acción a su nombre o a nombre de uno de sus perfiles.

## Comunicar una incidencia

![Formulario «Comunicar una incidencia»](img/mejora-comunicar.png)

El botón **Comunicar incidencia** está en la portada de Mejora continua y en la paleta de comandos
(**⌘K** / **Ctrl+K**, «Comunicar incidencia»). El formulario pide lo mínimo:

- **¿Qué ha pasado?** — la primera línea se convierte en el título de la ficha; conviene incluir
  cuándo y dónde.
- **¿Dónde?** — el proceso o apartado del [árbol documental](07-arbol-documental.md) al que afecta,
  si se sabe. Si no, «No lo sé / otro».
- **Fotos o documentos** (opcional) — hasta 5 ficheros de 20 MB como máximo cada uno.

Al enviarla, la ficha queda **Comunicada** y la coordinación de calidad recibe un aviso. Quien la
comunicó recibe otro cuando se descarta o se resuelve.

!!! warning "Datos del alumnado"
    No incluyas datos personales del alumnado que no sean imprescindibles para entender el problema.

## La portada

![Portada de Mejora continua, vista por el responsable de calidad](img/mejora-portada.png)

La portada reúne, en este orden:

- **Lo que toca ahora** — las tareas de Mejora continua pendientes para quien la abre (clasificar,
  analizar causas, hacer una acción, verificar la eficacia), las vencidas primero. Las mismas tareas
  aparecen en **Tus próximos pasos** del panel principal.
- **Bandeja: por clasificar** — solo para quien gestiona: lo comunicado que aún no se ha revisado.
- **Recuentos por estado** — para quien ve todas las fichas; cada uno abre el listado filtrado.
- **Lo que has comunicado** — las incidencias propias y en qué punto están.

![«Tus próximos pasos» del panel principal, con una tarea de Mejora continua](img/mejora-proximos-pasos.png)

## El ciclo de una ficha

Una **no conformidad** recorre todos los pasos: *Comunicada → Análisis de causas → En ejecución →
Pendiente de verificar → Cerrada*; si la verificación concluye que las acciones no han sido
eficaces, vuelve al análisis de causas. Una **observación** o una **oportunidad de mejora** se salta
el análisis y la verificación: *Comunicada → En ejecución → Cerrada*. Cualquier incidencia puede
**descartarse** al revisarla.

| Estado | Qué significa | A quién le toca |
| --- | --- | --- |
| **Comunicada** | Pendiente de revisar. | Coordinación de calidad |
| **Análisis de causas** | Solo no conformidades: buscar la causa raíz y proponer acciones correctivas. | Responsable del análisis |
| **En ejecución** | Hay acciones en marcha. | Responsable de cada acción |
| **Pendiente de verificar** | Solo no conformidades: todas las acciones están hechas; falta comprobar si han funcionado. | Coordinación de calidad |
| **Cerrada** | Resuelta. | — |
| **Descartada** | No requiere actuaciones del sistema de calidad (el motivo queda escrito). | — |

Cada ficha muestra arriba un recuadro **Lo siguiente** que dice qué falta y quién tiene que hacerlo.
Si un paso no se puede dar todavía, el botón aparece desactivado con el motivo al lado (por ejemplo,
«Falta escribir la causa raíz»).

### Clasificar

![Clasificar una incidencia como no conformidad](img/mejora-clasificar.png)

Quien gestiona abre la incidencia desde la bandeja y decide **qué es**:

- **No conformidad** — no se cumple un requisito (de la norma, de un procedimiento del centro o de
  un compromiso). Recibe un código `NC-AAAA-NNN`, una **gravedad** (*Menor*: un fallo puntual;
  *Mayor*: afecta de forma importante a lo que el centro ofrece o se repite de forma sistemática),
  una persona **responsable del análisis** (por defecto, la propia coordinación de calidad) y un
  **plazo** para el análisis. La persona elegida recibe un aviso.
- **Observación** (`OB-AAAA-NNN`) — todavía no es un incumplimiento, pero podría llegar a serlo.
- **Oportunidad de mejora** (`OM-AAAA-NNN`) — algo que ya funciona y podría ir mejor.

Al clasificar se puede corregir el título, y se indican el **proceso** y el **origen** (comunicación
interna, auditoría interna o externa, queja o reclamación, indicador fuera de meta o revisión por la
dirección). Las observaciones y oportunidades de mejora pasan directamente a **En ejecución**.

Si no requiere actuaciones del sistema de calidad (no depende del centro, ya está resuelta…), se
**descarta** con un motivo, que se comunica por correo a quien la envió.

### Analizar las causas

![Análisis de causas con los 5 porqués](img/mejora-analisis.png)

La persona responsable del análisis ve la tarea en su panel principal y en la portada. En la ficha:

1. **Los 5 porqués** (opcional) — preguntarse «¿por qué?» a partir de lo que ha pasado, y otra vez
   sobre cada respuesta, hasta llegar a la causa de fondo. No hace falta llegar a cinco.
2. **Causa raíz** — la causa de fondo que, si se elimina, evita que vuelva a pasar. Obligatoria.
3. **Acciones** — al menos una **correctiva** (ver [Acciones](#acciones)).

**Guardar** conserva el borrador; **Terminar el análisis** pasa la ficha a *En ejecución* y avisa a
los responsables de las acciones.

### Acciones

Cada acción tiene un **tipo**, una descripción, una persona **responsable** y un **plazo**:

| Tipo | Para qué |
| --- | --- |
| **Reparadora** | Arregla el efecto inmediato (la «corrección» de la norma). Suele registrarse ya hecha. |
| **Correctiva** | Elimina la causa para que no vuelva a ocurrir. |
| **Preventiva** | Evita un problema que todavía no ha ocurrido (un riesgo). |
| **De mejora** | Mejora algo que ya funciona. |

La responsable puede ser una persona concreta o un **perfil** de
[Responsabilidades](06-responsabilidades.md) (cualquiera que lo tenga puede hacerla). La casilla
**Ya está hecha** registra una acción terminada, con lo que se hizo, sin pasar por pendiente.

Las añade quien analiza, durante el análisis, y quien gestiona, mientras la ficha está en ejecución.
Quien es responsable de una acción la **empieza**, la **marca como hecha** contando qué se ha hecho
y adjunta la **evidencia** (fotos, actas, documentos…) con «Adjuntar evidencia».

![Ficha de una no conformidad en ejecución](img/mejora-ficha.png)

### Verificar la eficacia y cerrar

Cuando todas las acciones de una **no conformidad** están hechas, la ficha pasa sola a **Pendiente
de verificar**, con una fecha propuesta para comprobarlo (30 días después, ajustable en
[Ajustes](#ajustes)); se puede verificar antes. Quien gestiona escribe cómo lo ha comprobado y
elige:

- **Ha sido eficaz** — la ficha se cierra y se avisa a quien la comunicó.
- **No ha sido eficaz** — vuelve a *Análisis de causas*, con lo hecho en el historial. Para
  terminar el nuevo análisis hace falta al menos **una acción correctiva nueva**.

Las **observaciones** y **oportunidades de mejora** no se verifican: quien gestiona las **cierra**
cuando todas sus acciones están hechas.

### Historial, comentarios y adjuntos

Cada ficha lleva un **historial** con quién hizo cada paso y cuándo; cualquiera que ve la ficha
puede añadir un **comentario** o **adjuntar un fichero** mientras esté abierta. Los ficheros se
guardan con el mismo almacén deduplicado que el árbol documental.

## El listado de fichas

![Tablero de fichas por estado](img/mejora-tablero.png)

**Ver todas las fichas**, para quien ve todas, abre el listado con buscador (código, título o
texto) y filtros por tipo, origen, proceso, año y estado. Se puede ver como **Tabla** o como
**Tablero**, con una columna por estado. Los filtros quedan en la dirección de la página, así que
un listado filtrado se puede guardar o compartir como enlace.

El registro completo se descarga en PDF o Excel desde [Informes → No
conformidades](04-informes.md#no-conformidades).

## Ajustes

En **Ajustes**, a nivel global o de centro:

- **Mejora continua → Días hasta la verificación de eficacia** (30 por defecto): los días que se
  dejan pasar, desde que se terminan las acciones de una no conformidad, antes de proponer
  comprobar si han funcionado.
- **Avisos por correo → Avisos de Mejora continua**: los correos de «te toca» (clasificar, analizar,
  hacer una acción, verificar) y los de «tu incidencia se ha resuelto». También se puede desactivar
  a nivel personal.

## Permisos de un vistazo {#permisos-de-un-vistazo}

| Puede... | Docente | Auditor/a interno/a | Responsable de calidad | Equipo directivo / Admin. del centro | Admin. de la plataforma |
| --- | :-: | :-: | :-: | :-: | :-: |
| Comunicar incidencias y seguir las propias | ✅ | ✅ | ✅ | ✅ | ✅ |
| Analizar las causas y hacer las acciones que se le asignen | ✅ | ✅ | ✅ | ✅ | ✅ |
| Ver todas las fichas y el informe de no conformidades | — | ✅ | ✅ | ✅ | ✅ |
| Clasificar, descartar, asignar, verificar y cerrar | — | — | ✅ | ✅ | ✅ |
