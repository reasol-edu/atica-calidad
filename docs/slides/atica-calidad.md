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

Este es el **esqueleto inicial** de la aplicación: la base técnica, el acceso, la administración
del centro y el calendario ya están construidos. La gestión documental propiamente dicha —el
contenido central del SGC— se irá añadiendo a partir de aquí.

---

# Quién es quién

---

## Docente

Accede a la aplicación con su usuario y consulta el **calendario** del centro.

## Responsable de calidad / Auditor/a interno/a

Perfiles asignables a docentes concretos, pensados para coordinar y auditar el sistema de gestión
de la calidad del centro a medida que se construya esa parte de la aplicación.

## Equipo directivo / Administración del centro

Configura el centro educativo: cursos académicos, docentes, oferta formativa, días no lectivos,
perfiles y ajustes.

## Administración de la plataforma

Mantiene el servidor y gestiona todos los centros alojados en él.

---

# El calendario

---

## Navegación y eventos

El calendario muestra los días lectivos del curso académico, con los festivos y días no lectivos
marcados. El equipo directivo puede programar **eventos** de centro, generales o restringidos a
grupos concretos.

Más adelante, aquí se mostrarán también los **plazos de entrega de documentación** del sistema de
gestión de la calidad.

---

# Centro educativo

---

## Preparar el curso académico

- **Cursos académicos** — crear y activar el curso.
- **Docentes del centro** — altas manuales o importación desde Séneca.
- **Oferta formativa** — cursos, grupos y quién imparte clase en cada uno.
- **Días no lectivos** — importables desde el calendario escolar oficial o desde Séneca.
- **Perfiles** — responsable de calidad y auditor/a interno/a.
- **Registro de avisos por correo** y **ajustes del centro**.

---

# En resumen

---

## Una base sólida para construir encima

ÁTICA Calidad parte de una infraestructura ya probada (Symfony, multi-centro, autenticación,
generación de PDF, sistema de ajustes) para poder centrar el desarrollo en lo específico de la
gestión documental de la calidad, sin reconstruir esa base desde cero.

**Gracias por tu tiempo.**
