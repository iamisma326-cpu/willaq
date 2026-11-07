#!/usr/bin/env python3
"""
GENERADOR DEL REGISTRO DE ESTACIONES — WILLAQ
==============================================

QUÉ HACE
    Lee el catálogo de estaciones del SENAMHI (snapshot de Garúa en
    data/estaciones/catalogo_senamhi.json) y el catálogo de territorios WILLAQ
    (config/territorios.json), y escribe config/estaciones.json: el registro de
    estaciones que la fase 1 usa para saber qué existe, de dónde salió y con
    qué estado de verificación.

POR QUÉ EXISTE UN GENERADOR Y NO UN JSON ESCRITO A MANO
    El registro se deriva de dos fuentes externas (el catálogo del SENAMHI y los
    29 distritos de WILLAQ). Si se escribiera a mano, cada estación sería una
    afirmación sin trazabilidad. Generándolo, cada línea del registro sabe de
    qué fila del catálogo salió y con qué regla se asignó.

REGLAS DE ASIGNACIÓN (las mismas del plan, docs/PLAN_EXPOSICION_Y_ESTACIONES.md)
    1. Radio:      la estación debe estar a ≤ 25 km del centroide del distrito.
    2. Altitud:   |alt_estación − alt_distrito| ≤ 500 m.
    3. Tipo:       se prefiere 'M' (meteorológica) porque la fase 1 usa
                   precipitación y temperatura; 'H' (hidrológica) se acepta como
                   candidata secundaria (caudal/nivel de río).
    4. Vigencia:   se prefiere catalog_status='listed' sobre 'absent'.
                   'absent' NO significa baja oficial: solo que no figuraba en
                   el listado consultado en esa fecha.
    5. Serie:      se prefiere la fecha de inicio más antigua (data_since).
    6. Desempate:  menor distancia.

    Si un distrito no tiene ninguna estación dentro del radio, queda SIN
    ASIGNAR y con la estación más cercana como referencia. No se inventa
    cobertura ni se relaja el radio en silencio: la excepción queda visible.

QUÉ NO HACE
    · No descarga series. Eso es scripts/descargar_estaciones_garua.sh.
    · No marca ninguna estación como verificada por el SENAMHI en persona:
      `verificado` refleja el estado del catálogo, y `serie_cargada` refleja si
      hay CSV en disco. Ninguna de las dos cosas equivale a "dato de campo".

USO
    python3 scripts/generar_registro_estaciones.py [--dry-run]
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import sys
import unicodedata
from datetime import date, datetime, timezone
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
CATALOGO = RAIZ / "data" / "estaciones" / "catalogo_senamhi.json"
TERRITORIOS = RAIZ / "config" / "territorios.json"
SALIDA = RAIZ / "config" / "estaciones.json"

RADIO_KM = 25.0
DIF_ALTITUD_M = 500.0

# Estaciones que el plan nombra explícitamente como referencia operativa
# (decisión 1: "SENAMHI (PISCO) + ANA"). Pisco no es un distrito del piloto,
# pero su estación es la referencia de la decisión documentada.
REFERENCIAS_EXTERNAS = {
    "100046": {
        "motivo": "Estación de referencia de la decisión «SENAMHI (PISCO) + ANA».",
        "territorio": None,
    },
    "113009": {
        "motivo": "Estación de referencia de la decisión «SENAMHI (PISCO) + ANA».",
        "territorio": None,
    },
}

# Departamentos donde hay territorios del piloto, más Ica (referencia Pisco) y
# la costa norte completa. Se calculan a partir del propio catálogo de
# territorios para que no quede ningún distrito fuera por una lista escrita a
# mano: si el piloto crece, esta lista sigue siendo correcta.
def _departamentos_piloto(territorios: list[dict]) -> set[str]:
    deps = {norm(t.get("region")) for t in territorios}
    # Costa norte e Ica, para poder tomar estaciones de referencia aunque el
    # distrito no esté en la lista del piloto.
    deps |= {"TUMBES", "PIURA", "LAMBAYEQUE", "LA LIBERTAD", "ANCASH", "ICA"}
    return deps


def norm(s: str | None) -> str:
    """Normaliza un nombre para comparar: sin tildes, en mayúsculas, sin dobles espacios."""
    s = (s or "").upper().strip()
    s = unicodedata.normalize("NFD", s)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    return " ".join(s.split())


def haversine(lat1: float, lon1: float, lat2: float, lon2: float) -> float:
    """Distancia en km entre dos puntos (fórmula del haversina)."""
    r = 6371.0
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp = math.radians(lat2 - lat1)
    dl = math.radians(lon2 - lon1)
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * r * math.asin(math.sqrt(a))


def sha256_archivo(ruta: Path) -> str:
    h = hashlib.sha256()
    with ruta.open("rb") as f:
        for bloque in iter(lambda: f.read(65536), b""):
            h.update(bloque)
    return h.hexdigest()


def puntua_estacion(dist_km: float, dif_alt: float, est: dict) -> tuple:
    """Puntaje de prioridad (menor = mejor). Ver reglas 3, 4 y 5 del docstring."""
    tipo = est.get("ico")
    vigente = est.get("catalog_status") == "listed"
    since = est.get("data_since") or "9999-99"

    penal_tipo = 0 if tipo == "M" else (1 if tipo == "H" else 2)
    penal_vigencia = 0 if vigente else 1
    return (penal_tipo, penal_vigencia, since, dist_km)


# Distritos que el backtest histórico (data/validacion_historica.json) marca
# como NO DETECTADOS. Son el objetivo concreto de la fase 2: si una estación
# de referencia cae en uno de estos distritos y su serie cubre el año del
# evento, es la que puede recuperar la detección.
DISTRITOS_NO_DETECTADOS = {
    "piu-piura",         # evento 2017-18, evento_debil_en_datos
    "piu-catacaos",      # evento 2017-18, evento_ausente_en_datos
    "piu-tambogrande",   # evento 2017-18, evento_ausente_en_datos
    "piu-sullana",       # evento 2017-18, evento_ausente_en_datos
    "tum-zarumilla",     # evento 2023, evento_debil_en_datos
}

# Años de los eventos históricos que hay que poder cubrir.
ANIOS_EVENTO = {2017, 2018, 2023}


def prioridad_de(cod: str, asignaciones: list[dict], datos_desde: str) -> str:
    """Clasifica la estación por su valor para la fase 2.

    · critica: cubre un distrito no detectado Y su serie alcanza el año del
      evento (2017-18 o 2023). Es lo que puede cambiar un resultado del
      backtest, así que se descarga primero.
    · alta:    cubre un distrito no detectado, pero su serie empieza tarde.
    · media:   referencia de la decisión «SENAMHI (PISCO) + ANA» o estación
      meteorológica de la costa norte con serie larga.
    · baja:    el resto del catálogo.
    """
    desde_año = int(datos_desde[:4]) if datos_desde[:4].isdigit() else 9999
    cubre_no_detectado = any(a.get("territorio") in DISTRITOS_NO_DETECTADOS for a in asignaciones)

    if cubre_no_detectado and desde_año <= min(ANIOS_EVENTO):
        return "critica"
    if cubre_no_detectado:
        return "alta"
    if datos_desde[:4].isdigit() and desde_año <= 2017:
        return "media"
    return "baja"


def id_estacion(est: dict) -> str:
    """Identificador estable y legible: p.ej. senamhi-105063-san-miguel."""
    cod = str(est.get("cod", "")).strip()
    nombre = norm(est.get("nom")).lower().replace(" ", "-")
    nombre = "".join(c for c in nombre if c.isalnum() or c == "-")
    return f"senamhi-{cod}-{nombre}"


def _estado_serie(reg: dict) -> None:
    """Deriva `serie_cargada` y sus metadatos del archivo real en disco.

    El registro no es la fuente de verdad de la serie: el archivo
    data/estaciones/{id}.json lo es. Así, regenerar el registro no borra el
    hecho de que una serie ya fue importada, y no se puede declarar cargada
    una serie que no existe.
    """
    ruta = RAIZ / "data" / "estaciones" / f"{reg['id']}.json"
    reg["serie_cargada"] = ruta.is_file()
    if not reg["serie_cargada"]:
        return
    try:
        serie = json.loads(ruta.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        reg["serie_cargada"] = False
        return
    qa = serie.get("qa") or {}
    reg["serie_archivo"] = str(ruta.relative_to(RAIZ))
    reg["serie_actualizada_en"] = (serie.get("_meta") or {}).get("generado_en", "")
    reg["serie_primera_fecha"] = (serie.get("rango") or {}).get("primera")
    reg["serie_ultima_fecha"] = (serie.get("rango") or {}).get("ultima")
    reg["serie_dias_con_dato"] = qa.get("dias_con_dato")
    reg["serie_pct_faltantes"] = qa.get("pct_dias_faltantes")
    reg["serie_anos_completos"] = qa.get("anos_completos")
    reg["serie_sha256_origen"] = (
        ((serie.get("_meta") or {}).get("archivos_origen") or [{}])[0].get("sha256", "")
    )


def main() -> int:
    ap = argparse.ArgumentParser(description="Genera config/estaciones.json")
    ap.add_argument("--dry-run", action="store_true", help="No escribe el archivo")
    args = ap.parse_args()

    if not CATALOGO.exists():
        print(f"ERROR: no existe el catálogo {CATALOGO}", file=sys.stderr)
        return 1
    if not TERRITORIOS.exists():
        print(f"ERROR: no existe {TERRITORIOS}", file=sys.stderr)
        return 1

    catalogo = json.loads(CATALOGO.read_text(encoding="utf-8"))
    terr = json.loads(TERRITORIOS.read_text(encoding="utf-8"))
    territorios = terr.get("territorios", [])

    # ------------------------------------------------------------------
    # Candidatos por distrito, según las reglas del plan.
    # ------------------------------------------------------------------
    deps_piloto = _departamentos_piloto(territorios)
    asignaciones: dict[str, list[dict]] = {}
    sin_asignar: list[dict] = []

    for t in territorios:
        cands = []
        for e in catalogo:
            if norm(e.get("dep")) not in deps_piloto:
                continue
            d = haversine(float(t["lat"]), float(t["lon"]), float(e["lat"]), float(e["lon"]))
            if d > RADIO_KM:
                continue
            dif_alt = abs(float(e.get("alt") or 0) - float(t.get("altitud") or 0))
            if dif_alt > DIF_ALTITUD_M:
                continue
            cands.append((d, dif_alt, e))
        cands.sort(key=lambda x: puntua_estacion(x[0], x[1], x[2]))
        if cands:
            asignaciones[t["id"]] = cands
        else:
            sin_asignar.append(t)

    # ------------------------------------------------------------------
    # Estaciones efectivamente registradas (deduplicadas por código).
    # ------------------------------------------------------------------
    registradas: dict[str, dict] = {}
    cobertura: dict[str, list[str]] = {}

    for tid, cands in asignaciones.items():
        cobertura[tid] = []
        for dist_km, dif_alt, e in cands:
            cod = str(e["cod"])
            sid = id_estacion(e)
            if cod not in registradas:
                registradas[cod] = {
                    "id": sid,
                    "cod": cod,
                    "cod_old": e.get("cod_old") or "",
                    "nombre": e.get("nom") or "",
                    "tipo": e.get("ico") or "",
                    "categoria": e.get("cate") or "",
                    "lat": e.get("lat"),
                    "lon": e.get("lon"),
                    "altitud_msnm": e.get("alt"),
                    "departamento": e.get("dep") or "",
                    "provincia": e.get("prov") or "",
                    "distrito_origen": e.get("dist") or "",
                    "estado_operativo": e.get("estado") or "",
                    "datos_desde": e.get("data_since") or "",
                    "catalog_status": e.get("catalog_status") or "",
                    "catalog_checked_at": e.get("catalog_checked_at") or "",
                    "availability_status": e.get("availability_status") or "",
                    "availability_checked_at": e.get("availability_checked_at") or "",
                    "nota_catalogo": e.get("note") or "",
                    "fuente_catalogo": "SENAMHI (catálogo de estaciones vía Garúa)",
                    "licencia_catalogo": "Por confirmar: el SENAMHI no publica licencia de reutilización conocida",
                    "serie_cargada": False,
                    "verificado": False,
                    "asignaciones": [],
                }
            reg = registradas[cod]
            reg["asignaciones"].append(
                {
                    "territorio": tid,
                    "distrito": next(x["distrito"] for x in territorios if x["id"] == tid),
                    "distancia_km": round(dist_km, 2),
                    "diferencia_altitud_m": int(dif_alt),
                    "metodo": "radio_25km_altitud_500m",
                }
            )
            cobertura[tid].append(cod)

    # Referencias externas (Pisco), si existen en el catálogo.
    for cod, meta in REFERENCIAS_EXTERNAS.items():
        e = next((x for x in catalogo if str(x.get("cod")) == cod), None)
        if not e:
            continue
        if cod not in registradas:
            registradas[cod] = {
                "id": id_estacion(e),
                "cod": cod,
                "cod_old": e.get("cod_old") or "",
                "nombre": e.get("nom") or "",
                "tipo": e.get("ico") or "",
                "categoria": e.get("cate") or "",
                "lat": e.get("lat"),
                "lon": e.get("lon"),
                "altitud_msnm": e.get("alt"),
                "departamento": e.get("dep") or "",
                "provincia": e.get("prov") or "",
                "distrito_origen": e.get("dist") or "",
                "estado_operativo": e.get("estado") or "",
                "datos_desde": e.get("data_since") or "",
                "catalog_status": e.get("catalog_status") or "",
                "catalog_checked_at": e.get("catalog_checked_at") or "",
                "availability_status": e.get("availability_status") or "",
                "availability_checked_at": e.get("availability_checked_at") or "",
                "nota_catalogo": e.get("note") or "",
                "fuente_catalogo": "SENAMHI (catálogo de estaciones vía Garúa)",
                "licencia_catalogo": "Por confirmar: el SENAMHI no publica licencia de reutilización conocida",
                "serie_cargada": False,
                "verificado": False,
                "asignaciones": [],
            }
        registradas[cod]["asignaciones"].append(
            {
                "territorio": meta["territorio"],
                "distrito": None,
                "distancia_km": None,
                "diferencia_altitud_m": None,
                "metodo": "referencia_documentada",
                "motivo": meta["motivo"],
            }
        )

    # ------------------------------------------------------------------
    # Documenento de salida.
    # ------------------------------------------------------------------
    ahora = datetime.now(timezone.utc).isoformat(timespec="seconds")
    catalogo_hash = sha256_archivo(CATALOGO)

    # ------------------------------------------------------------------
    # Prioridad de descarga: qué estaciones pueden mover el backtest.
    # ------------------------------------------------------------------
    for cod, reg in registradas.items():
        reg["prioridad"] = prioridad_de(cod, reg["asignaciones"], reg["datos_desde"])
        _estado_serie(reg)

    estaciones = sorted(
        registradas.values(),
        key=lambda e: (
            {"critica": 0, "alta": 1, "media": 2, "baja": 3}[e["prioridad"]],
            e["datos_desde"] or "9999",
            e["cod"],
        ),
    )

    salida = {
        "_meta": {
            "descripcion": (
                "Registro de estaciones hidrometeorológicas del SENAMHI para la fase 1 de WILLAQ. "
                "Cada estación conserva su procedencia (catálogo, código, fecha de catálogo) y su "
                "estado de verificación. Las SERIES se cargan aparte con Garúa y se normalizan en "
                "data/estaciones/{id}.json."
            ),
            "version": "1.0",
            "generado_en": ahora,
            "generado_por": "scripts/generar_registro_estaciones.py",
            "fuente_catalogo": "SENAMHI — catálogo de estaciones, descargado con Garúa (https://github.com/danyneyra/garua)",
            "catalogo_archivo": "data/estaciones/catalogo_senamhi.json",
            "catalogo_sha256": catalogo_hash,
            "catalogo_consultado_en": (
                max((e.get("catalog_checked_at") or "" for e in catalogo), default="") or None
            ),
            "reglas_asignacion": {
                "radio_max_km": RADIO_KM,
                "diferencia_altitud_max_m": DIF_ALTITUD_M,
                "preferencia_tipo": "M (meteorológica) > H (hidrológica)",
                "preferencia_vigencia": "catalog_status=listed > absent",
                "preferencia_serie": "data_since más antiguo",
            },
            "honestidad": (
                "«verificado» significa que el registro coincide con el catálogo consultado; "
                "NO que el dato haya sido verificado en campo por el SENAMHI ni que la serie esté "
                "descargada. «serie_cargada» se pone en true solo cuando existe un CSV de origen "
                "procesado por el importador."
            ),
            "estado_fase": (
                "Fase 1 (registro, ingesta y QA). La fase 2 (climatología y fusión con el motor) "
                "sigue BLOQUEADA: requiere series con ≥10 años, <10% de días faltantes y medición de "
                "falsos positivos. Ninguna estación cambia los números del motor mientras el flag "
                "esté apagado."
            ),
            "licencia_datos": (
                "El SENAMHI no publica hasta la fecha una licencia de reutilización conocida para su "
                "catálogo y sus series. Se cita la fuente y no se redistribuyen como si fueran de "
                "dominio público."
            ),
            "cobertura": {
                "distritos_con_estacion": sorted(cobertura.keys()),
                "distritos_sin_estacion": sorted(t["id"] for t in sin_asignar),
                "total_distritos": len(territorios),
            },
        },
        "estaciones": estaciones,
    }

    # ------------------------------------------------------------------
    # Reporte en consola.
    # ------------------------------------------------------------------
    print(f"Catálogo: {CATALOGO.relative_to(RAIZ)} ({len(catalogo)} estaciones)")
    print(f"sha256:   {catalogo_hash}")
    print(f"Territorios WILLAQ: {len(territorios)}")
    print(f"Estaciones registradas: {len(estaciones)}")
    print(f"Distritos con estación dentro de radio: {len(cobertura)}")
    if sin_asignar:
        print(f"Distritos SIN estación dentro de radio ({len(sin_asignar)}):")
        for t in sin_asignar:
            print(f"  · {t['id']} ({t['distrito']}, {t['region']})")
    print()
    print("Prioridad de descarga:")
    for nivel in ("critica", "alta", "media", "baja"):
        sel = [e for e in estaciones if e["prioridad"] == nivel]
        print(f"  {nivel:<8} {len(sel):>3} estación(es)")
    print()
    print("Estaciones críticas (primera descarga):")
    for e in estaciones:
        if e["prioridad"] != "critica":
            continue
        tids = sorted({a["territorio"] for a in e["asignaciones"] if a.get("territorio")})
        print(
            f"  {e['cod']} {e['nombre'][:20]:<20} {e['tipo']} desde {e['datos_desde']} "
            f"→ {', '.join(tids)}"
        )
    print()
    print("Cobertura por distrito:")
    for tid in sorted(cobertura):
        t = next(x for x in territorios if x["id"] == tid)
        cods = cobertura[tid]
        detalle = []
        for cod in cods[:2]:
            reg = registradas[cod]
            a = next((a for a in reg["asignaciones"] if a["territorio"] == tid), None)
            d = f"{a['distancia_km']}km" if a and a["distancia_km"] is not None else "ref"
            detalle.append(f"{cod} {reg['nombre'][:16]}({reg['tipo']},{d},desde {reg['datos_desde']})")
        extra = f" +{len(cods) - 2}" if len(cods) > 2 else ""
        print(f"  {tid:<20} {t['distrito'][:18]:<18} {' | '.join(detalle)}{extra}")

    if args.dry_run:
        print("\n--dry-run: no se escribió el archivo.")
        return 0

    SALIDA.write_text(
        json.dumps(salida, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    print(f"\nEscrito: {SALIDA.relative_to(RAIZ)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
