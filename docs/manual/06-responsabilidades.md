# Responsabilidades

Este capítulo es para el responsable de calidad y el equipo directivo. Además de los dos perfiles
fijos del centro —**responsable de calidad** y **auditor/a interno/a**, descritos en
[Preparar el curso académico](02-preparar-el-curso-academico.md#perfiles)—, cada centro puede
necesitar responsabilidades propias: tutorías, jefaturas de familia profesional, coordinaciones...
Eso es lo que gestiona la sección **Responsabilidades**, con tres herramientas:

- **Listas** — jerarquías propias de nombres (grupos, departamentos...) que sirven de base para
  construir esas responsabilidades sin repetir trabajo.
- **Perfiles específicos** — las responsabilidades personalizadas en sí, opcionalmente construidas
  sobre una lista.
- **Asignar perfiles** — una vista de trabajo transversal sobre las asignaciones ya hechas, por
  perfil o por docente (ver [Asignar perfiles](#asignar-perfiles) más abajo).

## Acceso

**Responsabilidades** aparece en el menú lateral, entre **Centro educativo** y **Administración**,
para quien tenga alguno de estos papeles en el centro activo: **responsable de calidad**, **equipo
directivo / administración del centro** o **administración de la plataforma**. El **auditor/a
interno/a** y el resto del profesorado no la ven — a diferencia de los perfiles fijos (ver
[Permisos de un vistazo](10-permisos-de-un-vistazo.md)), esta sección sí requiere uno de esos
papeles para acceder, no solo para ser gestionada.

## Listas

Una lista es una jerarquía de nombres con la profundidad que necesites — no hay límite de niveles.
Por ejemplo, para reflejar la estructura de grupos de un centro:

```
Grupo
├── 1º ESO
│   ├── 1º ESO-A
│   └── 1º ESO-B
├── 2º ESO
│   └── 2º ESO-A
└── 3º ESO
    ├── 3º ESO-A
    └── 3º ESO-B
```

O la de sus departamentos:

```
Departamento
├── ESO/Bach
│   ├── Matemáticas
│   └── Biología y Geología
└── FP
    ├── Informática y Comunicaciones
    └── Sanidad
```

No hay una entidad "Lista" separada de sus elementos: cada elemento raíz (aquí, «Grupo» y
«Departamento») es simplemente un elemento sin padre, y toda la jerarquía que cuelga de él se
gestiona igual que el resto. Estas listas son permanentes por centro, igual que los perfiles fijos:
no se repiten cada curso académico.

### Navegar y crear elementos

En pantallas grandes, **Responsabilidades → Listas** muestra el árbol completo del centro: cada
elemento se renombra, reordena o mueve a otro padre **arrastrándolo** (icono ⣿ a su izquierda),
igual que las secciones del árbol documental. El botón **+** junto al título añade un elemento
raíz; el que aparece al pasar por encima de un elemento añade un hijo ahí mismo. Un icono de
enlace violeta junto al nombre indica que ese elemento ya tiene un perfil o subperfil asociado (ver
[Asociar un elemento con un perfil](#asociar-un-elemento-con-un-perfil) más abajo).

En pantallas pequeñas se navega con migas de pan en su lugar: empiezas en la raíz (todas las
listas del centro) y tocas el icono de flecha de un elemento para entrar en sus hijos, sin
arrastrar — se reordena con las flechas ↑/↓ del panel de edición. Un formulario en la parte
inferior de cada nivel añade elementos nuevos ahí mismo. El botón de ordenar (icono de flechas)
reordena alfabéticamente todos los elementos del nivel actual de una vez.

En ambos tamaños, tocar el nombre de un elemento abre el mismo panel de edición debajo (nombre,
estado, asociación a un perfil, etiquetas) — arrastrar nunca abre el panel, solo reordena o mueve.

### Asociar un elemento con un perfil

Cada elemento puede vincularse, opcionalmente, a un perfil o subperfil de Responsabilidades — por
ejemplo, una materia con la jefatura de departamento de la que depende. Es una referencia suelta,
sin relación con la jerarquía de la lista, distinta de [asociar un perfil a una lista para generar
subperfiles](#asociar-un-perfil-a-una-lista-subperfiles): esta usa
[Actividades](08-actividades.md#campos-de-una-actividad) para decidir a qué perfil corresponde la
entrega nombrada por cada hoja de una lista.

Para un elemento a la vez, se hace desde su panel de edición (**Asociado a**, con el mismo
buscador de perfil/subperfil que en el resto de la aplicación). Para varios a la vez, **Seleccionar
varios** —junto al título en pantallas grandes, o junto al contador de elementos en pequeñas—
sustituye el botón de cada elemento por una casilla; con uno o más marcados aparece una barra con
el mismo buscador y un botón **Asignar** que aplica el mismo perfil/subperfil a todos los marcados
de una vez (o se lo quita a todos, si se deja en «Sin asociar»). La selección no se limita a los
hijos de un mismo elemento: se puede marcar en varias ramas distintas antes de asignar.

### Elementos activos e inactivos

Cada elemento tiene un estado, **activo** o **inactivo**. Los elementos inactivos dejan de poder
elegirse en sitios nuevos (por ejemplo, al asociar un perfil específico a un elemento de la lista),
pero siguen viéndose con normalidad donde ya se estuvieran usando — no desaparece nada de golpe.
Útil, por ejemplo, para retirar un grupo del curso anterior sin perder su histórico.

### Eliminar elementos

Un elemento no se puede eliminar en dos casos:

- **Si tiene hijos** — hay que eliminar antes los elementos hijos, uno a uno o de dentro hacia
  fuera.
- **Si está en uso** — porque un perfil específico está asociado a él, o porque hay docentes
  asignados a través de él (ver [Perfiles específicos](#perfiles-especificos) más abajo).

### Importar desde Séneca

Dos botones en la parte superior de Listas — **Importar grupos desde Séneca** e **Importar
materias desde Séneca** — construyen una lista automáticamente a partir de los ficheros CSV que
exporta Séneca, en vez de crear cada elemento a mano:

- **Grupos**: en Séneca, con perfil Dirección, ve a **Alumnado → Relación de unidades → Curso:
  Cualquiera → Exportar datos**. Cada unidad del fichero se añade como un elemento dentro de la
  raíz elegida.
- **Materias**: en Séneca, con perfil Dirección, ve a **Personal → Personal del centro → Materias
  y grupos → Unidad: Cualquiera → Exportar datos**. Cada grupo del fichero se añade dentro de la
  raíz, y cada materia de ese grupo se añade dentro de su grupo.

Al elegir el fichero se pide también el **nombre de la raíz** que va a contener lo importado —
propone «Grupo» o «Materia» según el tipo, pero se puede escribir otro nombre; si ya existe una
raíz con ese nombre (comparando sin distinguir mayúsculas), se actualiza en vez de crear una nueva.

Antes de tocar nada se muestra una **previsualización**: qué elementos se añadirían, cuáles ya
existen y se reactivarían (si estaban inactivos) y cuáles ya no aparecen en el fichero. Para estos
últimos hay que elegir qué hacer:

- **Eliminar** los que no estén en uso.
- **Desactivar** en vez de eliminar — los que sí estén en uso (asociados a un perfil específico,
  con docentes asignados a través de uno, o usados en algún documento entregado) siempre se
  desactivan, nunca se eliminan, aunque se elija «Eliminar»; un elemento con hijos solo se puede
  eliminar si todos sus hijos también se pueden eliminar.

Nada se aplica hasta confirmar la previsualización.

### Etiquetas

Cualquier elemento de una lista, esté al nivel que esté (no solo las hojas), puede llevar sus
propias etiquetas. Las etiquetas se **heredan hacia abajo**: un elemento ve tanto las suyas propias
como las de todos sus antecesores.

Por ejemplo, si etiquetas el elemento «ESO/Bach» (hijo de «Departamento») con la etiqueta
`ESO/Bachillerato`, tanto «Matemáticas» como «Biología y Geología» —sus hijos— mostrarán esa
etiqueta como heredada, sin necesidad de repetirla en cada uno.

Las etiquetas se crean sobre la marcha, escribiendo su nombre en el campo de la sección
**Etiquetas** del elemento — si ya existe una con ese nombre en el centro (en cualquier otro
elemento), se reutiliza; si no, se crea al vuelo. No hay una pantalla aparte para gestionarlas: son
efímeras, así que cuando una etiqueta deja de estar asignada a cualquier elemento se elimina
automáticamente, sin dejar huérfanas que limpiar a mano.

En el panel de detalle, todas las etiquetas visibles en el elemento —propias y heredadas— se
muestran juntas, ordenadas alfabéticamente; las heredadas se distinguen visualmente (no se pueden
quitar desde ahí, hay que quitarlas del elemento donde están definidas).

## Perfiles específicos

Un perfil específico es una responsabilidad personalizada del centro — a diferencia de los perfiles
fijos (responsable de calidad, auditor/a interno/a), cada centro define los suyos con el nombre que
necesite: «Tutor/a», «Jefatura de departamento», «Coordinación TIC»...

### Asignación directa

En su forma más simple, un perfil específico no está asociado a ninguna lista: se crea con un
nombre y se le asignan directamente uno o varios docentes, buscándolos por nombre en el propio panel
de detalle. Es el caso adecuado para responsabilidades que no se repiten por grupo, departamento ni
ningún otro criterio — por ejemplo, «Coordinación TIC».

### Asociar un perfil a una lista: subperfiles

Cuando la responsabilidad sí se repite una vez por cada elemento de una lista, en vez de crear un
perfil por elemento a mano, asocias el perfil **a un elemento de una lista** (sección **Elemento de
lista asociado** → **Elegir**, con el mismo navegador de migas de pan que en Listas). A partir de
ahí, cada elemento **hoja** (sin hijos) descendiente de ese elemento se convierte automáticamente en
un **subperfil** independiente, con sus propios docentes asignados — no hace falta crearlos ni
mantenerlos a mano, se derivan solos de la lista.

El nombre de cada subperfil es el del perfil seguido del nombre de la hoja. Dos ejemplos:

- Perfil **«Tutor/a»** asociado al elemento **«Grupo»** → aparecen los subperfiles **«Tutor/a 1º
  ESO-A»**, **«Tutor/a 1º ESO-B»**, **«Tutor/a 2º ESO-A»**, etc. — uno por cada grupo-clase.
- Perfil **«Jefatura de Familia Profesional»** asociado al elemento **«FP»** (que cuelga de
  «Departamento») → aparecen los subperfiles **«Jefatura de Familia Profesional Informática y
  Comunicaciones»** y **«Jefatura de Familia Profesional Sanidad»** — uno por cada familia
  profesional, no por cada asignatura de «ESO/Bach».

Nótese que el elemento asociado no tiene por qué ser una raíz: en el segundo ejemplo, el perfil se
asocia a «FP», un hijo de «Departamento», así que solo genera subperfiles a partir de sus propias
hojas, ignorando la rama «ESO/Bach».

Para asignar docentes, selecciona primero el subperfil que corresponda en la lista de la sección
**Subperfiles** y después búscalos por nombre, igual que en la asignación directa.

!!! warning "Cambiar o quitar la asociación borra las asignaciones existentes"
    Si cambias el elemento de lista asociado a un perfil, o quitas la asociación (**Quitar
    asociación**), se eliminan **todas** las asignaciones de docentes de ese perfil — tanto las
    directas como las de todos sus subperfiles. Tiene sentido: las asignaciones existentes estaban
    ligadas a los subperfiles derivados de la asociación anterior, y ya no significan lo mismo con
    la nueva. Antes de cambiar la asociación de un perfil que ya tiene docentes asignados, anota
    quién estaba en cada subperfil si vas a necesitar volver a asignarlos.

### Perfiles activos e inactivos

Igual que los elementos de lista, un perfil específico tiene un estado, **activo** o **inactivo**.
Un perfil inactivo (y, si está asociado a una lista, cada uno de sus subperfiles) deja de poder
elegirse para asignaciones nuevas — tanto al asignar un docente directamente como al restringir un
evento del calendario (ver [Asignar perfiles](#asignar-perfiles) y
[Calendario](03-calendario.md#eventos)) — pero los docentes ya asignados no se pierden.

### Ordenar y eliminar perfiles

El botón de ordenar alfabético (junto al listado de perfiles) reordena todos los perfiles
específicos del centro de una vez. Eliminar un perfil elimina también todas sus asignaciones de
docentes, directas o a través de subperfiles; como los perfiles específicos ya no tienen jerarquía
propia, no hace falta comprobar hijos antes de borrar.

## Asignar perfiles

Mientras que **Perfiles específicos** es donde se crean y configuran los perfiles, **Asignar
perfiles** es una vista de trabajo sobre las asignaciones que ya existen, pensada para comprobar de
un vistazo quién tiene qué — especialmente útil al empezar un curso nuevo, cuando algunos docentes
ya no están. Tiene dos pestañas:

- **Perfiles** — todos los perfiles y subperfiles activos del centro, con los docentes asignados a
  cada uno. Un docente que ya no pertenece al curso académico activo se resalta en rojo. Un botón
  permite quitar de golpe, de todos los perfiles activos, a los docentes que ya no pertenecen al
  curso activo (con confirmación previa). Un botón aparte muestra también los perfiles/subperfiles
  inactivos, aparte del filtro por defecto. Al tocar un perfil se abre un panel para añadir o quitar
  docentes.
- **Docentes** — todos los docentes del curso académico activo (un botón permite ver también los de
  otros cursos, si todavía tienen alguna asignación), con los perfiles que tiene cada uno. Un perfil
  o subperfil inactivo se marca en rojo. Al tocar un docente se abre un panel para añadir o quitar
  perfiles.

Ambas pestañas incluyen buscador (por nombre de perfil/subperfil o de docente) y paginación.
