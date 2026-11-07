#!/usr/bin/env bash
#
# WILLAQ — MANTENIMIENTO PROGRAMADO
#
# Punto de entrada único para cron o systemd timer en un servidor Linux
# (Google Cloud, AWS o cualquier VM). Evita que dos ejecuciones se pisen,
# escribe un registro fechado y devuelve códigos de salida útiles.
#
# Uso:
#   scripts/willaq_cron.sh snapshot          # actualizar.php --ventana=all (24/48/72 h)
#   scripts/willaq_cron.sh alertas           # semear_alertas.php --limpiar
#   scripts/willaq_cron.sh todo              # snapshot y, si sale bien, alertas
#   scripts/willaq_cron.sh snapshot --prueba # sin red: actualizar.php --solo=snapshot
#   scripts/willaq_cron.sh estado            # frescura de snapshots y alertas
#   scripts/willaq_cron.sh --ayuda
#
# Variables:
#   WILLAQ_PHP_BIN   Ruta explícita al PHP CLI (si `php` no está en el PATH).
#
# Códigos de salida:
#   0  correcto (o ejecución omitida porque ya había otra en curso)
#   64 uso incorrecto
#   77 data/ no es escribible
#   78 no se encontró PHP
#   *  el código que devuelva el script PHP
#
# Documentación: docs/AUTOMATIZACION.md

set -u

RAIZ="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${WILLAQ_PHP_BIN:-$(command -v php 2>/dev/null || true)}"
DATA="$RAIZ/data"
LOG="$DATA/cron.log"
LOCK="$DATA/willaq_cron.lock"
MAX_LOG_BYTES=$((5 * 1024 * 1024))

MODO="${1:-}"
PRUEBA=0
if [ "${2:-}" = "--prueba" ]; then
    PRUEBA=1
fi

fecha() {
    date '+%Y-%m-%d %H:%M:%S %Z'
}

ayuda() {
    sed -n '2,26p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

case "$MODO" in
    --ayuda|-h|--help)
        ayuda
        exit 0
        ;;
    snapshot|alertas|todo|estado)
        ;;
    *)
        echo "Modo no reconocido: '${MODO}'" >&2
        echo "Use: snapshot | alertas | todo | estado | --ayuda" >&2
        exit 64
        ;;
esac

# La salida normal va al registro. Si se ejecuta a mano, al terminar se
# muestran las últimas líneas en la terminal (fd 3 conserva la salida original).
ES_TTY=0
if [ -t 1 ]; then
    ES_TTY=1
fi
exec 3>&1

if [ "$MODO" != "estado" ]; then
    mkdir -p "$DATA" 2>/dev/null || true

    # Rotación simple: nunca se deja crecer el registro sin límite.
    if [ -f "$LOG" ]; then
        TAM_LOG="$(wc -c < "$LOG" 2>/dev/null || echo 0)"
        if [ "${TAM_LOG:-0}" -gt "$MAX_LOG_BYTES" ]; then
            mv -f "$LOG" "$LOG.1" 2>/dev/null || true
        fi
    fi

    exec >> "$LOG" 2>&1

    echo ""
    echo "[$(fecha)] Inicio · modo=$MODO · prueba=$PRUEBA · php=${PHP_BIN:-no encontrado}"
fi

if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
    echo "[$(fecha)] ERROR: no se encontró PHP CLI. Defina WILLAQ_PHP_BIN=/ruta/al/php."
    if [ "$ES_TTY" -eq 1 ]; then
        tail -n 5 "$LOG" >&3 2>/dev/null || true
    fi
    exit 78
fi

# ---------------------------------------------------------------------------
# Bloqueo: una sola ejecución a la vez (snapshot y alertas comparten lock).
# ---------------------------------------------------------------------------
if [ "$MODO" != "estado" ]; then
    if command -v flock >/dev/null 2>&1; then
        exec 9>"$LOCK"
        if ! flock -n 9; then
            echo "[$(fecha)] Omitido ($MODO): otra ejecución sigue en curso."
            if [ "$ES_TTY" -eq 1 ]; then
                tail -n 5 "$LOG" >&3 2>/dev/null || true
            fi
            exit 0
        fi
    else
        LOCKDIR="$DATA/.willaq_cron.lockdir"
        if ! mkdir "$LOCKDIR" 2>/dev/null; then
            echo "[$(fecha)] Omitido ($MODO): existe $LOCKDIR (proceso colgado o ejecución en curso)."
            if [ "$ES_TTY" -eq 1 ]; then
                tail -n 5 "$LOG" >&3 2>/dev/null || true
            fi
            exit 0
        fi
        trap 'rmdir "$LOCKDIR" 2>/dev/null || true' EXIT INT TERM
    fi

    if [ ! -w "$DATA" ]; then
        echo "[$(fecha)] ERROR: $DATA no es escribible por el usuario $(id -un 2>/dev/null || echo '?')."
        if [ "$ES_TTY" -eq 1 ]; then
            tail -n 5 "$LOG" >&3 2>/dev/null || true
        fi
        exit 77
    fi
fi

# ---------------------------------------------------------------------------
# Tareas
# ---------------------------------------------------------------------------
ejecutar_snapshot() {
    local args=(--ventana=all)
    if [ "$PRUEBA" -eq 1 ]; then
        args=(--solo=snapshot --ventana=all)
    fi
    echo "[$(fecha)] → actualizar.php ${args[*]}"
    "$PHP_BIN" "$RAIZ/scripts/actualizar.php" "${args[@]}"
}

ejecutar_alertas() {
    echo "[$(fecha)] → semear_alertas.php --limpiar"
    "$PHP_BIN" "$RAIZ/scripts/semear_alertas.php" --limpiar
}

mostrar_estado() {
    WILLAQ_RAIZ="$RAIZ" "$PHP_BIN" -r '
        $raiz = getenv("WILLAQ_RAIZ");
        $ahora = time();
        $fmt = function ($segundos) {
            if ($segundos < 60)    return $segundos . " s";
            if ($segundos < 3600)  return floor($segundos / 60) . " min";
            if ($segundos < 86400) return floor($segundos / 3600) . " h";
            return floor($segundos / 86400) . " d";
        };
        echo "Snapshots:\n";
        foreach ([24, 48, 72] as $v) {
            $f = $raiz . "/data/snapshots/riesgo_nacional_" . $v . "h.json";
            if (!is_file($f)) {
                echo "  " . str_pad($v . " h", 5) . " sin archivo\n";
                continue;
            }
            $d = json_decode(file_get_contents($f), true);
            $g = (int)($d["generado_en"] ?? 0);
            $n = count($d["riesgos"] ?? []);
            printf("  %-5s %s · %d distritos · hace %s\n",
                $v . " h", $g ? date("Y-m-d H:i:s T", $g) : "sin fecha", $n, $fmt(max(0, $ahora - $g)));
        }
        $fa = $raiz . "/data/alertas.json";
        $a = is_file($fa) ? json_decode(file_get_contents($fa), true) : ["alertas" => []];
        echo "Alertas (espejo JSON): " . count($a["alertas"] ?? []) . "\n";

        // Estado de la base de datos (si está configurada).
        require_once $raiz . "/config/database.php";
        require_once $raiz . "/models/Alerta.php";
        if (alertas_bd_disponible()) {
            $n = count(alertas_todas());
            echo "Base de datos: " . db_motor_nombre() . " · " . $n . " alertas\n";
        } else {
            echo "Base de datos: no conectada (se usa el archivo JSON)\n";
        }

        $fl = $raiz . "/data/cron.log";
        if (is_file($fl)) {
            echo "Registro: " . $fl . " (" . number_format(filesize($fl) / 1024, 1, ",", ".") . " KB)\n";
        }
    '
}

case "$MODO" in
    snapshot)
        ejecutar_snapshot
        ESTADO=$?
        ;;
    alertas)
        ejecutar_alertas
        ESTADO=$?
        ;;
    todo)
        ejecutar_snapshot
        ESTADO=$?
        if [ "$ESTADO" -eq 0 ]; then
            ejecutar_alertas
            ESTADO=$?
        else
            echo "[$(fecha)] Alertas omitidas: el snapshot falló (código $ESTADO)."
        fi
        ;;
    estado)
        mostrar_estado
        exit $?
        ;;
esac

echo "[$(fecha)] Fin · modo=$MODO · código=$ESTADO"

if [ "$ES_TTY" -eq 1 ]; then
    tail -n 25 "$LOG" >&3 2>/dev/null || true
fi

exit "$ESTADO"
