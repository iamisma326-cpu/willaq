#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Genera el catálogo nacional de territorios de WILLAQ: 24 departamentos y sus
distritos más relevantes (capitales provinciales y distritos expuestos).

Escribe el SQL de inserción y actualiza config/territorios.json para que el
respaldo offline quede sincronizado con la base de datos.

Uso: python3 scripts/generar_territorios.py
"""
import json
import os

# (departamento, ubigeo_depto, [(distrito, provincia, ubigeo, lat, lon, altitud, zona, poblacion)])
# Coordenadas de capitales provinciales (WGS84) y población aproximada (INEI).
CATALOGO = {
    "Amazonas": ("01", [
        ("Chachapoyas", "Chachapoyas", "010101", -6.2317, -77.8690, 2335, "sierra", 32000),
        ("Bagua Grande", "Utcubamba", "010701", -5.7556, -78.4425, 450, "selva", 45000),
        ("Bagua", "Bagua", "010201", -5.6389, -78.5303, 420, "selva", 28000),
        ("Luya", "Luya", "010501", -6.1639, -77.9442, 2050, "sierra", 12000),
    ]),
    "Áncash": ("02", [
        ("Huaraz", "Huaraz", "020101", -9.5278, -77.5278, 3052, "sierra", 120000),
        ("Chimbote", "Santa", "021801", -9.0853, -78.5783, 5, "costa_norte", 380000),
        ("Caraz", "Huaylas", "021201", -9.0472, -77.8103, 2285, "sierra", 25000),
        ("Yungay", "Yungay", "022001", -9.1389, -77.7444, 2458, "sierra", 22000),
    ]),
    "Apurímac": ("03", [
        ("Abancay", "Abancay", "030101", -13.6339, -72.8814, 2377, "sierra", 72000),
        ("Andahuaylas", "Andahuaylas", "030201", -13.6553, -73.3869, 2926, "sierra", 65000),
        ("Chincheros", "Chincheros", "030601", -13.5183, -73.7231, 2800, "sierra", 15000),
    ]),
    "Arequipa": ("04", [
        ("Arequipa", "Arequipa", "040101", -16.4090, -71.5375, 2335, "sierra", 1000000),
        ("Camaná", "Camaná", "040201", -16.6239, -72.7103, 12, "costa_centro", 18000),
        ("Mollendo", "Islay", "040701", -17.0297, -72.0147, 26, "costa_centro", 30000),
        ("Chala", "Caravelí", "040301", -15.8667, -74.2333, 15, "costa_centro", 12000),
    ]),
    "Ayacucho": ("05", [
        ("Ayacucho", "Huamanga", "050101", -13.1588, -74.2239, 2761, "sierra", 180000),
        ("Huanta", "Huanta", "050401", -12.9397, -74.2489, 2628, "sierra", 40000),
        ("Puquio", "Lucanas", "050601", -14.6942, -74.1244, 3200, "sierra", 15000),
    ]),
    "Cajamarca": ("06", [
        ("Cajamarca", "Cajamarca", "060101", -7.1638, -78.5003, 2750, "sierra", 220000),
        ("Jaén", "Jaén", "060801", -5.7089, -78.8078, 729, "selva", 85000),
        ("Chota", "Chota", "060401", -6.5603, -78.6489, 2388, "sierra", 45000),
        ("Cutervo", "Cutervo", "060501", -6.3778, -78.8167, 2626, "sierra", 40000),
    ]),
    "Callao": ("07", [
        ("Callao", "Callao", "070101", -12.0566, -77.1181, 5, "costa_centro", 450000),
        ("Ventanilla", "Callao", "070106", -11.8753, -77.1264, 71, "costa_centro", 380000),
        ("Bellavista", "Callao", "070102", -12.0625, -77.1247, 12, "costa_centro", 75000),
    ]),
    "Cusco": ("08", [
        ("Cusco", "Cusco", "080101", -13.5319, -71.9675, 3399, "sierra", 130000),
        ("Sicuani", "Canchis", "080801", -14.2694, -71.2261, 3550, "sierra", 60000),
        ("Espinar", "Espinar", "080901", -14.7906, -71.4108, 3930, "sierra", 32000),
        ("Quillabamba", "La Convención", "080902", -12.8731, -72.6931, 1050, "selva", 45000),
    ]),
    "Huancavelica": ("09", [
        ("Huancavelica", "Huancavelica", "090101", -12.7864, -74.9764, 3676, "sierra", 40000),
        ("Pampas", "Tayacaja", "090701", -12.3989, -74.8678, 3276, "sierra", 15000),
    ]),
    "Huánuco": ("10", [
        ("Huánuco", "Huánuco", "100101", -9.9300, -76.2397, 1894, "sierra", 180000),
        ("Tingo María", "Leoncio Prado", "100601", -9.2953, -75.9944, 660, "selva", 60000),
        ("La Unión", "Dos de Mayo", "100301", -9.8281, -76.8019, 3200, "sierra", 18000),
        ("Ambo", "Ambo", "100201", -10.1308, -76.2047, 2064, "sierra", 20000),
    ]),
    "Ica": ("11", [
        ("Ica", "Ica", "110101", -14.0678, -75.7286, 406, "costa_centro", 300000),
        ("Chincha Alta", "Chincha", "110201", -13.4167, -76.1333, 97, "costa_centro", 190000),
        ("Pisco", "Pisco", "110501", -13.7100, -76.2019, 27, "costa_centro", 140000),
        ("Nasca", "Nasca", "110301", -14.8333, -74.9333, 588, "costa_centro", 28000),
    ]),
    "Junín": ("12", [
        ("Huancayo", "Huancayo", "120101", -12.0651, -75.2049, 3259, "sierra", 380000),
        ("Tarma", "Tarma", "120701", -11.4197, -75.6906, 3058, "sierra", 45000),
        ("La Merced", "Chanchamayo", "120301", -11.0531, -75.3319, 751, "selva", 28000),
        ("Satipo", "Satipo", "120601", -11.2539, -74.6378, 632, "selva", 35000),
    ]),
    "La Libertad": ("13", [
        ("Trujillo", "Trujillo", "130101", -8.11599, -79.02998, 34, "costa_norte", 800000),
        ("Pacasmayo", "Pacasmayo", "130701", -7.4006, -79.5711, 20, "costa_norte", 28000),
        ("Santiago de Chuco", "Santiago de Chuco", "131001", -8.1461, -78.1736, 3120, "sierra", 18000),
        ("Otuzco", "Otuzco", "130501", -7.9019, -78.5658, 2641, "sierra", 25000),
    ]),
    "Lambayeque": ("14", [
        ("Chiclayo", "Chiclayo", "140101", -6.7714, -79.8409, 27, "costa_norte", 600000),
        ("Lambayeque", "Lambayeque", "140301", -6.7011, -79.9064, 18, "costa_norte", 75000),
        ("Ferreñafe", "Ferreñafe", "140201", -6.6392, -79.7889, 42, "costa_norte", 40000),
    ]),
    "Lima": ("15", [
        ("Lima Cercado", "Lima", "150101", -12.0464, -77.0428, 154, "costa_centro", 300000),
        ("Ate", "Lima", "150103", -12.0333, -76.9167, 355, "costa_centro", 600000),
        ("San Juan de Lurigancho", "Lima", "150132", -11.9833, -76.9833, 200, "costa_centro", 1100000),
        ("Chosica", "Lima", "150118", -11.9333, -76.7000, 861, "quebrada_andina", 180000),
        ("Santa Eulalia", "Huarochirí", "150728", -11.8833, -76.6667, 1030, "quebrada_andina", 12000),
        ("San Mateo", "Huarochirí", "150722", -11.7500, -76.3000, 3150, "sierra", 6000),
        ("Chaclacayo", "Lima", "150107", -11.9667, -76.7667, 850, "quebrada_andina", 45000),
        ("Cieneguilla", "Lima", "150109", -12.1167, -76.8000, 300, "costa_centro", 40000),
        ("Villa El Salvador", "Lima", "150142", -12.2167, -76.9333, 145, "costa_centro", 400000),
        ("Lurín", "Lima", "150119", -12.2667, -76.8667, 30, "costa_centro", 90000),
        ("Huaral", "Huaral", "150601", -11.4953, -77.2075, 188, "costa_centro", 100000),
        ("Cañete", "Cañete", "150501", -13.0789, -76.3889, 145, "costa_centro", 50000),
        ("Huarochirí", "Huarochirí", "150701", -12.1333, -76.4667, 2400, "sierra", 15000),
        ("Barranca", "Barranca", "150201", -10.7500, -77.7667, 49, "costa_centro", 65000),
    ]),
    "Loreto": ("16", [
        ("Iquitos", "Maynas", "160101", -3.7491, -73.2538, 106, "selva", 450000),
        ("Yurimaguas", "Alto Amazonas", "160201", -5.9000, -76.0833, 130, "selva", 70000),
        ("Requena", "Requena", "160501", -5.0667, -73.8500, 114, "selva", 25000),
        ("Nauta", "Loreto", "160301", -4.5000, -73.5833, 111, "selva", 25000),
    ]),
    "Madre de Dios": ("17", [
        ("Puerto Maldonado", "Tambopata", "170101", -12.5933, -69.1897, 183, "selva", 85000),
        ("Iberia", "Tahuamanu", "170301", -11.4167, -69.4833, 260, "selva", 12000),
    ]),
    "Moquegua": ("18", [
        ("Moquegua", "Mariscal Nieto", "180101", -17.1939, -70.9336, 1410, "costa_centro", 70000),
        ("Ilo", "Ilo", "180201", -17.6394, -71.3394, 15, "costa_centro", 65000),
    ]),
    "Pas": ("19", []),
    "Pasco": ("19", [
        ("Cerro de Pasco", "Pasco", "190101", -10.6856, -76.2625, 4380, "sierra", 70000),
        ("Oxapampa", "Oxapampa", "190301", -10.5764, -75.4050, 1814, "selva", 25000),
    ]),
    "Piura": ("20", [
        ("Piura", "Piura", "200101", -5.1945, -80.6328, 29, "costa_norte", 550000),
        ("Castilla", "Piura", "200104", -5.2000, -80.6333, 30, "costa_norte", 200000),
        ("Catacaos", "Piura", "200105", -5.2667, -80.6833, 19, "costa_norte", 100000),
        ("Veintiséis de Octubre", "Piura", "200115", -5.1833, -80.6667, 40, "costa_norte", 180000),
        ("Chulucanas", "Morropón", "200401", -5.0967, -80.1617, 92, "costa_norte", 80000),
        ("Tambogrande", "Piura", "200114", -4.9333, -80.1500, 74, "costa_norte", 60000),
        ("Paita", "Paita", "200501", -5.0892, -81.1144, 15, "costa_norte", 90000),
        ("Sullana", "Sullana", "200601", -4.9039, -80.6856, 65, "costa_norte", 220000),
        ("Talara", "Talara", "200701", -4.5772, -81.2719, 15, "costa_norte", 100000),
        ("Ayabaca", "Ayabaca", "200201", -4.6400, -79.7200, 2720, "sierra", 40000),
        ("Huancabamba", "Huancabamba", "200301", -5.2389, -79.4508, 1929, "sierra", 35000),
    ]),
    "Puno": ("21", [
        ("Puno", "Puno", "210101", -15.8402, -70.0219, 3827, "sierra", 150000),
        ("Juliaca", "San Román", "211101", -15.5000, -70.1333, 3825, "sierra", 300000),
        ("Ilave", "El Collao", "210401", -16.0833, -69.6667, 3850, "sierra", 60000),
        ("Azángaro", "Azángaro", "210201", -14.9167, -70.2000, 3865, "sierra", 35000),
    ]),
    "San Martín": ("22", [
        ("Moyobamba", "Moyobamba", "220101", -6.0333, -76.9667, 860, "selva", 60000),
        ("Tarapoto", "San Martín", "220901", -6.4867, -76.3656, 356, "selva", 180000),
        ("Juanjuí", "Mariscal Cáceres", "220601", -7.1772, -76.7281, 280, "selva", 35000),
        ("Tocache", "Tocache", "221001", -8.1833, -76.5167, 490, "selva", 30000),
    ]),
    "Tacna": ("23", [
        ("Tacna", "Tacna", "230101", -18.0146, -70.2533, 562, "costa_centro", 300000),
        ("Tarata", "Tarata", "230301", -17.4742, -70.0328, 3084, "sierra", 8000),
    ]),
    "Tumbes": ("24", [
        ("Tumbes", "Tumbes", "240101", -3.5669, -80.4515, 7, "costa_norte", 111000),
        ("Corrales", "Tumbes", "240102", -3.6333, -80.4833, 12, "costa_norte", 25000),
        ("La Cruz", "Tumbes", "240103", -3.6333, -80.5833, 15, "costa_norte", 12000),
        ("Zarumilla", "Zarumilla", "240301", -3.5017, -80.2739, 11, "costa_norte", 40000),
        ("Aguas Verdes", "Zarumilla", "240302", -3.4833, -80.2500, 12, "costa_norte", 20000),
        ("Zorritos", "Contralmirante Villar", "240201", -3.6833, -80.6833, 8, "costa_norte", 12000),
    ]),
    "Ucayali": ("25", [
        ("Pucallpa", "Coronel Portillo", "250101", -8.3791, -74.5539, 154, "selva", 380000),
        ("Atalaya", "Atalaya", "250201", -10.7333, -73.7500, 289, "selva", 55000),
        ("Padre Abad", "Padre Abad", "250301", -8.9333, -75.0333, 210, "selva", 60000),
    ]),
}

# Eliminar la clave errónea de Pas
CATALOGO.pop("Pas", None)

# Población, viviendas, escuelas y centros de salud: se derivan de la población
# con ratios INEI, y las susceptibilidades dependen de la zona geográfica.
ZONA_PARAMS = {
    "costa_norte":   dict(vul=0.72, inu=0.90, hua=0.10, seq=0.45, pend=3,  cuenca=2400, umbral=900),
    "costa_centro":  dict(vul=0.65, inu=0.70, hua=0.30, seq=0.55, pend=10, cuenca=3200, umbral=750),
    "quebrada_andina": dict(vul=0.85, inu=0.45, hua=0.95, seq=0.35, pend=32, cuenca=950,  umbral=280),
    "sierra":        dict(vul=0.70, inu=0.35, hua=0.65, seq=0.70, pend=22, cuenca=1500, umbral=400),
    "selva":         dict(vul=0.75, inu=0.85, hua=0.25, seq=0.30, pend=8,  cuenca=5200, umbral=1400),
}

CULTIVOS = {
    "costa_norte": "arroz", "costa_centro": "vid", "quebrada_andina": "papa",
    "sierra": "papa", "selva": "cacao",
}


def slug(nombre):
    """Convierte un nombre a identificador: sin tildes, minúsculas, guiones."""
    import unicodedata
    s = unicodedata.normalize("NFKD", nombre)
    s = "".join(c for c in s if not unicodedata.combining(c))
    s = s.lower().replace(" ", "-").replace("'", "")
    for ch in [",", ".", "(", ")"]:
        s = s.replace(ch, "")
    return s


def construir():
    filas = []
    prefijos = {  # prefijo de id por departamento
        "Amazonas": "ama", "Áncash": "anc", "Apurímac": "apu", "Arequipa": "are",
        "Ayacucho": "aya", "Cajamarca": "caj", "Callao": "cal", "Cusco": "cus",
        "Huancavelica": "hvc", "Huánuco": "huu", "Ica": "ica", "Junín": "jun",
        "La Libertad": "lal", "Lambayeque": "lam", "Lima": "lim", "Loreto": "lor",
        "Madre de Dios": "mdd", "Moquegua": "moq", "Pasco": "pas", "Piura": "piu",
        "Puno": "pun", "San Martín": "sam", "Tacna": "tac", "Tumbes": "tum",
        "Ucayali": "uca",
    }

    vistos = set()
    for depto, (udep, distritos) in CATALOGO.items():
        pref = prefijos[depto]
        for (dist, prov, ubigeo, lat, lon, alt, zona, pob) in distritos:
            tid = f"{pref}-{slug(dist)}"
            # Evitar ids duplicados (p. ej. dos "Cusco" o ubigeos repetidos).
            base = tid
            n = 2
            while tid in vistos:
                tid = f"{base}-{n}"
                n += 1
            vistos.add(tid)

            p = ZONA_PARAMS[zona]
            filas.append({
                "id": tid,
                "ubigeo": ubigeo,
                "pais": "Perú",
                "region": depto,
                "provincia": prov,
                "distrito": dist,
                "lat": lat,
                "lon": lon,
                "altitud": alt,
                "zona": zona,
                "poblacion": pob,
                "viviendas": int(pob / 3.7),
                "escuelas": max(3, int(pob / 620)),
                "centros_salud": max(1, int(pob / 9200)),
                "km_vias": round(pob / 380.0, 1),
                "hectareas_cultivo": round(pob / 42.0, 1),
                "vulnerabilidad": p["vul"],
                "susc_inundacion": p["inu"],
                "susc_huaico": p["hua"],
                "susc_sequia": p["seq"],
                "pendiente": p["pend"],
                "cuenca_km2": p["cuenca"],
                "umbral_caudal": p["umbral"],
                "cultivo": CULTIVOS[zona],
                "albergues_capacidad": int(pob * 0.03),
            })
    return filas


def sql_escape(v):
    if isinstance(v, str):
        return "'" + v.replace("'", "''") + "'"
    return str(v)


CAMPOS = ["id", "ubigeo", "pais", "region", "provincia", "distrito", "lat", "lon",
          "altitud", "zona", "poblacion", "viviendas", "escuelas", "centros_salud",
          "km_vias", "hectareas_cultivo", "vulnerabilidad", "susc_inundacion",
          "susc_huaico", "susc_sequia", "pendiente", "cuenca_km2", "umbral_caudal",
          "cultivo", "albergues_capacidad"]


def main():
    filas = construir()
    base = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

    # SQL
    lineas = ["-- Catálogo nacional de territorios (24 departamentos).",
              "-- Generado por scripts/generar_territorios.py", ""]
    for f in filas:
        vals = ", ".join(sql_escape(f[c]) for c in CAMPOS)
        lineas.append(
            f"INSERT INTO territorios ({', '.join(CAMPOS)}) VALUES ({vals}) "
            f"ON CONFLICT (id) DO NOTHING;"
        )
    sql_path = os.path.join(base, "database", "seed_territorios.sql")
    open(sql_path, "w", encoding="utf-8").write("\n".join(lineas) + "\n")
    print(f"SQL: {sql_path} ({len(filas)} territorios)")

    # JSON de respaldo
    regiones = sorted(set(f["region"] for f in filas))
    json_path = os.path.join(base, "config", "territorios.json")
    datos = {
        "_meta": {
            "version": "nacional-2026",
            "fuente": "Catálogo nacional — 24 departamentos del Perú",
            "total": len(filas),
            "departamentos": len(regiones),
            "nota": "Respaldo offline. La fuente de verdad es la tabla territorios.",
        },
        "territorios": filas,
    }
    open(json_path, "w", encoding="utf-8").write(
        json.dumps(datos, ensure_ascii=False, indent=2))
    print(f"JSON: {json_path}")
    print(f"Departamentos: {len(regiones)} | Distritos: {len(filas)}")
    for r in regiones:
        n = sum(1 for f in filas if f["region"] == r)
        print(f"  {r}: {n}")


if __name__ == "__main__":
    main()
