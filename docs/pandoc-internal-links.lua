-- Pandoc Lua filter para el PDF del manual.
--
-- El manual se redacta como varios .md que MkDocs publica como páginas
-- independientes, pero el PDF los concatena en un único documento. Este filtro
-- reconcilia ambos mundos:
--
--   1. Reescribe los identificadores de cada encabezado con el mismo algoritmo
--      de "slug" que usa MkDocs (sin acentos), para que las anclas coincidan
--      con los fragmentos que aparecen en los enlaces del Markdown.
--   2. Convierte los enlaces entre ficheros (`fichero.md`, `fichero.md#ancla`)
--      en enlaces internos del PDF (`#ancla` o `#slug-del-fichero`).

-- Diacríticos del español -> ASCII (NFKD + ascii ignore, como hace MkDocs).
local accents = {
  ["á"] = "a", ["é"] = "e", ["í"] = "i", ["ó"] = "o", ["ú"] = "u",
  ["ü"] = "u", ["ñ"] = "n",
  ["Á"] = "A", ["É"] = "E", ["Í"] = "I", ["Ó"] = "O", ["Ú"] = "U",
  ["Ü"] = "U", ["Ñ"] = "N",
}

local function strip_accents(s)
  return (s:gsub("[%z\1-\127\194-\244][\128-\191]*", function(c)
    return accents[c] or c
  end))
end

-- Replica mkdocs/Python-Markdown slugify: NFKD->ascii, quita todo lo que no sea
-- alfanumérico/espacio/guion, minúsculas, y colapsa espacios y guiones en "-".
local function slugify(text)
  text = strip_accents(text)
  text = text:gsub("[^%w%s%-]", "")
  text = text:lower()
  text = text:gsub("[%s%-]+", "-")
  text = text:gsub("^%-+", ""):gsub("%-+$", "")
  -- Un id que empieza por dígito no es un selector CSS válido y rompe el
  -- target-counter de Paged.js (números de página del índice). Ningún enlace
  -- del manual apunta a estas secciones numeradas, así que basta con
  -- anteponer un prefijo.
  if text:match("^%d") then
    text = "sec-" .. text
  end
  return text
end

-- Dedup estilo MkDocs: id, id_1, id_2, ...
local seen = {}
local function unique(id)
  local candidate = id
  local n = 0
  while seen[candidate] or candidate == "" do
    n = n + 1
    candidate = id .. "_" .. n
  end
  seen[candidate] = true
  return candidate
end

-- Mapea el nombre de fichero al slug de su encabezado H1.
-- Quita el prefijo "NN-" y la extensión ".md"; "index" -> "introduccion".
local function file_to_slug(name)
  name = name:gsub("%.md$", "")
  name = name:gsub("^%d+%-", "")
  if name == "index" then
    return "introduccion"
  end
  return name
end

function Header(el)
  el.identifier = unique(slugify(pandoc.utils.stringify(el.content)))
  return el
end

function Link(el)
  local target = el.target
  -- No tocar enlaces externos ni anclas ya internas.
  if target:match("^%a[%w+.-]*://") or target:sub(1, 1) == "#" then
    return el
  end

  local file, fragment = target:match("^([%w%-_]+)%.md#(.+)$")
  if file then
    el.target = "#" .. fragment
    return el
  end

  file = target:match("^([%w%-_]+)%.md$")
  if file then
    el.target = "#" .. file_to_slug(file)
    return el
  end

  return el
end
