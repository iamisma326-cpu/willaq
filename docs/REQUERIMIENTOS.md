# Requerimientos y criterios de aceptación — WILLAQ

> Versión 1 · piloto Perú (29 distritos, 3 regiones)
> Documento de verificación: cada requisito tiene una prueba ejecutable y un
> resultado esperado. Si una prueba no se puede correr, el requisito **no** se
> declara cumplido.

---

## 1. Alcance

WILLAQ es una herramienta de **apoyo a la decisión** territorial frente al
Fenómeno El Niño. Cubre tres pilares:

| Pilar | Pregunta | Módulo |
|---|---|---|
| **Vigilar** | ¿Qué va a pasar en 24/48/72 h y a quién afecta? | Motor de riesgo, mapa, alertas |
| **Planificar** | ¿Se puede construir aquí? | ISE y evaluación de sitio |
| **Simular** | ¿Qué pasa si llueve más, si evacúo o si reubico? | Simulador |

**No** emite alertas oficiales, **no** predice el clima por cuenta propia y
**no** cubre todo el país. Esas exclusiones son parte del contrato (§6).

---

## 2. Actores y permisos

| Actor | Puede | No puede |
|---|---|---|
| **Ciudadano** | Ver sala, mapa, fichas, alertas, planificación y simulador | Emitir recomendaciones, entrar a la consola |
| **Autoridad** | Todo lo anterior + emitir recomendación y registrar acuse | Consola, caché, snapshots |
| **Administrador** | Todo + consola, recalcular snapshots, limpiar caché | — |

**Criterio de aceptación:** un ciudadano que abre `?action=admin` ve la pantalla
de acceso, no la consola. Un `POST action=emitir_alerta` sin sesión de autoridad
redirige a `?action=home&login=required`.

**Verificación:**

```bash
# Sin sesión: debe devolver el formulario de acceso, no la consola
curl -s "http://localhost:8000/?action=admin" | grep -q 'name="action" value="login"'

# Con sesión de administrador: debe devolver la consola
curl -s -c /tmp/cj.txt -d "action=login&email=admin@willaq.pe&password=willaq2026" \
  "http://localhost:8000/index.php" -o /dev/null
curl -s -b /tmp/cj.txt "http://localhost:8000/?action=admin" | grep -q "Consola de administración"
```

---

## 3. Requisitos funcionales

### RF-01 · Sala de situación

La página inicial muestra, sin iniciar sesión: estado ENSO, semáforo nacional,
conteo por nivel, distritos priorizados, fuente y antigüedad del dato.

**Aceptación:**
* `GET /?action=home` → HTTP 200.
* Aparecen los cuatro niveles con su conteo y la frase de apoyo a la decisión.
* Se indica si el dato es **En línea** u **Offline**.

**Prueba:** `curl -s "http://localhost:8000/?action=home" | grep -c "Muy alto"`

---

### RF-02 · Catálogo territorial

El sistema conoce los distritos piloto con población, colegios, centros de
salud, zona y susceptibilidades.

**Aceptación:**
* `GET /?action=api_territorios` → HTTP 200, `total = 29`.
* Cada elemento tiene `id`, `pais`, `region`, `distrito`, `lat`, `lon`, `zona`.
* La respuesta declara la procedencia de la exposición: `meta.fuente_exposicion`
  (catálogo) y el bloque por campo `exposicion` (oficial vs. estimada).
* Con el overlay del INEI aplicado, `poblacion` es **oficial** en los 29
  distritos; `viviendas`, `escuelas` y `centros_salud` siguen **estimados** y
  se declaran como tales. `exposicion.estado` = `mixta`.
* Sin overlay, `meta.fuente_exposicion` sigue siendo
  `estimacion_ratio_poblacional`; con overlay, cada campo declara su fuente,
  año y archivo.

**Prueba:**

```bash
curl -s "http://localhost:8000/?action=api_territorios" \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["total"], " ", $d["meta"]["fuente_exposicion"], PHP_EOL;'
# Esperado sin overlay: 29 estimacion_ratio_poblacional
# Con el overlay aplicado: 29 estimacion_ratio_poblacional
#   (el bloque meta.exposicion_oficial dice cuántos campos son oficiales)
```

---

### RF-03 · Motor de riesgo multi-amenaza

Cada distrito recibe un índice 0–100 con seis amenazas: lluvia, inundación,
huaico, sequía, calor y agro. El índice sigue:

```
R = 100 · H · (0.40 + 0.35·E + 0.25·V)
```

**Aceptación:**
* `GET /?action=api_riesgo&id=piu-catacaos` devuelve `riesgo` entre 0 y 100,
  `nivel.id` ∈ {verde, amarillo, naranja, rojo} y `amenazas` con las seis claves
  (`lluvia`, `inundacion`, `huaico`, `sequia`, `calor`, `agro`).
* `peligro`, `exposicion` y `vulnerabilidad` están redondeados a **3 decimales**
  y son consistentes entre sí: recalcular la fórmula con esos valores da el
  mismo `riesgo` (tolerancia 0.1 por el redondeo final).

**Prueba:**

```bash
curl -s "http://localhost:8000/?action=api_riesgo&id=piu-catacaos" | php -r '
$d=json_decode(stream_get_contents(STDIN),true);
$p=$d["amenazas"][$d["principal"]];
$r=100*$p["peligro"]*(0.40+0.35*$p["exposicion"]+0.25*$p["vulnerabilidad"]);
printf("R=%.1f recalculado=%.1f nivel=%s\n",$d["riesgo"],$r,$d["nivel"]["id"]);'
```

---

### RF-04 · Explicabilidad

Cada amenaza expone **valor observado, umbral, fuente y contribución**, y el
distrito muestra un resumen en lenguaje natural.

**Aceptación:** `amenazas.*.detalle` es una lista no vacía; `fuentes` es un
objeto con `pronostico`, `caudal`, `historico` y `climatologia`; `actualizado`
es una marca de tiempo Unix.

**Prueba:** `curl -s "…/api_riesgo&id=…" | grep -o '"fuente"' | wc -l` → ≥ 1.

---

### RF-05 · Regla de materialidad

Un valor solo cuenta como señal si supera el umbral absoluto **y** el percentil
95 local, con un margen (`factor_materialidad`). Así se evita que un día normal
de Piura se lea como extremo.

**Aceptación:** la respuesta incluye `materialidad` por variable (p. ej.
`lluvia: {min_mm, signo_mm, factor}`); un valor por debajo de `min_mm` no eleva
el nivel.

**Prueba:** inspeccionar `materialidad` en `api_riesgo&id=…` y contrastarlo con
`docs/METODOLOGIA.md` §3.

---

### RF-06 · Mapa interactivo

Mapa MapLibre con distritos coloreados por nivel, panel de detalle y capas
opcionales (lluvia, satélite). Debe funcionar con teselas locales/atenuadas y
degradar sin romper la página si no hay red.

**Aceptación:**
* `GET /?action=mapa` → HTTP 200.
* El mapa se inicializa con MapLibre **local** (`assets/vendor/maplibre-gl.js`).
* Al hacer clic en un distrito se abre su ficha sin recargar la página.

**Prueba:** abrir en navegador, comprobar consola sin errores de red locales y
que el clic navega a `?action=territorio&id=…`.

---

### RF-07 · Ficha de distrito

Muestra índice, nivel, desglose por amenaza, población/colegios/centros de salud
expuestos, recomendaciones y enlace al reporte.

**Aceptación:** `GET /?action=territorio&id=piu-catacaos` → HTTP 200 y contiene
el nombre del distrito, su nivel y el texto legal.

**Prueba:** `curl -s "…?action=territorio&id=piu-catacaos" | grep -c "apoyo a la decisión"` → ≥ 1.

---

### RF-07b · Alcance de la exposición redactado desde el dato

La home, la página de ayuda y el pie del informe **no** tienen escrito a mano
qué es oficial y qué es estimado: lo redactan a partir del overlay cargado
(`exposicion_texto_breve()`, `exposicion_texto_limite()`,
`exposicion_texto_corto()` en `models/Exposicion.php`).

**Aceptación:**

* Con el overlay del INEI aplicado, la home dice que la **población** es
  oficial y que viviendas, colegios y centros de salud **siguen siendo
  estimación**; el KPI de población muestra «oficial · INEI 2026».
* Sin overlay (`rm data/exposicion_oficial.json`), esos mismos textos vuelven
  a declarar que toda la exposición es una estimación de referencia.
* El informe imprimible declara lo mismo, campo por campo.

**Prueba:**

```bash
curl -s "http://localhost:8000/?action=home" | grep -o "oficial · INEI [0-9]*" | head -1
curl -s "http://localhost:8000/?action=ayuda" | grep -c "dato oficial en el campo de población"
```

---

### RF-08 · Recomendaciones y acuse

Las recomendaciones se generan desde el snapshot y siguen un protocolo por
amenaza y nivel. Solo autoridad/admin puede emitirlas; el acuse está abierto y
registra el nombre de la sesión (o "Autoridad local" si no hay sesión).

**Aceptación:**
* `GET /?action=alertas` lista las recomendaciones vigentes → HTTP 200.
* Emitir sin permiso redirige a `login=required` (302).
* Un acuse cambia el estado a "acusada".

**Prueba:**

```bash
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" \
  -d "action=emitir_alerta&territorio_id=piu-catacaos&nivel=naranja&amenaza=lluvia" \
  "http://localhost:8000/index.php"
# Esperado: 302 …?action=home&login=required
```

---

### RF-09 · Planificación territorial (ISE)

Evalúa un sitio (lat/lon) para un tipo de equipamiento y devuelve un ISE 0–100
con clase A/B/C/D y razones.

**Aceptación:**
* `GET /?action=api_evaluar&lat=-5.2667&lon=-80.6833&tipo=colegio` → HTTP 200.
* Devuelve `ise`, `clase` (A–D), `tipo_etiqueta` y explicación.
* Un hospital es más exigente que una vivienda para el mismo punto
  (`sensibilidad_equipamiento` 1.25 vs 0.80).

**Prueba:**

```bash
for t in colegio hospital vivienda; do
  curl -s "http://localhost:8000/?action=api_evaluar&lat=-5.2667&lon=-80.6833&tipo=$t" \
    | php -r '$d=json_decode(stream_get_contents(STDIN),true); printf("%s ISE=%.1f clase=%s\n",$d["tipo"],$d["ise"],$d["clase"]["id"]);'
done
```

---

### RF-10 · Simulador

Tres modos: **clima** (factor de lluvia y delta de temperatura), **evacuación**
(personas a evacuar y albergues) y **reubicación** (comparar sitio actual vs.
alternativo). Los dos primeros tienen API; reubicación es solo UI.

**Aceptación:**
* `GET /?action=simulador` → HTTP 200.
* `api_simular&modo=clima` devuelve `base.riesgo`, `simulado.riesgo`,
  `deltas.riesgo` y `parametros.factor_lluvia`; `modo=evacuacion` devuelve
  `resultado.poblacion_evacuar`, `capacidad_albergues` y `deficit`.
* Un factor de lluvia mayor no puede **bajar** el riesgo simulado.

**Prueba:**

```bash
for f in 1.0 1.5 2.0; do
  curl -s "http://localhost:8000/?action=api_simular&id=piu-catacaos&lluvia=$f&modo=clima" \
    | php -r '$d=json_decode(stream_get_contents(STDIN),true); printf("x%s base=%.1f simulado=%.1f\n",$d["parametros"]["factor_lluvia"],$d["base"]["riesgo"],$d["simulado"]["riesgo"]);'
done
```

---

### RF-11 · Reporte imprimible

Reporte por distrito o regional, en HTML imprimible (A4) sin navegación.

**Aceptación:** `GET /?action=reporte&id=piu-catacaos` → HTTP 200; al imprimir se
ocultan menú, botones y pie (regla `@media print`).

**Prueba:** abrir `?action=reporte&id=piu-catacaos` y usar "Vista previa de
impresión"; comprobar que no aparece la cabecera de navegación.

---

### RF-12 · Consola de administración

Muestra versión de PHP, extensiones requeridas y opcionales, directorios
escribibles, estado de los **tres** snapshots (24/48/72 h), caché, roles,
procedencia de exposición y cobertura de estaciones hidrometeorológicas.

**Aceptación (con sesión admin):**
* `GET /?action=admin` → HTTP 200.
* Aparecen `curl`, `json`, `mbstring` como requeridas y `pdo_pgsql` como
  opcional.
* Aparecen las tres ventanas con su distribución. La del snapshot regenerado
  el 7 de noviembre de 2026: 24 h → 9 muy alto · 8 alto · 5 medio · 7 bajo;
  48 h → 13/6/4/6; 72 h → 15/3/6/5. **Cambian con el clima**: lo que se
  verifica en la feria es que las tres ventanas aparecen con su fecha y su
  distribución, no una cifra fija.
* La sección «Cobertura de estaciones» muestra registro, series cargadas,
  distritos cubiertos y **separa** «criterios medibles» de «habilitadas fase 2»
  (hoy: 23 series cargadas, 4 cumplen los criterios medibles, 0 habilitadas).
* No aparecen `Warning`, `Notice` ni `Deprecated` en el HTML.

**Prueba:**

```bash
curl -s -b /tmp/cj.txt "http://localhost:8000/?action=admin" > /tmp/admin.html
grep -c "riesgo_nacional_{24,48,72}h.json" /tmp/admin.html
grep -q "Cobertura de estaciones" /tmp/admin.html && echo "estaciones OK"
! grep -qE "Warning|Notice|Deprecated|Fatal error" /tmp/admin.html && echo "sin avisos"
```

---

### RF-13 · Autenticación y roles

Login por correo/contraseña con `password_hash`, sesión y tres roles. Las
cuentas demo existen solo si no hay base de datos.

**Aceptación:**
* Login válido → 302 y sesión activa; login inválido → error sin filtrar si el
  correo existe.
* `admin@willaq.pe`, `defensa@willaq.pe`, `ciudadano@willaq.pe` funcionan con
  `willaq2026` en modo demostración.
* En producción se documenta cambiar la contraseña.

**Prueba:** §2.

---

### RF-14 · API pública

Cuatro endpoints JSON, sin autenticación, CORS abierto, documentados.

| Endpoint | Respuesta |
|---|---|
| `api_territorios` | Catálogo (29) + procedencia de exposición por campo |
| `api_riesgo[&id=]` | Snapshot nacional o recálculo en vivo de un distrito (incluye `exposicion`) |
| `api_simular` | Escenario clima / evacuación |
| `api_evaluar` | ISE de un punto |

**Aceptación:** los cuatro responden HTTP 200 con `Content-Type:
application/json; charset=utf-8`; un `id` inexistente responde 404 con
`{"error": …}`; faltar `lat`/`lon` responde 400.

**Prueba:** [`docs/API.md`](API.md) tiene los ejemplos ejecutables.

---

### RF-15 · Modo offline por ventana

Cada ventana (24/48/72 h) tiene su propio snapshot. El modo offline debe
responder con el snapshot de **la ventana pedida**, sin recalcular y sin red.

**Aceptación:**
* `WILLAQ_MODO_OFFLINE=1` + `?action=api_riesgo&ventana=24|48|72` devuelve el
  snapshot correspondiente y `generado_en` del archivo.
* Tiempo de respuesta **en caliente** < 50 ms (el primer acceso puede llegar a
  ~150 ms por el arranque de sesión; medido: 24 h ≈ 3–4 ms, 48 h ≈ 3–6 ms,
  72 h ≈ 3–4 ms).
* Si falta el snapshot de una ventana, se usa el archivo heredado
  `riesgo_nacional.json` **solo si** su `ventana` coincide; si no, se informa el
  faltante.

**Prueba:**

```bash
for v in 24 48 72; do
  curl -s -w " %{time_total}s\n" "http://localhost:8001/?action=api_riesgo&ventana=$v" \
    | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo $d["ventana"]," ",count($d["riesgos"])," distritos ";'
done
```

---

## 4. Requisitos no funcionales

| ID | Requisito | Criterio de aceptación | Verificación |
|---|---|---|---|
| **RNF-01** | PHP puro, sin Composer | El proyecto corre con `php -S` sin `vendor/` ni `composer.json` | `test ! -f composer.json` |
| **RNF-02** | PHP puro en el runtime web | La aplicación web no requiere Python/Node. La ingesta de estaciones (Garúa + generador de registro) es **offline**, corre antes de desplegar y nunca desde el servidor web | `grep -R "python\|node " scripts models views` revisado: solo scripts de ingesta |
| **RNF-03** | Persistencia sin base de datos | Caché JSON + snapshots; PostgreSQL opcional con fallback | Apagar PostgreSQL y repetir RF-01/RF-15 |
| **RNF-04** | Rendimiento offline | Cada vista/snapshot en caliente < 50 ms (primer acceso < 150 ms) | `curl -w %{time_total}` |
| **RNF-05** | Seguridad básica | `password_hash`, sesión, `.htaccess` niega `config/`, `data/`, `models/`, `scripts/`, `views/`, `database/`; `.env` no accesible | `curl -i /.env` → 403/404 |
| **RNF-06** | Lenguaje legal | Toda pantalla con riesgo dice "apoyo a la decisión" y "no alerta oficial" | `grep -R "apoyo a la decisión" views` |
| **RNF-07** | Accesibilidad | Contraste AA, foco visible, color no único canal, movimiento reducido | [`docs/MANUAL_DISENO.md`](MANUAL_DISENO.md) §2.3 y §9 |
| **RNF-08** | Offline de assets | Fuente, Tailwind, MapLibre y Chart.js locales | `ls assets/vendor assets/fonts` |
| **RNF-09** | Portabilidad | Funciona en subdirectorio y en hosting compartido | [`docs/MANUAL_INSTALACION.md`](MANUAL_INSTALACION.md) §3 |
| **RNF-10** | Honestidad del dato | Exposición: el total puede ser oficial, la fracción expuesta sigue marcada como estimación; estaciones: `cumple_medible` ≠ `habilitada`; sin precisión/ROC-AUC inventados | `meta.fuente_exposicion`, bloque `exposicion`, panel admin §estaciones y §7 de [`COMPARATIVA.md`](COMPARATIVA.md) |
| **RNF-11** | Reproducibilidad | Los scripts de auditoría escriben JSON y se pueden re-ejecutar | `php scripts/validar_historico.php`, `php scripts/sensibilidad.php` |

---

## 5. Matriz de trazabilidad

| Criterio del concurso | Requisitos | Evidencia |
|---|---|---|
| Algoritmos claros y consistentes | RF-03, RF-04, RF-05 | `models/Riesgo.php`, [`METODOLOGIA.md`](METODOLOGIA.md) |
| Implementación corresponde al diseño | RF-01…RF-15 | [`ARQUITECTURA.md`](ARQUITECTURA.md) + este documento |
| Documentación completa | RNF-11 | 11 documentos (README + 10 en `docs/`) + `verificar_enlaces.php` (138 enlaces, 0 rotos) |
| Interfaces claras | RF-01, RF-06, RF-07, RF-12 | [`MANUAL_DISENO.md`](MANUAL_DISENO.md), [`MANUAL_USUARIO.md`](MANUAL_USUARIO.md) |
| Funcionamiento según especificaciones | Todos | Pruebas de §3 y §4 |
| Resultados consistentes | RF-03, RF-15 | [`VALIDACION_HISTORICA.md`](VALIDACION_HISTORICA.md), `data/sensibilidad.json` |
| Herramienta nueva / mejora | RF-09, RF-10 | [`COMPARATIVA.md`](COMPARATIVA.md) |
| Alcance internacional | — | **Parcial**: el catálogo es multi-país por formato, falta selector |

---

## 6. Fuera de alcance (explícito)

1. **No** se emiten alertas oficiales. La responsabilidad legal es de SENAMHI,
   ENFEN e INDECI.
2. **No** hay predicción meteorológica propia: se consume Open-Meteo/ERA5.
3. **No** hay modelo de machine learning entrenado ni métricas de precisión o
   ROC-AUC, porque no existe una etiqueta oficial de "evento" por distrito.
4. **No** hay cobertura nacional: 29 distritos piloto en Tumbes, Piura y Lima,
   más sierras de sequía.
5. **No** hay app móvil nativa ni multi-idioma.
6. **No** hay reubicación expuesta por API (solo en la interfaz), porque requiere
   un sitio alternativo provisto por el usuario.
7. **No** hay fase 2 de estaciones en el motor: el motor de riesgo **no** usa
   las series del SENAMHI (`motor_usa_estaciones = false`). La ingesta existe
   y está auditada, y la medición de falsos positivos **ya se ejecutó**: no
   añadió ningún señalamiento fuera de la referencia documentada, pero el
   recall de la estación (0.7333) es igual al de ERA5 y **0 estaciones**
   alcanzaron el recall mínimo de 0.80. La fusión queda cerrada por resultado,
   no por falta de trabajo.
8. **No** hay nowcast ni tiempo real de estaciones. Garúa se usa solo para
   descargar series históricas; no hay scraping en runtime ni API operativa.

---

## 7. Cómo ejecutar la aceptación completa

```bash
cd willaq

# 1. Sintaxis de todo el PHP
find . -name '*.php' -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors" || echo "PHP OK"

# 2. Servidores (dos terminales)
php -S localhost:8000
WILLAQ_MODO_OFFLINE=1 php -S localhost:8001

# 3. Humo de vistas y API
for a in home mapa alertas planificacion simulador ayuda admin; do
  printf "%-14s %s\n" "$a" "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:8000/?action=$a")"
done

# 4. Auditorías
php scripts/validar_historico.php
php scripts/sensibilidad.php --verificar
php scripts/verificar_enlaces.php

# 5. Resultados esperados
#    Validación: 13/18 (72 %), 13/13 con señal clara, 0 fallas del modelo
#    Sensibilidad: Spearman mín. 0.9286, Jaccard mín. 0.667, diferencia con el motor = 0
#    Enlaces: 90 revisados, 0 rotos
```

**Regla de honestidad:** si una prueba falla, el requisito queda **no
cumplido** y se documenta. No se cambia el criterio para que pase.

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
