<?php
/**
 * UMBRALES DE RESPALDO — WILLAQ
 *
 * Se usan SOLO cuando no existe climatología calculada para un distrito
 * (fallback). La fuente preferente es la climatología real calculada desde
 * el archivo ERA5 de Open-Meteo (ver models/Clima.php::clima_climatologia).
 *
 * Cuando se usa este archivo el índice se marca con confianza = "media",
 * y la interfaz lo muestra. Nunca se oculta la procedencia del dato.
 */

$UMBRALES_ZONA = [
    'costa_norte' => [
        // precipitación diaria (mm)
        'p50_24' => 2.0,  'p95_24' => 25.0, 'p99_24' => 60.0,
        // acumulado de 90 días (p50_24 × 90)
        'p50_90' => 180.0, 'p95_90' => 2250.0,
        // temperatura aparente máxima (°C)
        'p50_temp' => 32.0, 'p95_temp' => 35.0, 'p99_temp' => 38.0,
        'caudal_ref' => 800,
    ],
    'costa_centro' => [
        'p50_24' => 0.2,  'p95_24' => 3.0,  'p99_24' => 9.0,
        'p50_90' => 18.0, 'p95_90' => 270.0,
        'p50_temp' => 25.0, 'p95_temp' => 28.5, 'p99_temp' => 31.0,
        'caudal_ref' => 120,
    ],
    'quebrada_andina' => [
        'p50_24' => 1.0,  'p95_24' => 12.0, 'p99_24' => 30.0,
        'p50_90' => 90.0, 'p95_90' => 1080.0,
        'p50_temp' => 23.0, 'p95_temp' => 27.0, 'p99_temp' => 30.0,
        'caudal_ref' => 90,
    ],
    'sierra' => [
        'p50_24' => 1.5,  'p95_24' => 14.0, 'p99_24' => 32.0,
        'p50_90' => 135.0, 'p95_90' => 1260.0,
        'p50_temp' => 19.0, 'p95_temp' => 23.0, 'p99_temp' => 26.0,
        'caudal_ref' => 100,
    ],
    'selva' => [
        'p50_24' => 5.0,  'p95_24' => 40.0, 'p99_24' => 90.0,
        'p50_90' => 450.0, 'p95_90' => 3600.0,
        'p50_temp' => 32.0, 'p95_temp' => 35.0, 'p99_temp' => 38.0,
        'caudal_ref' => 600,
    ],
];

/**
 * UMBRALES DE MATERIALIDAD
 *
 * El percentil local dice "esto es raro aquí"; la materialidad dice "esto
 * importa". Sin este segundo filtro el motor produce absurdos: en Lima el
 * p99 diario de lluvia es ~1 mm, así que 1.3 mm de llovizna salía como
 * "lluvia extrema" (nivel rojo).
 *
 * Cada amenaza declara:
 *   min_abs      : por debajo de este valor no hay evento (se combina con el
 *                  percentil local, el mayor de los dos manda)
 *   signo_abs    : a partir de este valor el evento es plenamente material
 *
 * El factor de materialidad (0–1) multiplica al peligro normalizado:
 *   H_final = H_percentil × factor_materialidad
 */
$MATERIALIDAD = [
    // Lluvia acumulada por ventana (mm). Se escala con el percentil de la ventana.
    'lluvia' => [
        24 => ['min_abs' => 3.0,  'signo_abs' => 15.0],
        48 => ['min_abs' => 5.0,  'signo_abs' => 25.0],
        72 => ['min_abs' => 7.0,  'signo_abs' => 35.0],
    ],
    // Intensidad máxima en 3 horas (mm/3h): disparador de huaico.
    'intensidad_3h' => ['min_abs' => 4.0, 'signo_abs' => 15.0],
    // Temperatura aparente máxima (°C): por debajo de 28 °C no hay riesgo
    // térmico para la salud; a partir de 35 °C el evento es plenamente material.
    'calor' => ['min_abs' => 28.0, 'signo_abs' => 35.0],
    // Déficit hídrico (fracción): 15 % ya es déficit, 50 % es emergencia.
    'sequia' => ['min_abs' => 0.15, 'signo_abs' => 0.50],
    // Anomalía de caudal: se ignoran variaciones menores al 5 % sobre el p95.
    'caudal' => ['ratio_min' => 1.05, 'ratio_signo' => 2.50],
];

/**
 * ESCALAS DE DETECCIÓN HISTÓRICA
 *
 * Un evento de El Niño no es solo un día extremo: es persistencia. Por eso la
 * validación histórica no se queda en el pico diario, sino que evalúa tres
 * escalas de acumulación y se queda con la más alta:
 *
 *   diaria        1 día   → aguacero convectivo intenso
 *   tres_dias     3 días  → lluvias sostenidas (el motor ya tiene ventana 72 h)
 *   treinta_dias  30 días → acumulación del episodio (inundación sostenida)
 *
 * Los umbrales absolutos son el mínimo de materialidad de cada escala. El
 * mínimo efectivo real es siempre max(umbral absoluto, p95 local).
 */
$ESCALAS_DETECCION = [
    'diaria' => [
        'dias' => 1, 'min_abs' => 3.0, 'signo_abs' => 15.0,
        'etiqueta' => 'Pico diario',
    ],
    'tres_dias' => [
        'dias' => 3, 'min_abs' => 7.0, 'signo_abs' => 35.0,
        'etiqueta' => 'Acumulado 3 días',
    ],
    'treinta_dias' => [
        'dias' => 30, 'min_abs' => 60.0, 'signo_abs' => 200.0,
        'etiqueta' => 'Acumulado 30 días',
    ],
];

/**
 * Estructura del índice:  R = 100 · H · (base + wE·E + wV·V)
 * El peligro es la condición necesaria; exposición y vulnerabilidad amplifican.
 */
$PESOS_RIESGO = [
    'base' => 0.40,
    'exposicion' => 0.35,
    'vulnerabilidad' => 0.25,
];

/** Cortes de nivel. */
$NIVELES = [
    ['min' => 0,  'max' => 25, 'id' => 'verde',    'etiqueta' => 'Bajo',      'color' => '#22c55e'],
    ['min' => 26, 'max' => 50, 'id' => 'amarillo', 'etiqueta' => 'Medio',     'color' => '#eab308'],
    ['min' => 51, 'max' => 75, 'id' => 'naranja',  'etiqueta' => 'Alto',      'color' => '#f97316'],
    ['min' => 76, 'max' => 100,'id' => 'rojo',     'etiqueta' => 'Muy alto',  'color' => '#ef4444'],
];

/** Sensibilidad de cultivos (0-1) para el subíndice agropecuario. */
$SENSIBILIDAD_CULTIVO = [
    'arroz' => 0.95, 'banano' => 0.90, 'limón' => 0.80, 'hortalizas' => 0.85,
    'maiz' => 0.85, 'papa' => 0.75, 'quinua' => 0.60, 'café' => 0.80,
    'frutales' => 0.75, 'pastos' => 0.55, 'ninguno' => 0.0,
];

/** Protocolos sugeridos por amenaza y nivel. Lenguaje de RECOMENDACIÓN, no de alerta oficial. */
$PROTOCOLOS = [
    'inundacion' => [
        'verde'    => ['Monitorear el caudal y la lluvia.', 'Revisar drenajes pluviales.'],
        'amarillo' => ['Limpiar cauces y drenajes.', 'Verificar rutas de evacuación.', 'Avisar a presidentes de juntas vecinales.'],
        'naranja'  => ['Activar el centro de operaciones de emergencia.', 'Preparar albergues y kit de emergencia.', 'Restringir actividades en zonas bajas.'],
        'rojo'     => ['Evacuar zonas inundables de forma preventiva.', 'Suspender clases y actividades públicas.', 'Abrir albergues y asegurar agua segura.'],
    ],
    'huaico' => [
        'verde'    => ['Monitorear quebradas secas.', 'Revisar señalización de rutas de escape.'],
        'amarillo' => ['Restringir acceso a cauces y quebradas.', 'Podar vegetación en laderas.', 'Reforzar comunicación vecinal.'],
        'naranja'  => ['Evacuar laderas y abanicos aluviales.', 'Cerrar vías en quebradas activas.', 'Activar albergues temporales.'],
        'rojo'     => ['Evacuación inmediata de zonas de abanico.', 'Cierre total de vías en la quebrada.', 'No cruzar cauces activos bajo ninguna circunstancia.'],
    ],
    'lluvia' => [
        'verde'    => ['Monitorear el pronóstico diario.'],
        'amarillo' => ['Asegurar techos y canales de agua.', 'Revisar sumideros.'],
        'naranja'  => ['Suspender actividades al aire libre.', 'Proteger documentos y equipos.', 'Verificar estado de puentes.'],
        'rojo'     => ['Suspender clases y eventos.', 'Evitar desplazamientos innecesarios.', 'Activar protocolo de emergencia.'],
    ],
    'sequia' => [
        'verde'    => ['Monitorear reservorios.'],
        'amarillo' => ['Racionar agua de riego.', 'Priorizar consumo humano.'],
        'naranja'  => ['Declarar emergencia hídrica local.', 'Suministro por cisternas.', 'Proteger ganado.'],
        'rojo'     => ['Racionamiento severo.', 'Evacuación de ganado.', 'Asistencia alimentaria.'],
    ],
    'calor' => [
        'verde'    => ['Hidratación normal.'],
        'amarillo' => ['Evitar exposición solar entre 10:00 y 16:00.', 'Vigilar a adultos mayores.'],
        'naranja'  => ['Suspender actividad física al aire libre.', 'Habilitar puntos de hidratación.', 'Vigilar golpes de calor.'],
        'rojo'     => ['Suspender clases y trabajo exterior.', 'Abrir refugios climáticos.', 'Atención prioritaria a menores y adultos mayores.'],
    ],
    'agro' => [
        'verde'    => ['Mantener el calendario agrícola.'],
        'amarillo' => ['Ajustar el riego.', 'Revisar drenajes del cultivo.'],
        'naranja'  => ['Proteger cosecha y almacén.', 'Asegurar drenaje parcelario.', 'Diferir siembras.'],
        'rojo'     => ['Cosecha de emergencia.', 'Activar seguro agrario.', 'No sembrar en zonas anegadas.'],
    ],
];
