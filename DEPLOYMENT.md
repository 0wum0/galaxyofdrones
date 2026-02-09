# Galaxy of Drones - Hostinger Shared Hosting Deployment Guide

## Voraussetzungen

- Hostinger Shared Hosting (hPanel) mit SSH-Zugang
- PHP >= 8.1 (empfohlen: 8.3 oder 8.4)
- MySQL / MariaDB Datenbank
- Composer (verfuegbar ueber Hostinger SSH)

## Variante A: DocumentRoot auf /public zeigt (empfohlen)

Bei Hostinger kannst du im hPanel unter **Websites > Domain verwalten** den DocumentRoot
(auch "Webroot" genannt) auf das `/public` Unterverzeichnis setzen.

### Schritt 1: Dateien hochladen

```bash
# Per SSH verbinden
ssh u123456789@yourdomain.com

# In das Home-Verzeichnis wechseln
cd ~/

# Repository klonen oder Dateien per FTP/File Manager hochladen
# Option A: Git (wenn verfuegbar)
git clone https://github.com/youruser/galaxyofdrones.git domains/yourdomain.com

# Option B: ZIP hochladen und entpacken
# Lade die ZIP-Datei ueber den Hostinger File Manager hoch
# Entpacke sie in domains/yourdomain.com/
```

### Schritt 2: DocumentRoot setzen

Im hPanel:
1. Gehe zu **Websites** > **Verwalten**
2. Unter **Erweitert** > **Ordnerindex** oder ueber SSH:

```bash
# Die public_html sollte auf /public zeigen
# Bei Hostinger: Erstelle einen Symlink
cd ~/domains/yourdomain.com
# DocumentRoot ist typischerweise public_html
# Wenn public_html der Root ist, loesche es und erstelle Symlink:
rm -rf public_html
ln -s /home/u123456789/domains/yourdomain.com/public public_html
```

### Schritt 3: Berechtigungen setzen

```bash
cd ~/domains/yourdomain.com

# Storage und Cache beschreibbar machen
chmod -R 775 storage
chmod -R 775 bootstrap/cache

# Sicherstellen, dass .env nicht oeffentlich ist
chmod 600 .env
```

### Schritt 4: Composer installieren

```bash
cd ~/domains/yourdomain.com

# Composer install (ohne Dev-Abhaengigkeiten fuer Production)
composer install --no-dev --optimize-autoloader --no-interaction
```

### Schritt 5: Web-Installer nutzen

1. Oeffne `https://yourdomain.com/install` im Browser
2. Der Installer prueft automatisch:
   - PHP Version und Extensions
   - Schreibrechte auf storage/ und bootstrap/cache/
3. Gib die Datenbank-Zugangsdaten ein (aus hPanel > Datenbanken)
4. Der Installer erstellt die .env, generiert den APP_KEY
5. Migrationen und Seeder werden automatisch ausgefuehrt
6. Erstelle einen Admin-Benutzer
7. Fertig! Der Installer sperrt sich selbst.

### Schritt 6: Cron-Job einrichten

Im hPanel unter **Erweitert** > **Cron Jobs**:

**Option A: Shell-Cron (empfohlen, falls verfuegbar)**

```
* * * * * cd /home/u123456789/domains/yourdomain.com && php artisan schedule:run >> /dev/null 2>&1
```

Oder nutze das mitgelieferte Script:
```
* * * * * /home/u123456789/domains/yourdomain.com/scripts/cron.sh >> /dev/null 2>&1
```

**Option B: HTTP-Cron ueber URL (fuer eingeschraenkte Hosting-Plaene)**

Falls du keinen Shell-Cron hast (z.B. nur URL-Cron erlaubt):

1. Der Installer generiert automatisch ein `CRON_TOKEN` in der `.env`
2. Richte im hPanel (oder bei cron-job.org) folgenden HTTP-Aufruf ein:

```
Intervall: Jede Minute (* * * * *)
URL:       https://yourdomain.com/cron/tick?token=DEIN_CRON_TOKEN
Methode:   GET
```

Das Token findest du in deiner `.env` unter `CRON_TOKEN=...`

**Wichtige Hinweise:**
- Der Game-Tick hat einen eingebauten Lock-Mechanismus. Doppelte Ausfuehrungen
  werden automatisch verhindert (per Cache-Lock, 5 Min Timeout).
- Der HTTP-Cron Endpoint ist rate-limited (max 2 Aufrufe/Minute).
- Der Schedule fuehrt neben `game:tick` auch Expedition/Mission-Generierung (alle 6h)
  und Ranking-Updates (stuendlich) aus. Bei HTTP-Cron laeuft NUR der Game-Tick.
  Fuer vollstaendige Funktionalitaet wird Shell-Cron empfohlen.

**Option C: HTTP-Cron fuer Root-Webroot (Variante B Deployment)**

Wenn das Projekt NICHT im DocumentRoot liegt sondern nur public_html:
Die URL bleibt gleich: `https://yourdomain.com/cron/tick?token=...`
Der Webserver routet automatisch ueber public_html/index.php zum Controller.

---

## Variante B: DocumentRoot kann NICHT geaendert werden

Falls der DocumentRoot zwingend auf `public_html` zeigt und nicht geaendert werden kann:

### Schritt 1: Projektstruktur anpassen

```bash
cd ~/

# Projekt in einen Ordner AUSSERHALB von public_html legen
mkdir -p app_files
# Entpacke/klone alles nach app_files/
git clone https://github.com/youruser/galaxyofdrones.git app_files

# Kopiere NUR den Inhalt von /public nach public_html
cp -r app_files/public/* public_html/
cp app_files/public/.htaccess public_html/
```

### Schritt 2: index.php anpassen

Editiere `public_html/index.php` und aendere die Pfade:

```php
// VORHER:
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

// NACHHER:
require '/home/u123456789/app_files/vendor/autoload.php';
$app = require_once '/home/u123456789/app_files/bootstrap/app.php';
```

### Schritt 3: Maintenance-Pfad anpassen

In derselben `public_html/index.php`:

```php
// VORHER:
if (file_exists(__DIR__.'/../storage/framework/maintenance.php')) {
    require __DIR__.'/../storage/framework/maintenance.php';
}

// NACHHER:
if (file_exists('/home/u123456789/app_files/storage/framework/maintenance.php')) {
    require '/home/u123456789/app_files/storage/framework/maintenance.php';
}
```

### Schritt 4: Rest wie Variante A

Fuehre Schritte 3-6 aus Variante A aus, aber im `app_files/` Verzeichnis:

```bash
cd ~/app_files
chmod -R 775 storage bootstrap/cache
composer install --no-dev --optimize-autoloader
```

---

## Datenbank erstellen

Im hPanel:
1. Gehe zu **Datenbanken** > **MySQL-Datenbanken**
2. Erstelle eine neue Datenbank
3. Erstelle einen Benutzer mit vollem Zugriff
4. Notiere: DB-Name, Benutzername, Passwort, Host (meist `localhost`)

---

## PHP-Version einstellen

Im hPanel:
1. Gehe zu **Erweitert** > **PHP Konfiguration**
2. Waehle PHP 8.3 oder 8.4
3. Aktiviere folgende Extensions:
   - pdo_mysql
   - mbstring
   - openssl
   - tokenizer
   - ctype
   - json
   - fileinfo
   - xml
   - curl
   - imagick (optional, fuer Starmap-Rendering)

---

## Cache erneuern (nach Updates)

```bash
cd ~/domains/yourdomain.com  # oder ~/app_files
php artisan config:cache
php artisan view:cache

# Optional: Route-Cache (nur moeglich wenn keine Closure-Routes in web.php/api.php)
php artisan route:cache
```

## Fehlerbehebung

### 500 Internal Server Error
```bash
# Pruefe die Logs
cat storage/logs/laravel.log | tail -50

# Cache leeren
php artisan config:clear
php artisan cache:clear
php artisan route:clear
```

### Storage-Symlink erstellen
```bash
php artisan storage:link
```

### Berechtigungsprobleme
```bash
chmod -R 775 storage bootstrap/cache
```

---

## Sicherheitshinweise

- `.env` ist durch `.htaccess` geschuetzt und hat Berechtigung 600
- Der Web-Installer sperrt sich nach erfolgreicher Installation
- `APP_DEBUG=false` ist in Production gesetzt
- Alle sensiblen Dateien sind durch .htaccess blockiert
- Aendere regelmaessig das `CRON_TOKEN` in der .env
