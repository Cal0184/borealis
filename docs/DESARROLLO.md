# Guía para trabajar en Borealis en un computador local

Esta guía es para la persona que va a mantener o mejorar Borealis. Explica cómo tenerlo funcionando en su propio computador, dónde está cada cosa, cómo probar los cambios y cómo llevarlos al servidor real.

Para entender **qué es y qué riesgos tiene**, lee primero [`AUDITORIA-EXTERNA.md`](AUDITORIA-EXTERNA.md). Para la configuración del servidor, ver el [`README.md`](../README.md).

---

## 1. Qué necesitas instalar

| Programa | Versión | Para qué |
|---|---|---|
| PHP | 8.2 o superior, con `pdo_mysql`, `mbstring`, `fileinfo`, `iconv`, `curl`, `openssl`, `sodium`, `zip` y `gd` | Ejecutar Borealis |
| MariaDB o MySQL | MariaDB 10.6+ o MySQL 8 | Base de datos |
| Git | cualquiera | Bajar el código y subir cambios a GitHub |
| Node.js (opcional) | 18 o superior, con Playwright | Solo para las pruebas de navegador |

No hay `composer`, `npm install` ni paso de compilación: el código se usa tal cual.

**Windows:** la forma más simple es [Laragon](https://laragon.org/) o XAMPP, que traen PHP y MariaDB juntos. Revisa que la versión de PHP sea 8.2 o superior y activa las extensiones de la tabla en `php.ini`. Usa la terminal que trae Laragon, o Git Bash.

**macOS:** `brew install php mariadb git` y después `brew services start mariadb`.

**Linux (Debian/Ubuntu):**
```bash
sudo apt install php8.3-cli php8.3-mysql php8.3-mbstring php8.3-curl php8.3-zip php8.3-gd php8.3-xml mariadb-server git
```

---

## 2. Tenerlo funcionando (10 minutos)

```bash
# 1. Bajar el código
git clone https://github.com/Cal0184/borealis.git
cd borealis

# 2. Crear una base de datos vacía y un usuario (ejemplo con MariaDB)
sudo mysql -e "CREATE DATABASE borealis_local CHARACTER SET utf8mb4;
               CREATE USER 'borealis'@'127.0.0.1' IDENTIFIED BY 'clave-local';
               GRANT ALL ON borealis_local.* TO 'borealis'@'127.0.0.1';"

# 3. Arrancar el servidor de PHP
php -S 127.0.0.1:8080 -t public public/router.php
```

4. Abre <http://127.0.0.1:8080>. Aparece el **instalador**: escribe servidor `127.0.0.1`, la base `borealis_local`, el usuario y la clave del paso 2, y crea tu cuenta de administración.
5. El instalador crea `config/config.php`. A partir de ahí, cada vez que quieras trabajar solo repites el paso 3.

**Para empezar de cero:** detén el servidor, borra `config/config.php`, vacía `storage/` (deja los `.gitkeep` y `.htaccess`, por ejemplo con `git clean -fdx storage/`) y borra y vuelve a crear la base de datos. Si queda algo en `storage/`, el instalador no se abre y lo dice; para forzarlo, crea un archivo vacío `storage/install.allow`.

**Ver los errores:** en `config/config.php`, pon `'debug' => true` (nunca en el servidor real). Los errores también quedan en `storage/logs/error.log`.

**Avisos y tareas programadas en local:** `php bin/notify.php` ejecuta una vez la tarea de avisos (recordatorios, correos, push, monitoreo). El servidor de PHP de prueba no puede correr el motor interno de avisos, porque atiende una petición a la vez.

---

## 3. Dónde está cada cosa

```
public/index.php        Única entrada de todas las páginas
app/routes.php          Lista de todas las direcciones → qué controlador responde
app/Core/               Motor propio: Router, Request/Response, Database (PDO), Session, Csrf, Migrator, View
app/Security/Policy.php Permisos: TODO acceso a datos pasa por aquí
app/Services/           Lógica de negocio (tareas, proyectos, avisos, copias, correo…)
app/Controllers/        Páginas HTML
app/Api/                Respuestas JSON que usa el JavaScript
app/Views/              Plantillas PHP (layouts, partials, pages)
public/assets/          CSS, JavaScript (sin librerías) e iconos
database/migrations/    Cambios de la base de datos, numerados (001…017)
bin/                    Scripts de consola: notify.php, backup.php, migrate.php…
tests/                  Pruebas (ver sección 5)
config/config.php       Configuración local (NO va a git)
storage/                Datos locales: llave, copias, archivos subidos (NO va a git)
```

**Recorrido de un cambio típico** (por ejemplo, un botón nuevo que guarda algo):
1. La ruta en `app/routes.php`, por ejemplo `$r->post('/api/algo', [Api\XApi::class, 'metodo']);`. Las rutas exigen sesión y token CSRF salvo que digan `'public' => true`.
2. El método en `app/Api/…` lee la petición y llama a un servicio.
3. El servicio en `app/Services/…` comprueba permisos con `Policy::authorize…` y escribe en la base con consultas preparadas (`Database::get()->run('… ?', [$valor])`).
4. La vista en `app/Views/…` muestra el botón. Todo texto se escapa con `e()`. Un botón con `data-call="/api/algo"` o un formulario con `data-api="/api/algo"` llaman a la API sin escribir JavaScript.

**Reglas que no hay que romper:**
- Nunca concatenar datos de la persona en SQL: siempre `?`.
- Nunca mostrar datos sin `e()`.
- Toda consulta filtra por el espacio de trabajo (`Policy::wsId()`) y comprueba el proyecto.
- No hay JavaScript dentro del HTML: la política de seguridad (CSP) lo bloquea. Todo va en `public/assets/js/`.

---

## 4. Cambios en la base de datos

1. Crea `database/migrations/018_lo_que_sea.sql` (el número siguiente). Solo agrega cosas, nunca borres datos de usuarios.
2. Al entrar, Borealis ve la migración pendiente y la administración pulsa «Actualizar base de datos». Antes de aplicarla crea una copia. Desde la consola: `php bin/migrate.php`.
3. El código nuevo no debe consultar columnas nuevas antes de que la migración se aplique (ver `app/Controllers/UpdateGate.php`).

---

## 5. Pruebas

Las pruebas de integración **borran e instalan desde cero**: úsalas solo con una base de datos vacía de pruebas, nunca con la real ni con la de trabajo.

```bash
# Unitarias (sin servidor ni base): cada una imprime {"ok":true,...}
for f in tests/unit/*.php; do php "$f"; done

# Integración: instalación y uso completo por HTTP (~25 minutos, unas 1.200 comprobaciones)
#   requiere: config/config.php inexistente, storage/ vacío y una base VACÍA
mysql -e "DROP DATABASE IF EXISTS borealis_test; CREATE DATABASE borealis_test CHARACTER SET utf8mb4;"
php -S 127.0.0.1:8080 -t public public/router.php &
BOREALIS_URL=http://127.0.0.1:8080 BOREALIS_TEST_DB_HOST=127.0.0.1 \
BOREALIS_TEST_DB_NAME=borealis_test BOREALIS_TEST_DB_USER=borealis BOREALIS_TEST_DB_PASS=clave-local \
  php tests/integration/run.php --json=resultados.json

# Navegador (Playwright + Chromium), con una instalación nueva igual que la anterior
npm install -g playwright && npx playwright install chromium
NODE_PATH=$(npm root -g) BOREALIS_URL=http://127.0.0.1:8080 BOREALIS_TEST_DB_NAME=… node tests/e2e/run.mjs
```

- Cada versión agrega su archivo en `tests/integration/` (`v1164.php`, etc.) y lo incluye al final de `run.php`.
- Google, correo, avisos push, Discord y GitHub se prueban contra servidores falsos locales (`tests/fake-google`, `tests/fake-services`). Funcionar en las pruebas no garantiza que funcione con el servicio real.
- `tests/fpm/engine.sh` prueba el motor de avisos con Nginx+PHP-FPM o Apache reales. Necesita Linux con permisos de administrador.
- **En GitHub** las pruebas unitarias y de integración corren solas en cada cambio (`.github/workflows/pruebas.yml`). El resultado aparece en la pestaña «Actions» y en cada pull request.

---

## 6. Trabajar con GitHub

- `main` tiene la versión publicada. Cada cambio va en una rama (`git checkout -b arreglo-x`) y entra por un *pull request*, que muestra si las pruebas pasaron.
- **Nunca** subas `config/config.php` ni nada de `storage/`: el `.gitignore` ya lo impide. Si ves uno de ellos en `git status`, no lo agregues.
- Al terminar un cambio visible, anótalo arriba en `CHANGELOG.md`.

**Publicar una versión nueva:**
1. Cambia el número en `app/bootstrap.php` (`BOREALIS_VERSION`) y en `public/sw.js` (`VERSION`).
2. `php bin/stamp-assets.php` agrega la versión a los JavaScript, para que los navegadores no usen los viejos.
3. Corre las pruebas.
4. Arma el ZIP **desde git**, para que no se cuele nada local:
   ```bash
   git archive --format=zip --prefix=borealis/ -o borealis-X.Y.Z.zip HEAD
   unzip -l borealis-X.Y.Z.zip | grep -E "config/|storage/"   # solo deben aparecer .htaccess, .gitkeep y config.example.php
   ```

---

## 7. Llevar los cambios al servidor real

El hosting actual solo permite FTP y administrar la base de datos: no hay terminal ni tareas programadas (cron).

1. **Copia primero:** en Borealis, Administración › Datos › «Descargar copia completa (.zip)». Guárdala fuera del servidor.
2. Descomprime el ZIP de la versión y súbelo con FileZilla **encima** de lo que hay. **No borres ni reemplaces** `config/config.php` ni la carpeta `storage/`.
3. Entra como administración. Si hay migraciones, aparece «Actualizar base de datos».
4. Revisa Administración › Datos › «Seguridad de la instalación» (debe estar en verde) y Administración › Notificaciones (el motor de avisos).

**Avisos sin cron:** para que los recordatorios salgan siempre, configura un servicio externo gratuito (por ejemplo cron-job.org) que visite cada 1 a 5 minutos la dirección que muestra Administración › Notificaciones (`/cron/run?token=…`). Ese token es secreto.

**Restaurar una copia:** importa el `.sql` en la base (phpMyAdmin › Importar) y sube `config/config.php` y `storage/` del ZIP completo.

---

## 8. Secretos: qué no se puede perder ni publicar

| Archivo | Qué es |
|---|---|
| `config/config.php` | Contraseña de la base de datos y, en instalaciones nuevas, la llave de cifrado (`app.key`) |
| `storage/app.key` | Llave de cifrado (instalaciones anteriores a 1.16.4) |
| `storage/vapid.json`, `cron.json`, `mail.json` | Llaves de los avisos push, token de `/cron/run` y configuración de correo (cifrados con la llave) |
| `storage/uploads/` | Archivos y fotos que subieron las personas |
| `storage/backups/` | Copias completas de la base de datos |

Sin la llave, Borealis funciona, pero hay que volver a configurar el correo, Google y los webhooks, y cada persona debe reactivar sus avisos.
