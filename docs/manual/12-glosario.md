# Anexo: Glosario

**Centro educativo**
: Unidad organizativa principal de la aplicación. Un mismo servidor puede alojar varios, con datos
completamente separados.

**Curso académico**
: Periodo anual de un centro (p. ej. «2026-2027»). Cada centro tiene siempre un curso **activo**,
el que se muestra por defecto al profesorado.

**SGC**
: Sistema de gestión de la calidad: el conjunto de procesos, documentación y responsables que
ÁTICA Calidad ayuda a organizar.

**Responsable de calidad**
: Perfil que coordina el sistema de gestión de la calidad de un centro.

**Auditor/a interno/a**
: Perfil que realiza las auditorías internas del sistema de gestión de la calidad de un centro.

**Responsabilidades**
: Sección del menú lateral, accesible al responsable de calidad, al equipo directivo y a la
administración, donde se gestionan las listas, los perfiles específicos y sus asignaciones.

**Lista**
: Jerarquía propia de nombres, con la profundidad que se necesite (p. ej. «Grupo» → «1º ESO» → «1º
ESO-A»), gestionada desde **Responsabilidades → Listas**. Sirve de base para construir perfiles
específicos sin repetir trabajo.

**Elemento de lista**
: Cada nodo de una lista, a cualquier profundidad. Puede estar activo o inactivo, y puede llevar
sus propias etiquetas, que heredan también todos sus descendientes.

**Etiqueta**
: Marca de texto libre que se asigna a un elemento de lista y se hereda hacia abajo por sus
descendientes. Se crea sobre la marcha al escribirla y se elimina automáticamente en cuanto deja de
estar asignada a ningún elemento.

**Perfil específico**
: Responsabilidad personalizada que cada centro crea en **Responsabilidades → Perfiles
específicos**. Puede asignar docentes directamente, o asociarse a un elemento de una lista para
generar automáticamente un subperfil por cada hoja descendiente. Puede estar activo o inactivo. Es
ante todo un registro documental, sin efecto sobre los permisos de administración general — pero sí
puede usarse para restringir a quién se muestra un evento del calendario, o quién ve o gestiona una
sección o una carpeta del árbol documental (ver [Árbol documental](07-arbol-documental.md)).

**Subperfil**
: Perfil «virtual» que aparece automáticamente cuando un perfil específico está asociado a un
elemento de lista: hay uno por cada hoja descendiente de ese elemento, y cada uno tiene sus propios
docentes asignados. No existe como entidad aparte; desaparece si cambia la asociación del perfil.

**Asignar perfiles**
: Tarjeta de **Responsabilidades** con una vista de trabajo sobre las asignaciones de perfiles
específicos ya existentes, por perfil o por docente, pensada para detectar de un vistazo docentes
que ya no pertenecen al curso académico activo.

**Árbol documental**
: Sección del menú lateral, visible para todo el profesorado, donde vive la documentación del
sistema de gestión de la calidad: secciones, carpetas, documentos y revisiones. Ver
[Árbol documental](07-arbol-documental.md).

**Sección (del árbol documental)**
: Nodo de la estructura del árbol documental, con la profundidad que se necesite. Se gestiona desde
la pestaña **Editar árbol**, reservada a responsable de calidad, equipo directivo/admin. del centro
y admin. de la plataforma. Puede restringirse a perfiles/subperfiles de Responsabilidades; la
restricción no se hereda a sus subsecciones.

**Carpeta**
: Contenedor de documentos dentro de una sección del árbol documental. Se crea y configura desde la
pestaña **Ver**, también reservado a responsable de calidad/equipo directivo/admin. Tiene cuatro
listas independientes de perfiles/subperfiles — responsables, de subida, de visibilidad y de
revisión — que determinan quién puede ver y gestionar su contenido (ver
[Permisos sobre una carpeta](07-arbol-documental.md#permisos-sobre-una-carpeta)). Puede marcarse
como **obsoleta** para ocultarla sin eliminar nada.

**Documento**
: Fichero con nombre propio dentro de una carpeta, con un historial de una o varias **revisiones**.
Se descarga siempre su **revisión activa**.

**Revisión**
: Cada versión numerada de un documento, con su propio fichero. Puede quedar **pendiente de visto
bueno** si la carpeta tiene perfiles de revisión configurados, hasta que alguien con ese permiso la
apruebe (pasa a ser la revisión activa) o la rechace.

**Revisión activa**
: La revisión de un documento que se descarga y se muestra por defecto. Se elige automáticamente al
aprobar una revisión pendiente, o manualmente por quien es responsable de la carpeta.

**Equipo directivo**
: Docentes con acceso a la administración de un centro concreto (creación de cursos académicos,
gestión de docentes, ajustes del centro...), sin necesidad de ser administradores globales.

**Administrador/a global**
: Cuenta con acceso a la administración de todos los centros alojados en el servidor.

**Ajuste bloqueado**
: Valor de configuración fijado por un nivel superior (global o de centro) que los niveles
inferiores no pueden sobrescribir.

**Actividad**
: Tarea o plazo periódico del sistema de gestión de la calidad, agrupada en una **categoría de
actividades**, con fecha límite (día y mes, se repite cada curso). Puede llevar una carpeta del
árbol documental vinculada (entonces se completa entregando un documento) o no (se completa a
mano). Ver [Actividades](08-actividades.md).

**Categoría de actividades**
: Nodo que agrupa actividades, con la profundidad que se necesite — misma idea que las secciones
del árbol documental. Se gestiona en **Actividades → Editar categorías**.

**Entrega**
: El documento que se sube para completar una actividad con carpeta vinculada — es, técnicamente,
un documento más de esa carpeta. Su revisión (aprobar/rechazar) sigue las mismas reglas que
cualquier otro documento del árbol documental.

**Ámbito de entrega**
: Si una actividad es **por perfil** (una entrega compartida por todos los que tienen ese
perfil/subperfil) o **individual** (cada docente entrega la suya).

**Completado automático**
: Modo de una actividad con carpeta en el que no hay botón de completar: se considera hecha en
cuanto el documento esperado está aprobado.

**Resumen diario**
: Modo de aviso por correo que agrupa lo pendiente de un día en un único correo, en vez de un aviso
individual por cada evento — ver [Ajustes disponibles](09-administrar-la-plataforma.md#ajustes-disponibles).
