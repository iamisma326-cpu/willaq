<?php
/**
 * CLIENTE DE DATOS CLIMÁTICOS — WILLAQ
 *
 * Fuente principal: Open-Meteo (sin API key, uso no comercial).
 *   - Forecast  : pronóstico horario/diario 16 días
 *   - Flood     : caudal de río GloFAS 5 km
 *   - Archive   : reanálisis ERA5 para climatología y sequía
 *
 * Principios:
 *   1. Toda respuesta se cachea. Nunca se golpea la API en cada clic.
 *   2. Si la API falla, se devuelve el último dato bueno (modo offline).
 *   3. Cada respuesta conserva fuente, fecha de obtención y si es respaldo.
 */

require_once __DIR__ . '/Cache.php';

/** Zona horaria oficial del Perú. */
function clima_tz() {
    return new DateTimeZone('America/Lima');
}

function clima_hoy() {
    return new DateTime('now', clima_tz());
}

function clima_error_ultimo($mensaje = null) {
    static $ultimo = null;
    if ($mensaje !== null) {
        $ultimo = $mensaje;
    }
    return $ultimo;
}

/**
 * GET JSON con cURL, reintentos y timeout corto (nunca bloquear una demo).
 */
function clima_curl_json($url, $timeout = 12, $reintentos = 2) {
    for ($intento = 1; $intento <= $reintentos; $intento++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'WILLAQ/1.0 (proyecto academico; +https://willaq.local)',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $respuesta = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($respuesta !== false && $codigo >= 200 && $codigo < 300) {
            $datos = json_decode($respuesta, true);
            if (is_array($datos)) {
                return $datos;
            }
        }

        clima_error_ultimo('HTTP ' . $codigo . ($error ? ' · ' . $error : ''));
        if ($intento < $reintentos) {
            usleep(300000);
        }
    }
    return null;
}

/** Convierte valores posiblemente nulos a float seguro. */
function clima_f($valor, $defecto = null) {
    return (is_numeric($valor) ? (float)$valor : $defecto);
}

/** Suma ignorando nulos; si no hay ningún dato válido devuelve null. */
function clima_suma($valores) {
    $suma = 0.0;
    $hay = false;
    foreach ((array)$valores as $v) {
        if (is_numeric($v)) {
            $suma += (float)$v;
            $hay = true;
        }
    }
    return $hay ? $suma : null;
}

/** Percentil con interpolación lineal. $p entre 0 y 100. */
function clima_percentil($valores, $p) {
    $v = array_values(array_filter((array)$valores, 'is_numeric'));
    if (count($v) === 0) {
        return null;
    }
    sort($v, SORT_NUMERIC);
    $n = count($v);
    $pos = ($n - 1) * ($p / 100);
    $base = (int)floor($pos);
    $resto = $pos - $base;
    if ($base + 1 < $n) {
        return $v[$base] + $resto * ($v[$base + 1] - $v[$base]);
    }
    return $v[$base];
}

/**
 * PRONÓSTICO DIARIO + HORARIO.
 * Devuelve estructura normalizada lista para el motor de riesgo.
 */
function clima_forecast($lat, $lon, $dias = 7) {
    $lat = round((float)$lat, 4);
    $lon = round((float)$lon, 4);
    $clave = "forecast:$lat:$lon:$dias";

    return cache_recordar($clave, 3600, function () use ($lat, $lon, $dias) {
        $params = http_build_query([
            'latitude' => $lat,
            'longitude' => $lon,
            'daily' => implode(',', [
                'precipitation_sum',
                'precipitation_hours',
                'precipitation_probability_max',
                'temperature_2m_max',
                'temperature_2m_min',
                'apparent_temperature_max',
                'apparent_temperature_min',
                'wind_speed_10m_max',
                'et0_fao_evapotranspiration',
                'weather_code',
            ]),
            'hourly' => 'precipitation,apparent_temperature',
            'past_days' => 7,
            'forecast_days' => $dias,
            'timezone' => 'America/Lima',
        ]);

        $datos = clima_curl_json('https://api.open-meteo.com/v1/forecast?' . $params);
        if (!$datos || empty($datos['daily']['time'])) {
            return null;
        }

        $d = $datos['daily'];
        $h = $datos['hourly'] ?? [];

        return [
            'fuente' => 'Open-Meteo Forecast (ERA5/ICON)',
            'obtenido_en' => time(),
            'zona_horaria' => $datos['timezone'] ?? 'America/Lima',
            'elevacion' => $datos['elevation'] ?? null,
            'dias' => $d['time'],
            'precipitacion' => array_map(fn($v) => clima_f($v, 0.0), $d['precipitation_sum'] ?? []),
            'horas_lluvia' => array_map(fn($v) => clima_f($v, 0.0), $d['precipitation_hours'] ?? []),
            'prob_lluvia' => array_map(fn($v) => clima_f($v, 0.0), $d['precipitation_probability_max'] ?? []),
            'tmax' => array_map(fn($v) => clima_f($v), $d['temperature_2m_max'] ?? []),
            'tmin' => array_map(fn($v) => clima_f($v), $d['temperature_2m_min'] ?? []),
            'stmax' => array_map(fn($v) => clima_f($v), $d['apparent_temperature_max'] ?? []),
            'stmin' => array_map(fn($v) => clima_f($v), $d['apparent_temperature_min'] ?? []),
            'viento' => array_map(fn($v) => clima_f($v, 0.0), $d['wind_speed_10m_max'] ?? []),
            'et0' => array_map(fn($v) => clima_f($v, 0.0), $d['et0_fao_evapotranspiration'] ?? []),
            'codigo_tiempo' => $d['weather_code'] ?? [],
            'horas' => [
                'tiempo' => $h['time'] ?? [],
                'precipitacion' => array_map(fn($v) => clima_f($v, 0.0), $h['precipitation'] ?? []),
            ],
        ];
    }, 'Open-Meteo', null);
}

/**
 * CAUDAL DE RÍO (GloFAS vía Open-Meteo Flood).
 * Incluye 31 días de pasado para calcular la anomalía LOCAL, que es mucho más
 * robusta que un umbral absoluto (el mismo caudal significa cosas distintas
 * en el río Piura y en el Rímac).
 */
function clima_flood($lat, $lon) {
    $lat = round((float)$lat, 4);
    $lon = round((float)$lon, 4);
    $clave = "flood:$lat:$lon";

    return cache_recordar($clave, 3600, function () use ($lat, $lon) {
        $params = http_build_query([
            'latitude' => $lat,
            'longitude' => $lon,
            'daily' => 'river_discharge,river_discharge_max',
            'past_days' => 31,
            'forecast_days' => 7,
            'timezone' => 'America/Lima',
        ]);

        $datos = clima_curl_json('https://flood-api.open-meteo.com/v1/flood?' . $params);
        if (!$datos || empty($datos['daily']['time'])) {
            return null;
        }

        $d = $datos['daily'];
        return [
            'fuente' => 'Open-Meteo Flood (GloFAS 5 km)',
            'obtenido_en' => time(),
            'dias' => $d['time'],
            'descarga' => array_map(fn($v) => clima_f($v, 0.0), $d['river_discharge'] ?? []),
            'descarga_max' => array_map(fn($v) => clima_f($v, 0.0), $d['river_discharge_max'] ?? []),
            'pasado_hasta' => 31, // posiciones 0..30 son pasado
        ];
    }, 'Open-Meteo Flood', null);
}

/**
 * ARCHIVO HISTÓRICO (ERA5).
 * Se pide un bloque largo una sola vez y se filtra en PHP: así se evita
 * hacer 11 peticiones (una por año) por cada distrito.
 */
function clima_archivo($lat, $lon, $inicio, $fin) {
    $lat = round((float)$lat, 4);
    $lon = round((float)$lon, 4);
    $clave = "archivo:$lat:$lon:$inicio:$fin";

    return cache_recordar($clave, 86400 * 7, function () use ($lat, $lon, $inicio, $fin) {
        $params = http_build_query([
            'latitude' => $lat,
            'longitude' => $lon,
            'start_date' => $inicio,
            'end_date' => $fin,
            'daily' => 'precipitation_sum,temperature_2m_max,apparent_temperature_max,et0_fao_evapotranspiration',
            'timezone' => 'America/Lima',
        ]);

        $datos = clima_curl_json('https://archive-api.open-meteo.com/v1/archive?' . $params, 25, 2);
        if (!$datos || empty($datos['daily']['time'])) {
            return null;
        }

        $d = $datos['daily'];
        return [
            'fuente' => 'Open-Meteo Archive (ERA5)',
            'obtenido_en' => time(),
            'dias' => $d['time'],
            'precipitacion' => array_map(fn($v) => clima_f($v), $d['precipitation_sum'] ?? []),
            'tmax' => array_map(fn($v) => clima_f($v), $d['temperature_2m_max'] ?? []),
            'stmax' => array_map(fn($v) => clima_f($v), $d['apparent_temperature_max'] ?? []),
            'et0' => array_map(fn($v) => clima_f($v), $d['et0_fao_evapotranspiration'] ?? []),
        ];
    }, 'Open-Meteo Archive', null);
}

/**
 * CLIMATOLOGÍA DEL DISTRITO.
 * Calcula p50/p95/p99 de precipitación diaria y temperatura aparente para la
 * ventana de calendario de hoy (±15 días) sobre los últimos 11 años completos.
 *
 * Resultado cacheado 30 días. Si no hay red, devuelve null y el motor usa
 * los umbrales de respaldo de config/umbrales.php.
 */
function clima_climatologia($territorio, $permitir_calculo = true) {
    $id = $territorio['id'];
    $clave = "climatologia:$id";

    // En una petición web NUNCA se dispara el cálculo pesado: se usa lo que ya
    // exista (aunque esté vencido) o los umbrales de respaldo. El cálculo se
    // hace en scripts/calibrar.php antes de la feria.
    if (!$permitir_calculo) {
        $item = cache_leer($clave, true);
        return $item ? $item['payload'] : null;
    }

    return cache_recordar($clave, 86400 * 30, function () use ($territorio) {
        $hoy = clima_hoy();
        $anioFin = (int)$hoy->format('Y') - 1;
        $anioIni = $anioFin - 10;

        $archivo = clima_archivo(
            $territorio['lat'],
            $territorio['lon'],
            $anioIni . '-01-01',
            $anioFin . '-12-31'
        );
        if (!$archivo) {
            return null;
        }

        $mmHoy = (int)$hoy->format('n');
        $ddHoy = (int)$hoy->format('j');

        $precipitaciones = [];
        $temperaturas = [];

        foreach ($archivo['dias'] as $i => $fecha) {
            $ts = strtotime($fecha);
            $mm = (int)date('n', $ts);
            $dd = (int)date('j', $ts);

            // Ventana de calendario ±15 días (manejando el cambio de año)
            $diaDelAnio = (int)date('z', $ts);
            $diaHoy = (int)$hoy->format('z');
            $distancia = abs($diaDelAnio - $diaHoy);
            $distancia = min($distancia, 365 - $distancia);

            if ($distancia > 15) {
                continue;
            }

            $p = $archivo['precipitacion'][$i] ?? null;
            if (is_numeric($p)) {
                $precipitaciones[] = (float)$p;
            }
            $t = $archivo['stmax'][$i] ?? null;
            if (is_numeric($t)) {
                $temperaturas[] = (float)$t;
            }
        }

        if (count($precipitaciones) < 30) {
            return null;
        }

        $p50 = clima_percentil($precipitaciones, 50);
        $p95 = clima_percentil($precipitaciones, 95);
        $p99 = clima_percentil($precipitaciones, 99);

        return [
            'fuente' => 'ERA5 · ' . $anioIni . '-' . $anioFin . ' · ventana ±15 días',
            'obtenido_en' => time(),
            'confianza' => 'alta',
            'p50_24' => round($p50, 2),
            'p95_24' => round(max($p95, $p50 + 0.5), 2),
            'p99_24' => round(max($p99, $p95 + 1.0), 2),
            'p50_temp' => round(clima_percentil($temperaturas, 50), 2),
            'p95_temp' => round(clima_percentil($temperaturas, 95), 2),
            'p99_temp' => round(clima_percentil($temperaturas, 99), 2),
            'p50_90' => round($p50 * 90, 2),
            'p95_90' => round($p95 * 90, 2),
            'muestra_dias' => count($precipitaciones),
        ];
    }, 'ERA5', null);
}

/** Precipitación acumulada de los últimos N días (para sequía). */
function clima_reciente($lat, $lon, $dias = 90) {
    $lat = round((float)$lat, 4);
    $lon = round((float)$lon, 4);
    $clave = "reciente:$lat:$lon:$dias";

    return cache_recordar($clave, 86400 / 2, function () use ($lat, $lon, $dias) {
        $fin = clima_hoy()->modify('-5 days')->format('Y-m-d');
        $inicio = clima_hoy()->modify('-' . ($dias + 5) . ' days')->format('Y-m-d');

        $archivo = clima_archivo($lat, $lon, $inicio, $fin);
        if (!$archivo) {
            return null;
        }

        $precip = array_values(array_filter($archivo['precipitacion'], 'is_numeric'));
        $recorte = array_slice($precip, -$dias);

        return [
            'fuente' => $archivo['fuente'],
            'obtenido_en' => time(),
            'dias' => count($recorte),
            'acumulado' => round((float)clima_suma($recorte), 2),
            'dias_sin_lluvia' => count(array_filter($recorte, fn($v) => (float)$v < 0.5)),
            'et0_acumulado' => round((float)clima_suma(array_slice($archivo['et0'], -$dias)), 2),
            'inicio' => $inicio,
            'fin' => $fin,
        ];
    }, 'ERA5', null);
}

/** Metadatos de la última actualización de datos por distrito. */
function clima_estado_fuentes($territorio) {
    $f = clima_forecast($territorio['lat'], $territorio['lon']);
    $fl = clima_flood($territorio['lat'], $territorio['lon']);
    return [
        'forecast' => $f['obtenido_en'] ?? null,
        'flood' => $fl['obtenido_en'] ?? null,
    ];
}
