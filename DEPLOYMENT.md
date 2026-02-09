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

## QA / Smoke Tests (Checkliste nach Deployment)

Nach dem Deployment und vor dem produktiven Betrieb sollten diese Tests durchgefuehrt werden:

### 1. Web-Tests (im Browser)

| Test | URL / Aktion | Erwartetes Ergebnis |
|------|-------------|---------------------|
| Installer oeffnen | `https://god.makeit.uno/install` | Requirements-Seite wird angezeigt (kein 500) |
| DB Step | Datenbank-Formular ausfuellen + "Test Connection" | JSON-Antwort "Connection successful" (kein 419) |
| DB Step POST | Formular absenden | Weiterleitung zu Migrate-Step (kein 419 Page Expired) |
| Migrate Step | Automatisch nach DB Step | Migrationen + Seeder laufen durch (gruen) |
| Admin erstellen | Username/Email/Passwort eingeben | Admin-User wird erstellt, Weiterleitung zu Complete |
| Complete | Nach Admin-Erstellung | Cron-Token wird angezeigt, Lock-File erstellt |
| Startseite | `https://god.makeit.uno/` | Login/Register-Seite (nach Installation) |
| Admin Panel | `https://god.makeit.uno/admin` | Admin Dashboard (nach Login) |

### 2. Terminal/SSH-Tests

```bash
cd ~/domains/god.makeit.uno   # oder entsprechendes Projektverzeichnis

# Test 1: HTTPS-Erkennung hinter Proxy
php artisan tinker --execute="dump(request()->isSecure());"
# Erwartung: true (wenn ueber HTTPS aufgerufen)

# Test 2: DB-Verbindung
php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
try {
    \$pdo = DB::connection()->getPdo();
    echo 'DB OK: ' . \$pdo->getAttribute(PDO::ATTR_SERVER_INFO) . PHP_EOL;
} catch (Exception \$e) {
    echo 'DB FEHLER: ' . \$e->getMessage() . PHP_EOL;
}
"

# Test 3: .env korrekt geladen
php artisan tinker --execute="dump(config('app.env'), config('app.url'), config('database.connections.mysql.host'));"

# Test 4: Session-Verzeichnis beschreibbar
php -r "echo is_writable('storage/framework/sessions') ? 'Sessions: OK' : 'Sessions: NICHT SCHREIBBAR'; echo PHP_EOL;"

# Test 5: APP_KEY gesetzt
php artisan tinker --execute="dump(config('app.key'));"
# Erwartung: base64:... (kein leerer Wert)

# Test 6: Cron-Endpoint testen
curl -s -o /dev/null -w "%{http_code}" "https://god.makeit.uno/cron/tick?token=DEIN_TOKEN"
# Erwartung: 200 (mit korrektem Token) oder 403 (mit falschem Token)
```

### 3. Sicherheits-Schnelltest

```bash
# Sensitive Pfade muessen blockiert sein (403 oder 404):
for path in .env .git/config vendor/autoload.php storage/logs/laravel.log \
  bootstrap/app.php config/app.php database/ routes/web.php \
  resources/ app/Models/User.php composer.json artisan; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" "https://god.makeit.uno/$path")
  echo "$STATUS - /$path"
done
# Alle muessen 403 oder 404 sein, NICHT 200
```

---

## Fehlerbehebung / Troubleshooting

### 500 Internal Server Error
```bash
# Pruefe die Logs
tail -50 storage/logs/laravel.log

# Cache leeren
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

**Haeufige Ursache: TrustProxies**

Wenn im Log steht:
```
Undefined constant Illuminate\Http\Request::HEADER_X_FORWARDED_ALL
```

**Fix:** Die Datei `app/Http/Middleware/TrustProxies.php` muss die Symfony-Konstanten
verwenden statt `Illuminate\Http\Request::HEADER_X_FORWARDED_ALL` (wurde in Symfony 6 entfernt):

```php
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

protected $headers =
    SymfonyRequest::HEADER_X_FORWARDED_FOR |
    SymfonyRequest::HEADER_X_FORWARDED_HOST |
    SymfonyRequest::HEADER_X_FORWARDED_PORT |
    SymfonyRequest::HEADER_X_FORWARDED_PROTO |
    SymfonyRequest::HEADER_X_FORWARDED_PREFIX;
```

### 419 Page Expired (CSRF Token Mismatch)

Moegliche Ursachen und Loesungen:

1. **Session-Verzeichnis nicht beschreibbar:**
   ```bash
   mkdir -p storage/framework/sessions storage/framework/cache storage/framework/views
   chmod -R 775 storage/framework/sessions
   chmod -R 775 storage
   ```

2. **TrustProxies fehlerhaft:** Wenn HTTPS nicht korrekt erkannt wird,
   generiert Laravel Session-Cookies mit falschen Secure-Flags.
   → Siehe TrustProxies-Fix oben.

3. **Session-Cookie Konfiguration:** Pruefe diese Werte in `.env`:
   ```
   SESSION_DRIVER=file
   SESSION_DOMAIN=.makeit.uno
   SESSION_SECURE_COOKIE=true
   SESSION_SAME_SITE=lax
   ```
   - `SESSION_DOMAIN` mit fuehrendem Punkt fuer Subdomain-Kompatibilitaet
   - `SESSION_SECURE_COOKIE=true` nur bei HTTPS (oder `null` fuer Auto-Erkennung)
   - `SESSION_SAME_SITE=lax` ist der sicherste Default fuer normale Forms

4. **Config-Cache veraltet (haeufigste Ursache!):**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   php artisan config:cache
   ```
   **Wichtig:** `config:cache` darf erst nach korrekt geschriebener `.env` laufen!
   Wenn `config:cache` mit falschen Werten gecacht wurde, erhaelt man 419 auf
   allen POST-Requests (Login, Forms, Installer).

5. **Formular hat kein @csrf Token:** Alle POST-Formulare muessen
   `@csrf` enthalten (ist in den Installer-Views bereits vorhanden).

6. **Browser-Cookies loeschen:** Nach Aenderung der Session-Konfiguration
   muessen alte Cookies im Browser geloescht werden (oder Inkognito-Tab nutzen).

### Storage-Symlink erstellen
```bash
php artisan storage:link
```

### Berechtigungsprobleme
```bash
chmod -R 775 storage bootstrap/cache
```

### .env Passwort mit Sonderzeichen

Wenn `DB_PASSWORD` Sonderzeichen enthaelt (`$`, `#`, `"`, Leerzeichen),
muss der Wert in der `.env` in doppelten Anfuehrungszeichen stehen.
Der Web-Installer macht das automatisch. Bei manueller Bearbeitung:

```
# Richtig:
DB_PASSWORD="mein$uper#passwort"

# Falsch ($ wird als Variable interpretiert):
DB_PASSWORD=mein$uper#passwort
```

### Route-Cache Fehler

Falls `php artisan route:cache` fehlschlaegt mit "Unable to prepare route
for serialization. Uses Closure.": Das ist normal. Closure-basierte Routen
(z.B. in console.php) koennen nicht gecacht werden. Verwende stattdessen:

```bash
php artisan config:cache
php artisan view:cache
# route:cache NICHT ausfuehren!
```

---

## Deployment-sicheres package:discover

### Warum package:discover beim Erstdeploy geskippt wird

Beim ersten `composer install` auf Hostinger Shared Hosting existiert weder eine `.env`-Datei
noch ein `APP_KEY`. Diese werden erst durch den Web-Installer (`/install`) erzeugt. Laravels
`package:discover`-Befehl bootet jedoch das gesamte Framework – ohne gueltige `.env` schlaegt
dieser Boot fehl und Composer bricht mit dem Fehler ab:

```
Script @php artisan package:discover --ansi returned with error code 1
```

**Loesung:** In `composer.json` wurde der direkte Artisan-Aufruf durch ein Guard-Script
ersetzt (`scripts/post_autoload.php`). Das Script prueft folgende Bedingung, bevor es
`package:discover` ausfuehrt:

1. **`.env` existiert** – wird vom Web-Installer oder manuell erstellt

Zusaetzlich ist der gesamte Script-Body in einen `try/catch(\Throwable)` Block gewrapped,
sodass selbst unerwartete PHP-Fehler niemals als Exit-Code 255 an Composer weitergereicht
werden. Das Script beendet sich **immer** mit Exit-Code 0.

> **Wichtig:** Das Script haengt **nicht** von `storage/installed.lock` oder anderem
> untracked State ab. `installed.lock` wird vom Web-Installer nach erfolgreicher
> Installation erstellt und darf **niemals** ins Git-Repository committed werden.
> Das Repo muss sauber bleiben – `installed.lock` gehoert in `.gitignore`.

Wenn `.env` nicht vorhanden ist, beendet sich das Script sauber mit Exit-Code 0.
Dadurch laeuft `composer install` auf einem frischen Server fehlerfrei durch.

### Was nach der Installation passiert

Nachdem der Web-Installer alle Schritte abgeschlossen hat (Datenbank, Migrationen, Admin-User),
fuehrt er automatisch folgende Artisan-Befehle aus:

1. `config:clear` – entfernt veraltete Config-Caches
2. `route:clear` – entfernt veraltete Route-Caches
3. `view:clear` – entfernt veraltete View-Caches
4. `package:discover --ansi` – erkennt und registriert alle Laravel-Pakete
5. `config:cache` – erstellt einen frischen Config-Cache
6. `view:cache` – kompiliert alle Blade-Views vor

Damit ist die Anwendung nach Abschluss des Installers sofort betriebsbereit.

### Wie man manuell package:discover ausfuehrt

Falls noetig (z.B. nach einem manuellen Paket-Update), kann `package:discover` jederzeit
per SSH ausgefuehrt werden:

```bash
cd ~/domains/yourdomain.com   # Projektverzeichnis
php artisan package:discover --ansi
```

**Voraussetzung:** `.env` muss vorhanden sein und einen gueltigen `APP_KEY` enthalten.

Bei Problemen hilft folgender Reset:

```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan package:discover --ansi
php artisan config:cache
php artisan view:cache
```

---

## Hinweis: installed.lock und sauberes Repository

> **Keep repo clean; `installed.lock` must be created post-install, never committed.**

Die Datei `storage/installed.lock` wird vom Web-Installer nach erfolgreicher Installation
angelegt. Sie signalisiert der Anwendung, dass die Ersteinrichtung abgeschlossen ist.

**Wichtige Regeln:**
- `installed.lock` wird **nach** `composer install` durch den Web-Installer erstellt
- Die Datei darf **niemals** ins Git-Repository committed werden
- Sie ist bereits in `.gitignore` eingetragen
- Das `post_autoload.php` Script haengt **nicht** von `installed.lock` ab –
  es prueft ausschliesslich die Existenz von `.env`
- Beim Erstdeploy (clean checkout ohne `.env`) laeuft `composer install` fehlerfrei
  durch, weil das Script sauber mit Exit-Code 0 beendet

---

## Sicherheitshinweise

- `.env` ist durch `.htaccess` geschuetzt und hat Berechtigung 600
- Der Web-Installer sperrt sich nach erfolgreicher Installation
- `APP_DEBUG=false` ist in Production gesetzt
- Alle sensiblen Dateien/Verzeichnisse sind durch .htaccess blockiert
- Aendere regelmaessig das `CRON_TOKEN` in der .env
- Security Headers aktiv: HSTS, X-Frame-Options, X-Content-Type-Options
- Gzip-Komprimierung und Browser-Caching fuer Assets aktiviert

### .htaccess Sicherheitstest

Nach dem Deployment sollten folgende URLs **403 Forbidden** oder **404** zurueckgeben:

```
# Diese URLs MUESSEN blockiert sein (403/404):
https://yourdomain.com/.env                    # 403 - Umgebungsvariablen
https://yourdomain.com/.git/config             # 403 - Git Repository
https://yourdomain.com/.git/HEAD               # 403 - Git Repository
https://yourdomain.com/vendor/autoload.php     # 403 - Composer Vendor
https://yourdomain.com/storage/logs/laravel.log # 403 - Logdateien
https://yourdomain.com/bootstrap/app.php       # 403 - Bootstrap
https://yourdomain.com/config/app.php          # 403 - Konfiguration
https://yourdomain.com/config/database.php     # 403 - DB-Konfiguration
https://yourdomain.com/database/               # 403 - Migrationen
https://yourdomain.com/routes/web.php          # 403 - Routen
https://yourdomain.com/resources/              # 403 - Resources
https://yourdomain.com/app/Models/User.php     # 403 - Anwendungscode
https://yourdomain.com/composer.json           # 403 - Composer Config
https://yourdomain.com/composer.lock           # 403 - Composer Lock
https://yourdomain.com/artisan                 # 403 - Artisan CLI
https://yourdomain.com/.env.example            # 403 - Env Beispiel
https://yourdomain.com/phpunit.xml             # 403 - PHPUnit Config

# Diese URLs MUESSEN erreichbar sein (200):
https://yourdomain.com/                        # 200 - Startseite
https://yourdomain.com/login                   # 200 - Login
https://yourdomain.com/register                # 200 - Registrierung
https://yourdomain.com/robots.txt              # 200 - Robots
https://yourdomain.com/cron/tick?token=X       # 403 - Cron (falsches Token)
```

Test-Befehl (per SSH oder lokal):
```bash
# Schnelltest aller blockierten Pfade:
for path in .env .git/config vendor/autoload.php storage/logs/laravel.log \
  bootstrap/app.php config/app.php database/ routes/web.php \
  resources/ app/Models/User.php composer.json artisan; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://yourdomain.com/$path)
  echo "$STATUS - /$path"
done
```

---

## Kurzanleitung: Deployment auf Hostinger (Schritt fuer Schritt)

### 1. Dateien deployen

```bash
# Per SSH verbinden und Projekt hochladen
ssh u123456789@god.makeit.uno
cd ~/domains/god.makeit.uno   # oder public_html Verzeichnis

# Git Clone oder ZIP entpacken
git clone https://github.com/youruser/galaxyofdrones.git .
# ODER: Dateien per FTP/File Manager hochladen
```

### 2. Composer install

```bash
cd ~/domains/god.makeit.uno

# Wenn sodium Extension fehlt:
# Im hPanel: PHP Konfiguration > Extensions > sodium aktivieren
# Oder --ignore-platform-req=ext-sodium hinzufuegen

composer install --no-dev --optimize-autoloader --no-interaction
```

**Hinweis:** `package:discover` wird beim ersten Install automatisch
uebersprungen (kein .env vorhanden). Das ist beabsichtigt – der Web-Installer
fuehrt es spaeter aus.

### 3. Rechte setzen

```bash
chmod -R 775 storage
chmod -R 775 bootstrap/cache
# Sicherstellen dass sessions-Verzeichnis existiert:
mkdir -p storage/framework/sessions
mkdir -p storage/framework/cache
mkdir -p storage/framework/views
mkdir -p storage/logs
```

### 4. Web-Installer durchklicken

1. Oeffne `https://god.makeit.uno/install`
2. Requirements pruefen (alle gruen = OK)
3. Datenbank-Zugangsdaten eingeben → "Test Connection" → "Next: Install"
4. Migrationen laufen automatisch
5. Admin-Benutzer erstellen
6. Fertig! Cron-Token notieren.

### 5. Cache-Befehle (nach Installation)

```bash
# Nur wenn du .env manuell aenderst:
php artisan config:clear
php artisan config:cache
php artisan view:cache

# NICHT ausfuehren (Closure-Routes!):
# php artisan route:cache
```

### 6. Cron-Job einrichten

Im hPanel unter Erweitert > Cron Jobs:

```
* * * * * cd /home/u123456789/domains/god.makeit.uno && php artisan schedule:run >> /dev/null 2>&1
```

Oder per HTTP-Cron: `https://god.makeit.uno/cron/tick?token=DEIN_CRON_TOKEN`

---

## Hostinger Checkliste: 419-Fix (CSRF / Session)

Wenn nach dem Deployment 419 "Page Expired" Fehler auftreten, folge dieser Checkliste:

### Schritt 1: Storage-Verzeichnisse pruefen/erstellen

```bash
cd ~/domains/god.makeit.uno   # Projektverzeichnis

# Verzeichnisse erstellen falls fehlend
mkdir -p storage/framework/sessions
mkdir -p storage/framework/cache
mkdir -p storage/framework/views
mkdir -p storage/logs

# Berechtigungen setzen
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

### Schritt 2: Session-Werte in .env pruefen

Stelle sicher, dass diese Werte in der `.env` stehen:

```
SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_DOMAIN=.makeit.uno
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

**Hinweis:** `SESSION_SECURE_COOKIE=true` nur verwenden wenn die App ueber HTTPS laeuft.
Fuer automatische Erkennung `SESSION_SECURE_COOKIE=null` setzen.

### Schritt 3: Alle Caches loeschen und neu aufbauen

```bash
# WICHTIG: In dieser Reihenfolge!
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Dann neu cachen (erst NACH korrekter .env):
php artisan config:cache
php artisan view:cache
```

### Schritt 4: Browser-Cookies loeschen

1. Browser-Cookies fuer die Domain loeschen (oder Inkognito-Tab)
2. Seite komplett neu laden (Ctrl+Shift+R)
3. Login oder Installer erneut testen

### Schritt 5: Verifizieren

```bash
# Session-Verzeichnis beschreibbar?
php -r "echo is_writable('storage/framework/sessions') ? 'OK' : 'FEHLER'; echo PHP_EOL;"

# Config korrekt geladen?
php artisan tinker --execute="dump(config('session.domain'), config('session.secure'), config('session.same_site'));"
# Erwartung: ".makeit.uno", true, "lax"

# HTTPS-Erkennung hinter Proxy?
php artisan tinker --execute="dump(request()->isSecure());"
# Erwartung: true
```

### Typische Fehlerquellen

| Problem | Ursache | Loesung |
|---------|---------|---------|
| 419 auf allen POST | Config-Cache mit falschen Werten | `php artisan config:clear && php artisan config:cache` |
| 419 nur bei Login | Session-Verzeichnis nicht beschreibbar | `chmod -R 775 storage/framework/sessions` |
| 419 nach .env Aenderung | Alter Config-Cache aktiv | `php artisan config:clear` ausfuehren |
| Cookie wird nicht gesetzt | SECURE=true aber kein HTTPS | `SESSION_SECURE_COOKIE=null` in .env |
| Cookie verschwindet | SESSION_DOMAIN falsch | `SESSION_DOMAIN=.makeit.uno` (mit Punkt!) |
