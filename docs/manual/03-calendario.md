# Calendario

Todo el profesorado tiene acceso a un calendario mensual del centro, con navegación por mes y
detalle de cada día.

## Navegación

El calendario muestra los días lectivos del curso académico visualizado (de lunes a viernes; los
festivos y días no lectivos aparecen marcados). Pulsa sobre cualquier día para ver su detalle:
eventos programados y, si el día no es lectivo, el motivo.

![Calendario de octubre de 2026: cuadrícula de lunes a viernes con una actividad que vence el 1 de octubre (banda roja), un evento el día 15 («Evaluación inicial», en azul) y bandas azules que cruzan las semanas del 19 y del 26; arriba, el botón «Suscribirme a mi calendario» y las pestañas Calendario y Eventos](img/calendario.png)

La cuadrícula solo tiene **lunes a viernes**: los fines de semana no se muestran. Cada celda lleva el
número del día; los **eventos** aparecen como una etiqueta con su hora y su nombre (el del día 15,
de 16:00 a 18:00), y las **actividades** con plazo como una banda que cubre todos los días de su
periodo (la roja, que vence el 1 de octubre, es una actividad vencida). Las flechas de arriba cambian
de mes y **Hoy** vuelve al mes actual.

Los días **no lectivos** se distinguen a primera vista: la celda va en un tono más oscuro y el número
aparece tachado. En diciembre de los datos de demostración se ve el puente de la Constitución (7 y 8) y
las vacaciones de Navidad (del 23 en adelante):

![Calendario de diciembre de 2026: los días 7 y 8, y del 23 de diciembre en adelante, con la celda más oscura y el número tachado; la primera semana lleva la banda de una actividad que vence](img/calendario-no-lectivos.png)

El **detalle de un día** (al pulsarlo) agrupa lo que ocurre en él: los eventos y las actividades con
plazo ese día, y botones para pasar al día anterior o siguiente. Si el día es no lectivo, un aviso
lo dice y da el motivo:

![Detalle del martes 8 de diciembre de 2026: el aviso «Día no lectivo: Inmaculada Concepción» y, debajo, los bloques Eventos y Actividades, ambos vacíos](img/calendario-dia-no-lectivo.png)

![Detalle del martes 15 de septiembre de 2026, un día lectivo: no hay eventos, y en Actividades figuran «Lectura y conformidad con la Política de Calidad» y «Revisión por la dirección (acta)»](img/calendario-dia.png)

Las actividades de un día se muestran con su título y enlazan a la propia actividad. El botón
**Añadir evento**, arriba a la derecha, crea un evento para esa fecha (ver [Eventos](#eventos)).

## Eventos

El equipo directivo y la administración del centro pueden crear eventos (p. ej. una reunión o una
fecha de entrega) desde la pestaña **Eventos** del calendario. Cada evento puede ser **general**
(visible para todo el profesorado del centro) o **restringido a perfiles o subperfiles concretos**
de [Responsabilidades](06-responsabilidades.md#perfiles-especificos) (solo lo ven quienes tengan
asignado alguno de los perfiles o subperfiles seleccionados).

## Plazos de actividades

El calendario muestra también las actividades del docente, con enlace directo a cada una. Una
actividad con un rango real (fecha de inicio y de fin distintas) aparece como una banda que cubre
**todos los días** de ese periodo, tanto en la cuadrícula del mes como en el detalle de cada día;
una actividad de fecha única (inicio y fin coinciden) se muestra como una sola marca en esa fecha.
Cada **categoría** de actividades tiene su propio color, así que las actividades de la misma
categoría se reconocen de un vistazo. Ver
[Actividades](08-actividades.md#actividades-en-el-calendario).

## Suscripción al calendario {#suscripcion-al-calendario}

Cada docente puede **suscribir su aplicación de calendario** (Google Calendar, Apple Calendar, el
calendario del móvil…) a sus plazos personales. Desde el calendario, el botón **Suscribirme a mi
calendario** abre una página donde **Activar mi calendario** genera una dirección propia. En el
calendario aparecen, como eventos de día completo:

- las **actividades** que le tocan y aún no ha completado ni tiene en revisión (incluidas las que
  todavía no se han abierto), en la fecha de su plazo, con enlace a la actividad;
- sus **tareas de Mejora continua** que tienen fecha límite.

Es el mismo contenido que «Tus próximos pasos»: lo que se completa o entra en revisión desaparece en
la siguiente actualización. Un calendario es **por centro**.

![Página de suscripción al calendario, con la dirección generada y las instrucciones por aplicación](img/calendario-suscripcion.png)

La página ofrece **Copiar** la dirección y **Abrir en mi aplicación de calendario** (para móviles
y Mac), con las instrucciones para Google Calendar, Apple Calendar, iPhone/iPad y Android. Hay
también una [ficha rápida](https://github.com/reasol-edu/atica-calidad/blob/main/docs/cheatsheets/calendario-movil.md).

!!! warning "La dirección es secreta"
    La aplicación de calendario no inicia sesión: quien conozca la dirección puede ver tus plazos. No
    la compartas. Si crees que otra persona la tiene, pulsa **Generar una dirección nueva** (la
    anterior deja de funcionar y hay que volver a suscribirse) o **Desactivar**.

Las aplicaciones de calendario refrescan las suscripciones cada pocas horas (Google puede tardar
hasta un día), así que un cambio reciente puede tardar en verse.
