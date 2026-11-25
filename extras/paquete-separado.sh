#!/bin/sh
# Borealis 1.16.6 · ZIP «separado» para servidores Nginx donde no se puede apuntar la web a public/.
#
#   sh extras/paquete-separado.sh salida.zip [copia.sql]
#
# Deja en borealis/ solo los archivos públicos (index.php, assets/, sw.js…) y todo lo demás
# (app/, config/, database/, storage/, bin/) en borealis/nucleo-<32 hex>/, una carpeta con nombre
# secreto y sin listado. borealis/nucleo.php dice dónde está. Si el FTP deja subir algo FUERA de la
# carpeta web, se puede mover nucleo-…/ allí y cambiar la ruta en nucleo.php (absoluta).
# tests/, docs/ y extras/ no se incluyen.
set -eu
out=$(realpath -m "$1"); sql=${2:-}
repo=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
hex=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
core="nucleo-$hex"
mkdir -p "$tmp/full" "$tmp/pkg/borealis/$core"
(cd "$repo" && git archive HEAD) | tar -x -C "$tmp/full"
cp -a "$tmp/full/public/." "$tmp/pkg/borealis/"
rm -f "$tmp/pkg/borealis/router.php"   # solo para «php -S» en desarrollo
for d in app bin config database storage; do cp -a "$tmp/full/$d" "$tmp/pkg/borealis/$core/"; done
cp "$tmp/full/CHANGELOG.md" "$tmp/pkg/borealis/$core/"
printf 'Require all denied\nDeny from all\n' > "$tmp/pkg/borealis/$core/.htaccess"
: > "$tmp/pkg/borealis/$core/index.html"
for d in app bin config database database/migrations; do [ -f "$tmp/pkg/borealis/$core/$d/index.html" ] || : > "$tmp/pkg/borealis/$core/$d/index.html"; done
printf "<?php\n// Borealis: dónde está el resto de la aplicación (relativo a esta carpeta, o una ruta absoluta).\nreturn '%s';\n" "$core" > "$tmp/pkg/borealis/nucleo.php"
cat > "$tmp/pkg/borealis/index.html" <<'HTML'
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Borealis</title>
<meta http-equiv="refresh" content="0; url=index.php"><script>location.replace('index.php' + location.search + location.hash);</script>
</head><body style="font:16px system-ui;margin:40px"><p><a href="index.php">Entrar a Borealis</a></p></body></html>
HTML
if [ -n "$sql" ]; then mkdir -p "$tmp/pkg/base-de-datos"; cp "$sql" "$tmp/pkg/base-de-datos/"; fi
rm -f "$out"; (cd "$tmp/pkg" && zip -qr -X "$out" .)
echo "$out · carpeta del núcleo: $core"
