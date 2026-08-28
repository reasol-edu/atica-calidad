---
marp: true
title: ÁTICA Calidad — Presentación
author: ÁTICA Calidad
lang: es
paginate: true
header: 'ÁTICA Calidad'
footer: 'v{{VERSION}} ({{PUB_DATE}}) · ÁTICA Calidad'
style: |
  :root {
    --nx-ink: #141c26;
    --nx-accent: #5a7188;
    --nx-accent-soft: #f7f0ee;
    --nx-muted: #6b7280;
  }
  section {
    font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 26px;
    color: var(--nx-ink);
    padding: 56px 64px;
  }
  h1 { color: var(--nx-ink); font-size: 52px; }
  h2 { color: var(--nx-accent); font-size: 38px; border-bottom: 2px solid #cbb3bf; padding-bottom: 8px; }
  h3 { color: var(--nx-ink); font-size: 28px; }
  strong { color: var(--nx-accent); }
  table { font-size: 20px; }
  th { background: var(--nx-accent); color: #fff; }
  tr:nth-child(even) { background: var(--nx-accent-soft); }
  code { background: var(--nx-accent-soft); color: var(--nx-ink); }
  header { color: var(--nx-muted); font-size: 16px; }
---

# ÁTICA Calidad

## Gestión documental de apoyo al sistema de gestión de la calidad

---

## ¿Qué es ÁTICA Calidad?

Una aplicación web para organizar la documentación, los plazos y los responsables del **sistema de
gestión de la calidad (SGC)** de un centro educativo, en un solo lugar y con acceso diferenciado
por docente y por centro.

- **Multi-centro**: un mismo servidor puede alojar varios centros con datos separados.
- Acceso con usuario y contraseña propios, o mediante autenticación externa (iSéneca).

---

## Estado del proyecto

La base técnica, el acceso, la administración del centro, el calendario, Responsabilidades y el
**árbol documental** —el contenido central del SGC— ya están construidos. La aplicación se irá
ampliando a partir de aquí (informes, plazos de entrega...).

---

# Quién es quién

---

## Docente

Accede a la aplicación con su usuario y consulta el **calendario** del centro.

## Responsable de calidad / Auditor/a interno/a

Perfiles asignables a docentes concretos, con acceso completo al árbol documental del centro para
coordinar y auditar su sistema de gestión de la calidad. El responsable de calidad, además,
gestiona **Responsabilidades** (siguiente sección).

## Equipo directivo / Administración del centro

Configura el centro educativo: cursos académicos, docentes, días no lectivos, perfiles y ajustes.
También gestiona Responsabilidades.

## Administración de la plataforma

Mantiene el servidor y gestiona todos los centros alojados en él.

---

# El calendario

---

## Navegación y eventos

El calendario muestra los días lectivos del curso académico, con los festivos y días no lectivos
marcados. El equipo directivo puede programar **eventos** de centro, generales o restringidos a
perfiles o subperfiles concretos de Responsabilidades.

Más adelante, aquí se mostrarán también los **plazos de entrega de documentación** del sistema de
gestión de la calidad.

---

# Centro educativo

---

## Preparar el curso académico

- **Cursos académicos** — crear y activar el curso.
- **Docentes del centro** — altas manuales o importación desde Séneca.
- **Días no lectivos** — importables desde el calendario escolar oficial o desde Séneca.
- **Perfiles** — responsable de calidad y auditor/a interno/a del centro.
- **Registro de avisos por correo** y **ajustes del centro**.

---

# Responsabilidades

---

## Listas

Jerarquías propias de nombres, con la profundidad que se necesite:

```
Grupo → 1º ESO → 1º ESO-A, 1º ESO-B
Departamento → FP → Informática y Comunicaciones, Sanidad
```

Cada elemento puede llevar **etiquetas**, creadas sobre la marcha y heredadas por sus
descendientes. Los elementos inactivos o en uso no se pierden ni se pueden borrar por accidente.

## Perfiles específicos → subperfiles

Cada centro crea sus propias responsabilidades (tutorías, jefaturas...) y les asigna docentes,
directamente o **asociadas a una lista**: entonces cada hoja genera un subperfil automático.

- «Tutor/a» + lista «Grupo» → *Tutor/a 1º ESO-A*, *Tutor/a 1º ESO-B*...
- «Jefatura de Familia Profesional» + «FP» → *Jefatura... Informática y Comunicaciones*, *Jefatura...
  Sanidad*

Estos mismos perfiles y subperfiles también sirven para restringir eventos del calendario a quien
corresponda.

## Asignar perfiles

Vista transversal sobre las asignaciones ya hechas, por perfil o por docente, con aviso de
docentes fuera del curso activo y borrado masivo de esas asignaciones.

---

# Árbol documental

---

## Secciones, carpetas, documentos y revisiones

- **Secciones** — la estructura del árbol (como el índice de una carpeta física), restringible por
  perfil de Responsabilidades. Se editan en la pestaña **Editar árbol**.
- **Carpetas** — dentro de cada sección, con cuatro listas de perfiles independientes: quién la
  **gestiona**, quién puede **subir**, a quién se le **muestra** y quién debe dar el **visto
  bueno**. Se crean y configuran en la pestaña **Ver**.
- **Documentos y revisiones** — cada documento guarda su historial completo de versiones; si la
  carpeta lo exige, una revisión nueva queda **pendiente** hasta que se aprueba o se rechaza.

## Búsqueda

Tres formas de buscar según el contexto —global sobre todo el árbol, local dentro de una sección, o
la paleta de comandos (**⌘K**) desde cualquier pantalla— con resultados resaltados y acceso directo
a la sección, carpeta o documento que coincide.

---

# En resumen

---

## Una base sólida para construir encima

ÁTICA Calidad parte de una infraestructura ya probada (Symfony, multi-centro, autenticación,
generación de PDF, sistema de ajustes) para poder centrar el desarrollo en lo específico de la
gestión documental de la calidad, sin reconstruir esa base desde cero.

**Gracias por tu tiempo.**
