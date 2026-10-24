# Administrar el centro educativo

Todas las secciones de administración de un centro concreto se agrupan bajo **Centro educativo**,
visible en el menú lateral para el equipo directivo y la administración del centro. Su
configuración inicial se describe en
[Preparar el curso académico](02-preparar-el-curso-academico.md); este capítulo recoge lo que falta
por documentar del resto del hub.

![Portada de Centro educativo: una tarjeta por cada herramienta (cursos, docentes, días no lectivos, perfiles, registro de avisos, motivos de rechazo y ajustes), con un resumen de cada una](img/centro-inicio.png)

Cada tarjeta lleva a una herramienta y resume su estado: aquí, 2 cursos académicos, 34 docentes en el
curso activo y 16 días no lectivos registrados.

## Registro de avisos por correo

Desde **Centro educativo → Registro de avisos por correo** se consulta el historial de correos
automáticos enviados por la aplicación (destinatario, evento, asunto y resultado del envío),
filtrable por fecha, evento y resultado. El registro solo se guarda si el ajuste **«Registrar los
avisos por correo»** está activado (ver [Ajustes disponibles](10-administrar-la-plataforma.md#ajustes-disponibles)),
y sus entradas se eliminan automáticamente pasado el número de días configurado en
**«Retención de los registros»**.

![Registro de avisos por correo: ocho avisos de distintos tipos, con su destinatario, evento, asunto y resultado; el de «Mejora continua: incidencia resuelta» figura como fallido](img/centro-registro-avisos.png)

En la captura hay ocho avisos de seis tipos distintos —recordatorios de actividades pendientes,
documentos aceptados o rechazados, revisiones pendientes, acciones de mejora asignadas y el resumen
diario de revisiones—. El del 21/10 a la responsable de calidad aparece **fallido** (la columna
*Resultado* lo marca en rojo y guarda el mensaje de error del servidor de correo): es la pista para
saber que el correo del centro no está llegando y revisar la
[configuración del correo](10-administrar-la-plataforma.md#correo-electronico-del-servidor).

De forma análoga, el equipo directivo puede activar o desactivar para su centro el
[registro de actividad](10-administrar-la-plataforma.md#registro-de-actividad) (auditoría de
seguridad) con el ajuste **«Registrar la actividad de los usuarios»** en **Centro educativo →
Ajustes del centro**. El registro en sí solo lo consultan los administradores de la plataforma.

## Motivos de rechazo {#motivos-de-rechazo}

Al rechazar una entrega desde la [cola de revisión](08-actividades.md#cola-de-revision), quien revisa
puede elegir con un clic uno de los motivos predefinidos y completarlo con su propio texto. Desde
**Centro educativo → Motivos de rechazo** cada centro decide cuáles ofrecer: una **lista de texto,
un motivo por línea**. Se ignoran las líneas vacías y repetidas, y cada motivo admite hasta 255
caracteres.

![Pantalla de motivos de rechazo del centro](img/centro-motivos-rechazo.png)

Mientras el centro no guarde una lista propia se usan los **motivos estándar** (formato no válido,
documento incompleto, versión incorrecta, falta la firma, fichero ilegible, no corresponde a la
actividad). Dejar el cuadro vacío o pulsar **Restablecer los estándar** vuelve a ellos. El cambio solo
afecta a los motivos que se ofrecen: los rechazos ya hechos conservan el texto con el que se hicieron.

## Varios centros en un mismo servidor

Un mismo servidor puede alojar varios centros educativos con datos completamente separados. Quien
tiene acceso a más de uno elige con cuál trabajar al entrar, y puede cambiar de centro en cualquier
momento desde el icono junto al nombre del centro, en el menú lateral.

![Pantalla de selección de centro: dos centros, el CEIP Miguel Hernández (Granada) y el IES Ada Lovelace (Málaga), con su localidad y código](img/acceso-seleccion-centro.png)

La pantalla de selección solo aparece a quien pertenece a más de un centro (en los datos de
demostración, el usuario `multicentro`). Cada centro se muestra con su localidad y su código. Ya
dentro, el icono de las dos flechas junto al nombre del centro y del curso, en la cabecera del menú
lateral, vuelve a esta pantalla; quien solo tiene un centro no lo ve.

![Cabecera del menú lateral: nombre del centro, curso académico y, a la derecha, el icono de cambiar de centro](img/acceso-cambiar-centro.png)
