#!/usr/bin/env bash
#
# DESCARGA DE SERIES SENAMHI CON GARÚA — WILLAQ
#
# QUÉ HACE
#   Usa Garúa (https://github.com/danyneyra/garua, MIT) para descargar las
#   series hidrometeorológicas oficiales del SENAMHI de las estaciones que
#   WILLAQ tiene registradas, y las deja en data/estaciones/_garua/csv/.
#
#   Garúa abre un navegador (Chrome/Brave/Edge) porque el portal del SENAMHI
#   pide verificación Cloudflare Turnstile. Es una descarga con navegador,
#   no una API: por eso es un script aparte y no parte del runtime de la app.
#
# POR QUÉ UN SCRIPT SEPARADO
#   · La aplicación web es PHP puro sin dependencias externas. Garúa es Python
#     y solo se necesita en la etapa de ingesting de datos, nunca en runtime.
#   · Cada descarga deja CSV crudos con la cabecera original del SENAMHI.
#     El importador `importar_estaciones.php` es quien los normaliza, les
#     aplica el QA y decide si la estación es utilizable.
#
# USO
#   bash scripts/descargar_estaciones_garua.sh              # estación pendiente
#   bash scripts/descargar_estaciones_garua.sh 105063       # una estación
#   bash scripts/descargar_estaciones_garua.sh --critica    # solo las críticas
#   bash scripts/descargar_estaciones_garua.sh --lista      # ver pendientes
#   bash scripts/descargar_estaciones_garua.sh --forzar     # ignora lo ya descargado
#   bash scripts/descargar_estaciones_garua.sh --reintentos=3
#
# REQUISITOS
#   uv tool install garua        (o: pipx install garua)
#   Un navegador: google-chrome-stable, brave o microsoft-edge

set -uo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$RAIZ" || exit 1

export GARUA_OUTPUT_DIR="${GARUA_OUTPUT_DIR:-$RAIZ/data/estaciones/_garua}"
CSV_DIR="$GARUA_OUTPUT_DIR/csv"
LOG_DIR="$GARUA_OUTPUT_DIR/logs"
mkdir -p "$CSV_DIR" "$LOG_DIR"

ANIO_INICIO="${WILLAQ_EST_YEAR_INICIO:-2017}"
ANIO_FIN="${WILLAQ_EST_YEAR_FIN:-$(date +%Y)}"

# Reintentos por estación. El portal se conecta con Chrome y a veces falla
# con «Failed to connect to browser»; casi siempre funciona al reintentar.
REINTENTOS="${WILLAQ_EST_REINTENTOS:-2}"
ESPERA_INICIAL="${WILLAQ_EST_ESPERA:-15}"

# Estaciones registradas en config/estaciones.json sin CSV descargado todavía.
# Se ordenan por prioridad: primero las «critica» (las que cubren un distrito
# no detectado del backtest y pueden mover un resultado), luego el resto.
listar_pendientes() {
    php -r '
        $c = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($c)) { exit(1); }
        $orden = ["critica" => 0, "alta" => 1, "media" => 2, "baja" => 3];
        $est = $c["estaciones"] ?? [];
        usort($est, function ($a, $b) use ($orden) {
            $pa = $orden[$a["prioridad"] ?? "baja"] ?? 3;
            $pb = $orden[$b["prioridad"] ?? "baja"] ?? 3;
            if ($pa !== $pb) { return $pa <=> $pb; }
            return strcmp((string)($a["datos_desde"] ?? "9999"), (string)($b["datos_desde"] ?? "9999"));
        });
        foreach ($est as $e) {
            $cod = $e["cod"];
            // El directorio que crea Garúa es {Nombre}_{cod}_{tipo}_{categoria}.
            $dirs = glob($argv[2] . "/*_" . $cod . "_*", GLOB_ONLYDIR) ?: [];
            $tiene = false;
            foreach ($dirs as $dir) {
                if (glob($dir . "/*.csv")) { $tiene = true; break; }
            }
            if (!$tiene) {
                echo $e["id"] . " " . $cod . " " . ($e["prioridad"] ?? "baja") . "\n";
            }
        }
    ' "$RAIZ/config/estaciones.json" "$CSV_DIR"
}

# Igual que listar_pendientes, pero limitado a un nivel de prioridad.
listar_por_prioridad() {
    listar_pendientes | awk -v p="$1" '$3 == p { print $1, $2 }'
}

if ! command -v garua >/dev/null 2>&1; then
    echo "ERROR: 'garua' no está instalado. Ejecuta: uv tool install garua" >&2
    exit 1
fi

if ! garua --health >/dev/null 2>&1; then
    echo "ERROR: garua --health falló. Revisa el navegador (garua --doctor)." >&2
    exit 1
fi

FORZAR=0
PRIORIDAD=""
objetivos=()
for arg in "$@"; do
    case "$arg" in
        --forzar) FORZAR=1 ;;
        --lista)  listar_pendientes; exit 0 ;;
        --reintentos=*) REINTENTOS="${arg#--reintentos=}" ;;
        --critica|--alta|--media|--baja) PRIORIDAD="${arg#--}" ;;
        *)        objetivos+=("$arg") ;;
    esac
done

if ! [[ "$REINTENTOS" =~ ^[0-9]+$ ]]; then
    echo "ERROR: --reintentos debe ser un entero." >&2
    exit 64
fi

if [ "${#objetivos[@]}" -eq 0 ]; then
    if [ -n "$PRIORIDAD" ]; then
        while read -r _id cod; do
            [ -n "${cod:-}" ] && objetivos+=("$cod")
        done < <(listar_por_prioridad "$PRIORIDAD")
    else
        while read -r _id cod _prio; do
            [ -n "${cod:-}" ] && objetivos+=("$cod")
        done < <(listar_pendientes)
    fi
fi

if [ "${#objetivos[@]}" -eq 0 ]; then
    echo "No hay estaciones pendientes de descarga."
    exit 0
fi

echo "=============================================================="
echo " Descarga de series SENAMHI con Garúa"
echo " Salida:      $CSV_DIR"
echo " Periodo:     $ANIO_INICIO - $ANIO_FIN"
echo " Estaciones:  ${#objetivos[@]}"
echo " Reintentos:  $REINTENTOS por estación"
echo "=============================================================="

fallos=0
fallidas=()

for cod in "${objetivos[@]}"; do
    nombre=$(php -r '
        $c=json_decode(file_get_contents($argv[1]),true);
        foreach(($c["estaciones"]??[]) as $e){ if($e["cod"]===$argv[2]){ echo $e["nombre"]; exit; } }
    ' "$RAIZ/config/estaciones.json" "$cod")
    log="$LOG_DIR/garua_$cod.log"

    if [ "$FORZAR" -eq 0 ]; then
        ya=$(find "$CSV_DIR" -maxdepth 2 -path "*${cod}_*" -name '*.csv' 2>/dev/null | head -1)
        if [ -n "$ya" ]; then
            echo "· $cod ($nombre): ya existe, se omite"
            continue
        fi
    fi

    echo ""
    echo ">>> $cod ($nombre) — descargando $ANIO_INICIO..$ANIO_FIN"

    ok=0
    for intento in $(seq 0 "$REINTENTOS"); do
        if [ "$intento" -gt 0 ]; then
            espera=$((ESPERA_INICIAL * intento))
            echo "    reintento $intento/$REINTENTOS en ${espera}s…"
            sleep "$espera"
        fi

        garua --station "$cod" --mode period \
              --start "$ANIO_INICIO" --end "$ANIO_FIN" \
              --output "$CSV_DIR" >"$log" 2>&1

        # Garúa puede salir con código 0 aunque no haya descargado nada (por
        # ejemplo, si falló la conexión con Chrome). El criterio de éxito es
        # que exista al menos un CSV con datos de la estación.
        n=$(find "$CSV_DIR" -maxdepth 2 -path "*${cod}_*" -name '*.csv' 2>/dev/null | wc -l)
        if [ "$n" -gt 0 ]; then
            ok=1
            break
        fi
        echo "    sin CSV (intento $((intento + 1))/$((REINTENTOS + 1))) — ver $log"
    done

    if [ "$ok" -eq 1 ]; then
        echo "    OK — $n archivo(s) CSV"
    else
        echo "    FALLÓ — ver $log"
        fallos=$((fallos + 1))
        fallidas+=("$cod")
    fi
done

echo ""
echo "=============================================================="
if [ "$fallos" -eq 0 ]; then
    echo " Todas las descargas terminaron correctamente."
else
    echo " $fallos descarga(s) fallaron: ${fallidas[*]}"
    echo " Revisa $LOG_DIR y reintenta con:"
    echo "   bash scripts/descargar_estaciones_garua.sh ${fallidas[*]}"
fi
echo "=============================================================="
exit "$fallos"
