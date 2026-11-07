# Manual de diseño — WILLAQ

> Sistema visual de la sala de operaciones.
> Archivos: `assets/css/core/variables.css`, `assets/css/views/willaq.css`,
> `views/layout/header.php`, `assets/fonts/montserrat-var.woff2`

---

## 1. Principio visual

WILLAQ **no** se diseñó como una landing page. Se diseñó como una **sala de
operaciones**: alguien la abre con prisa, mira el semáforo, identifica el
distrito y decide. Eso ordena todas las decisiones:

| Decisión | Por qué |
|---|---|
| Fondo azul oscuro (`#0A1628`) | Reduce el brillo en pantalla grande y hace que el semáforo destaque |
| Un solo acento dorado (`#C5A880`) | Marca jerarquía sin competir con los colores de riesgo |
| Semáforo de 4 niveles | Es el lenguaje que la autoridad ya usa; no hay que enseñarlo |
| Números tabulares | Las cifras se comparan en columnas; no deben "bailar" |
| Densidad alta, aire controlado | Más información por pantalla, sin perder legibilidad |
| Todo local (fuente, Tailwind, mapas) | La feria puede quedarse sin internet |

La identidad heredada del proyecto base se conserva: **azul estratósfera,
dorado amanecer, Montserrat**. WILLAQ solo añade la capa semántica del riesgo.

---

## 2. Paleta y contraste

### 2.1 Marca

| Token | Valor | Uso |
|---|---|---|
| `--color-primary` / `--wq-gold` | `#C5A880` | Acento, bordes, eyebrows, foco |
| `--color-primary-hover` | `#B4966E` | Hover del acento |
| `--color-secondary` | `#48324F` | Púrpura crepuscular (degradados) |
| `--color-accent` | `#9C694C` | Oro rosa del atardecer (detalles) |
| `--color-dark` / `--wq-ink` | `#0A1628` | Fondo principal |
| `--color-surface` | `#132238` | Tarjetas y formularios |
| `--wq-ink-2` | `#081120` | Fondo de mapas y pozos |
| `--wq-panel` | `rgba(19,34,56,.78)` | Paneles translúcidos |
| `--wq-line` | `rgba(197,168,128,.18)` | Bordes suaves |
| `--wq-line-strong` | `rgba(197,168,128,.38)` | Bordes activos |

### 2.2 Riesgo

| Nivel | Token | Valor | Fondo suave |
|---|---|---|---|
| Bajo | `--wq-green` | `#22C55E` | `rgba(34,197,94,.14)` |
| Medio | `--wq-yellow` | `#EAB308` | `rgba(234,179,8,.14)` |
| Alto | `--wq-orange` | `#F97316` | `rgba(249,115,22,.14)` |
| Muy alto | `--wq-red` | `#EF4444` | `rgba(239,68,68,.14)` |

### 2.3 Contraste medido (WCAG 2.2 AA)

Sobre el fondo `#0A1628`:

| Texto | Ratio | AA (4.5:1) |
|---|---|---|
| Blanco `#FFFFFF` | **18.13:1** | Sí |
| Texto claro `#C5CDE0` | **11.38:1** | Sí |
| Dorado `#C5A880` | **8.02:1** | Sí |
| Verde `#22C55E` | **7.96:1** | Sí |
| Amarillo `#EAB308` | **9.45:1** | Sí |
| Naranja `#F97316` | **6.47:1** | Sí |
| Rojo `#EF4444` | **4.82:1** | Sí (justo) |
| Texto atenuado `#93A1B8` | **6.93:1** | Sí |

En las insignias, el texto rojo usa `#FF8F8F` sobre el fondo suave compuesto
(**5.74:1**), no `#EF4444`, para no quedar al límite.

> **Regla:** el rojo es el único color que se acerca al mínimo. No se usa rojo
> para texto pequeño sobre fondos claros ni para adornos. Si se necesita más
> contraste, se sube el texto a blanco y se deja el rojo solo en el punto o la
> barra.

---

## 3. Tipografía

**Montserrat variable**, servida en local (`assets/fonts/montserrat-var.woff2`,
peso 100–900). No se carga desde Google Fonts: la feria funciona sin red.

| Rol | Peso | Tamaño | Clase |
|---|---|---|---|
| Título de página | 200 | `clamp` según vista (Tailwind `text-4xl`/`5xl`) | `.wq-title` |
| Cifra KPI | 200, tabular | `2rem` | `.wq-num` |
| Cuerpo | 300 | `1rem` | base |
| Etiqueta superior | 500, mayúsculas, `0.32em` | `0.68rem` | `.wq-eyebrow` |
| Encabezado de tabla | 500, mayúsculas, `0.14em` | `0.64rem` | `.wq-table th` |
| Texto auxiliar | 300 | `0.7–0.8rem` | `.wq-muted` |

Reglas:

* **Máximo dos pesos por bloque** (200/300 para lectura, 500 para etiquetas).
* Las cifras siempre con `font-variant-numeric: tabular-nums` (`.wq-num`).
* No se usa cursiva decorativa ni mayúsculas en párrafos largos.
* El espaciado entre letras negativo (`-0.02em`) solo en títulos grandes.

---

## 4. Espaciado, radios y sombras

| Token | Valor | Uso |
|---|---|---|
| `--radius-md` | `0.5rem` | Controles pequeños |
| `--radius-lg` | `0.75rem` | Botones |
| `--radius-xl` | `1rem` | Tarjetas |
| `--radius-2xl` | `1.5rem` | Bloques destacados |
| `--shadow-md` | dorado 10 % + negro 30 % | Tarjetas |
| `--shadow-lg` | púrpura 20 % + dorado 15 % | Elementos elevados |
| `--transition-fast` | `150ms ease-in-out` | Hover, foco |
| `--transition-normal` | `300ms ease-in-out` | Aparición de paneles |

Escala de espacio usada en las vistas (Tailwind): `0.25 · 0.5 · 0.75 · 1 ·
1.5 · 2 · 3 · 4 · 6` rem. Las tarjetas usan `1.15–1.25rem` de padding interno y
`1rem` de separación entre sí.

**Regla de densidad:** si un bloque necesita más de tres niveles de anidación
visual, se divide en dos tarjetas. La sala de operaciones se lee en diagonal.

---

## 5. Componentes base

### 5.1 Tarjeta (`.wq-card`)

Fondo `--wq-panel`, borde `--wq-line`, radio `1rem`, padding `1.15–1.25rem`.
Se usa para KPIs, fichas y paneles laterales. Nunca para texto corrido largo.

### 5.2 KPI (`.wq-kpi`)

Cifra grande (`.wq-num`), etiqueta en `.wq-eyebrow`, y una barra superior de
3 px con el color del nivel. La barra **refuerza**, no sustituye: el número y la
palabra ("Muy alto") siempre están presentes.

### 5.3 Insignia (`.wq-badge`)

Mayúsculas, `0.68rem`, radio `999px`, fondo suave del nivel y borde del mismo
color al 35–42 %. Siempre lleva texto; nunca es solo un punto de color.

### 5.4 Punto de estado (`.wq-dot`)

`0.55rem`, con halo suave. Se usa en listas y en el panel de administración
(extensiones, snapshots). Acompaña siempre a una etiqueta textual.

### 5.5 Barra de proporción (`.wq-bar`)

Pista de 6 px y relleno con degradado del nivel. Sirve para comparar distritos
dentro de una tabla; no para representar series temporales (para eso, Chart.js).

### 5.6 Botones (`.btn`)

| Variante | Apariencia | Uso |
|---|---|---|
| `.btn--primary` | Borde dorado, texto dorado; hover relleno dorado | Acción principal |
| `.btn--outline` | Borde dorado, fondo transparente | Acción secundaria |
| `.btn--secondary` | Degradado púrpura | Acción alternativa |
| `.btn--large` | Padding `0.85rem 2.5rem` | CTA de portada |

Texto en mayúsculas, `0.85rem`, `letter-spacing: 0.12em`. El estado activo baja
a `scale(0.97)`.

### 5.7 Formularios (`.wq-input`, `.wq-select`)

Fondo `--color-surface`, borde `--wq-line`, radio `0.5rem`. En foco, borde
dorado y halo (`box-shadow`). Las etiquetas van **arriba** del campo y nunca
dentro como único placeholder.

### 5.8 Tabla (`.wq-table`)

Encabezado en mayúsculas pequeñas, filas con `0.8rem` de padding vertical y
separador `--wq-line`. En pantallas pequeñas se permite scroll horizontal; no se
ocultan columnas críticas (nivel, población, acciones).

---

## 6. Lenguaje del riesgo

El semáforo es el componente más importante del sistema. Tiene **cuatro**
niveles y una regla dura:

| Nivel | Color | Palabra obligatoria | Nunca |
|---|---|---|---|
| Bajo | Verde | "Bajo" | "Sin riesgo" (nunca es cero) |
| Medio | Amarillo | "Medio" | "Moderado" (ambigüedad) |
| Alto | Naranja | "Alto" | "Alerta naranja" (no somos autoridad) |
| Muy alto | Rojo | "Muy alto" | "Alerta roja" |

* El color **nunca** va solo: siempre con palabra y, cuando aplica, con cifra.
* El texto legal viaja en cada pantalla que muestra riesgo:
  *"Recomendación de apoyo a la decisión. No constituye una alerta oficial."*
* No se usan degradados entre niveles: el nivel es discreto, no continuo.

---

## 7. Estructura y navegación

### 7.1 Cabecera

Altura fija `4rem` (`h-16`), `position: sticky`, fondo translúcido con blur.
Contiene marca, navegación de 6–7 acciones y el bloque de sesión.

* Navegación: **Sala de situación · Mapa · Alertas · Planificar · Simular ·
  Ayuda** (+ **Consola** solo para administrador).
* En móvil, el menú se colapsa en un botón con icono y texto accesible.
* La ventana temporal (`24/48/72 h`) se conserva al navegar entre vistas.

### 7.2 Contenedor

Ancho máximo `80rem` (`max-w-7xl`) y padding lateral `1rem` (`1.5rem` en
escritorio). El mapa a pantalla completa es la excepción: ocupa
`calc(100vh - 4rem)` y no lleva contenedor.

### 7.3 Rejilla

* KPIs: 2 columnas en móvil, 4 en escritorio.
* Ficha de distrito: una columna en móvil, `2/3 + 1/3` en escritorio.
* Formularios: una columna; nunca dos campos por fila por debajo de `640px`.

### 7.4 Breakpoints

Se usan los de Tailwind (`sm 640`, `md 768`, `lg 1024`, `xl 1280`). El diseño se
prueba como mínimo en **360 px**, **768 px** y **1440 px**.

---

## 8. Movimiento e impresión

* Transiciones de `150–300 ms`; nada de animaciones de más de 400 ms.
* El punto rojo "muy alto" puede pulsar suavemente; es la única animación
  recurrente y se desactiva con `prefers-reduced-motion`.
* Con `prefers-reduced-motion: reduce` se anulan animaciones y transiciones, y
  el scroll deja de ser suave.
* **Impresión** (`@media print`): fondo blanco, texto negro, se ocultan
  navegación, botones y pie; las tarjetas pasan a borde gris y `break-inside:
  avoid`. El reporte PDF se genera desde esa misma vista.

---

## 9. Accesibilidad

| Requisito | Cómo se cumple |
|---|---|
| Contraste AA | Todos los pares principales ≥ 4.5:1 (§2.3) |
| Color no es el único canal | Nivel escrito + cifra + barra/insignia |
| Foco visible | `:focus-visible` con contorno dorado de 2 px y offset 2 px |
| Navegación por teclado | Enlaces y controles nativos; sin `div` clicables |
| Texto alternativo | Iconos decorativos; el texto de la acción siempre existe |
| Movimiento reducido | `prefers-reduced-motion` respetado |
| Idioma | `<html lang="es">` y cifras con formato local |
| Tamaño de texto | Base `1rem`; no se usa texto menor a `0.64rem` |

Pendiente honesto: **no se ha hecho un test de usabilidad con personas
reales**. La comprobación actual es heurística y de contraste medido.

---

## 10. Modo offline

| Recurso | Local | Si falla |
|---|---|---|
| Montserrat | `assets/fonts/montserrat-var.woff2` | Cae a `sans-serif` |
| Tailwind | `assets/vendor/tailwind.js` | — |
| MapLibre | `assets/vendor/maplibre-gl.{js,css}` | El mapa no se dibuja; el resto sigue |
| Chart.js | `assets/vendor/chart.umd.min.js` | Los gráficos no se dibujan; las tablas siguen |
| Iconos (Font Awesome) | CDN | `html.sin-iconos` los oculta; el texto permanece |

El modo offline se activa con `WILLAQ_MODO_OFFLINE=1` y no cambia el diseño:
solo cambia el origen de los datos (snapshots por ventana).

---

## 11. Dónde cambiar cada cosa

| Qué | Archivo |
|---|---|
| Colores de marca | `assets/css/core/variables.css` |
| Colores de riesgo y utilidades | `assets/css/views/willaq.css` (`:root`) |
| Paleta expuesta a Tailwind | `views/layout/header.php` (`tailwind.config`) |
| Botones | `assets/css/components/buttons.css` |
| Tarjetas | `assets/css/components/cards.css` |
| Formularios | `assets/css/components/forms.css` |
| Cabecera y navegación | `assets/css/layout/header.css` + `views/layout/header.php` |
| Estilos de impresión | `assets/css/views/willaq.css` (`@media print`) |

**Regla:** un color nuevo entra primero como token en `:root`; nunca se escribe
un hexadecimal suelto en una vista.

---

---

## Documentos relacionados

| Documento | Para qué |
|---|---|
| [`README.md`](../README.md) | Vista general, scripts y estructura del proyecto |
| [`METODOLOGIA.md`](METODOLOGIA.md) | La fórmula del índice, los umbrales y la regla de materialidad |
| [`ARQUITECTURA.md`](ARQUITECTURA.md) | Cómo está construido el sistema y por qué se decidió así |
| [`VALIDACION_HISTORICA.md`](VALIDACION_HISTORICA.md) | El backtest contra El Niño 2017/2017-18/2023 y el análisis de sensibilidad |
| [`API.md`](API.md) | Los 4 endpoints JSON con ejemplos ejecutables |
| [`COMPARATIVA.md`](COMPARATIVA.md) | WILLAQ frente a SENAMHI, ENFEN, Google Flood Hub e INDECI |
| [`MANUAL_INSTALACION.md`](MANUAL_INSTALACION.md) | Despliegue paso a paso en hosting compartido |
| [`REQUERIMIENTOS.md`](REQUERIMIENTOS.md) | Requisitos con criterio de aceptación y su prueba |
| [`MANUAL_DISENO.md`](MANUAL_DISENO.md) | Sistema visual: paleta, tipografía, componentes y accesibilidad |
| [`MANUAL_USUARIO.md`](MANUAL_USUARIO.md) | Cómo se usa la aplicación, por rol |
| [`GUION_DEMO.md`](GUION_DEMO.md) | Guion de sustentación de 8 minutos |

## 12. Lista de verificación antes de publicar un cambio visual

- [ ] El color sale de un token, no de un hexadecimal suelto.
- [ ] El nivel de riesgo se entiende **sin** mirar el color.
- [ ] El contraste del texto nuevo es ≥ 4.5:1 sobre su fondo real.
- [ ] Se probó a 360 px, 768 px y 1440 px.
- [ ] Se probó con teclado (Tab) y el foco se ve.
- [ ] Se probó con `prefers-reduced-motion`.
- [ ] La vista imprime en blanco y negro de forma legible.
- [ ] No se añadió una librería externa que rompa el modo offline.
- [ ] El texto legal "apoyo a la decisión, no alerta oficial" sigue presente
      donde se muestra riesgo.
