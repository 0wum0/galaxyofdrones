# Galaxy of Drones - Shared Hosting Deployment Guide

## Uebersicht

Galaxy of Drones ist fuer Shared Hosting (z.B. Hostinger CloudLinux) optimiert:
- **Kein Docker** erforderlich
- **Kein Redis** erforderlich (file cache/session, queue sync)
- **Kein SSH** Pflicht – Installation komplett per Web-Installer
- **PHP 8.1-8.4** kompatibel, Laravel 10.x
- StarMap-Generierung im Browser (kein CLI noetig)

## Schnellstart: Download → Upload → /install → Fertig

### 1. Dateien hochladen

Lade das gesamte Projekt auf deinen Server (per FTP, File Manager, oder Git).

```
/home/username/domains/yourdomain.com/
├── app/
├── bootstrap/
├── config/
├── database/
├── public/          ← DocumentRoot sollte hierher zeigen
├── resources/
├── routes/
├── storage/
├── vendor/          ← wird per composer install erstellt
├── .htaccess        ← Root-Schutz falls DocumentRoot nicht /public ist
├── composer.json
└── ...
```

### 2. Composer install

Per SSH (falls verfuegbar):
```bash
cd ~/domains/yourdomain.com
composer install --no-dev --optimize-autoloader --no-interaction
```

**Hinweis:** `package:discover` wird beim ersten Install automatisch uebersprungen
(kein .env vorhanden). Das ist beabsichtigt – der Web-Installer erledigt das spaeter.

Falls kein SSH: Fuehre `composer install` lokal aus und lade den `vendor/` Ordner per FTP hoch.

### 3. Berechtigungen setzen

```bash
chmod -R 775 storage bootstrap/cache
mkdir -p storage/framework/sessions storage/framework/cache storage/framework/views storage/logs
```

### 4. Web-Installer ausfuehren

Oeffne im Browser:
```
https://yourdomain.com/install
```

Der Installer fuehrt 7 Schritte durch:

| Step | Beschreibung |
|------|-------------|
| 1 | **System Check** – PHP Version, Extensions, Verzeichnis-Rechte |
| 2 | **Database** – MySQL-Zugangsdaten eingeben + testen |
| 3 | **Environment** – .env wird geschrieben, APP_KEY generiert |
| 4 | **Migrate** – Datenbank-Tabellen + Seeders werden ausgefuehrt |
| 5 | **StarMap** – Spielwelt (Sterne + Planeten) wird generiert |
| 6 | **Admin** – Administrator-Konto erstellen |
| 7 | **Complete** – Cron-Token + Installer-Token werden angezeigt |

**Nach Abschluss ist der Installer automatisch gesperrt.**

### 5. Cron-Job einrichten

**Option A: Shell-Cron (empfohlen)**

Im Hosting Control Panel (z.B. Hostinger hPanel > Erweitert > Cron Jobs):
```
* * * * * cd /home/username/domains/yourdomain.com && php artisan schedule:run >> /dev/null 2>&1
```

**Option B: HTTP-Cron (wenn kein Shell-Cron verfuegbar)**

Der Installer generiert ein `CRON_TOKEN`. Richte einen HTTP-Aufruf ein:
```
URL:      https://yourdomain.com/cron/tick?token=DEIN_CRON_TOKEN
Intervall: Jede Minute (* * * * *)
Methode:   GET
```

Der HTTP-Cron Endpoint fuehrt folgendes aus:
- `game:tick` – bei jedem Aufruf
- `expedition:generate` + `mission:generate` – alle 6 Stunden
- `rank:update` – jede Stunde

---

## DocumentRoot Konfiguration

### Variante A: DocumentRoot auf /public (empfohlen)

Bei Hostinger: Erstelle einen Symlink:
```bash
cd ~/domains/yourdomain.com
rm -rf public_html
ln -s /home/username/domains/yourdomain.com/public public_html
```

### Variante B: Root Deployment (DocumentRoot kann nicht geaendert werden)

Die Root `.htaccess` leitet automatisch alles auf `public/` weiter und blockiert
sensible Verzeichnisse (vendor/, storage/, config/, .env etc.).

---

## Installer Lock & Updater

### Lock-Mechanik

Nach erfolgreicher Installation wird `storage/installed.lock` erstellt.
Der Installer ist dann gesperrt und `/install` leitet auf die Startseite um.

### Installer entsperren (fuer Updates/Reparaturen)

Es gibt drei Wege:

1. **Token-URL:** `https://yourdomain.com/install?token=DEIN_INSTALL_TOKEN`
   (Token wird bei Installation angezeigt und steht in `.env` als `INSTALL_TOKEN`)

2. **Unlock-Datei:** Erstelle die Datei `storage/install.unlock`
   ```bash
   touch storage/install.unlock
   ```

3. **CRON_TOKEN** funktioniert ebenfalls als Unlock-Token

### Updater-Modus

Wenn der Installer entsperrt wird, zeigt er **Repair/Update Tools**:
- **Run Migrations** – Datenbank-Schema aktualisieren
- **Run Seeders** – Fehlende Grunddaten nachinstallieren (idempotent)
- **Rebuild Caches** – config:cache, view:cache, cache:clear
- **Clear Sessions** – Alle Sessions loeschen
- **Regenerate StarMap** – Neue Spielwelt generieren
- **Reinstall Passport** – OAuth-Keys neu erstellen

---

## Admin Panel

Erreichbar unter `/admin` (erfordert Admin-Login).

### Features:
- **Dashboard** – Statistiken: Users, Stars, Planets, aktive Events
- **Users** – Benutzerverwaltung, Admin-Rechte vergeben/entziehen
- **Game Settings** – Key/Value Settings fuer Spielkonfiguration
- **Logs** – Laravel-Logfile im Browser anzeigen
- **StarMap** – Sternenkarte generieren/regenerieren, Statistiken

---

## Troubleshooting

### 419 Page Expired (CSRF)

1. **Storage-Verzeichnisse pruefen:**
   ```bash
   mkdir -p storage/framework/sessions storage/framework/cache storage/framework/views
   chmod -R 775 storage
   ```

2. **Config-Cache erneuern:**
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```

3. **Browser-Cookies loeschen** (oder Inkognito-Tab nutzen)

4. **SESSION_DOMAIN in .env pruefen:**
   ```
   SESSION_DOMAIN=.yourdomain.com
   SESSION_SECURE_COOKIE=true    # nur bei HTTPS
   SESSION_SAME_SITE=lax
   ```

### 500 Internal Server Error

```bash
# Logs pruefen
tail -50 storage/logs/laravel.log

# Alle Caches leeren
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

### "Server is full" / Kein Spawn moeglich

Die StarMap wurde nicht generiert oder hat keine Starter-Planeten.

**Loesung A (Web):** Installer entsperren und "Regenerate StarMap" klicken
**Loesung B (Admin):** Admin Panel > StarMap > Regenerate
**Loesung C (SSH):**
```bash
php artisan game:generate-starmap --stars=2000 --planets-per-star=3 --clear --shared-hosting
```

### mix-manifest.json fehlt (500)

Alle `mix()` Aufrufe wurden durch `asset()` ersetzt. Dieses Problem sollte
nicht mehr auftreten. Falls doch:
```bash
# Pruefen ob noch mix() Aufrufe existieren
grep -r "mix(" resources/views/
```

### TrustProxies Fehler

Wird bereits mit Symfony 6 Konstanten gehandhabt:
```php
// In app/Http/Middleware/TrustProxies.php
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
protected $headers = SymfonyRequest::HEADER_X_FORWARDED_FOR | ...
```

---

## Dateien die NIEMALS committed werden duerfen

Diese Dateien sind in `.gitignore` eingetragen:
- `.env` – Umgebungsvariablen
- `storage/installed.lock` – Installations-Status
- `storage/install.unlock` – Installer-Entsperrung
- `vendor/` – Composer-Abhaengigkeiten
- `node_modules/` – NPM-Abhaengigkeiten
- `public/mix-manifest.json` – Build-Artefakt
- `public/tile/` – Generierte StarMap-Tiles

---

## Sicherheitshinweise

- `.env` ist durch `.htaccess` geschuetzt (403)
- Alle sensiblen Verzeichnisse sind blockiert (vendor/, storage/, config/, etc.)
- Der Installer sperrt sich automatisch nach Installation
- `APP_DEBUG=false` in Production
- CRON_TOKEN und INSTALL_TOKEN werden bei Installation generiert
- Session-Cookies: HttpOnly, Secure (bei HTTPS), SameSite=Lax
- Security Headers: HSTS, X-Frame-Options, X-Content-Type-Options

### Sicherheitstest

```bash
# Diese URLs MUESSEN 403/404 zurueckgeben:
for path in .env .git/config vendor/autoload.php storage/logs/laravel.log \
  config/app.php database/ routes/web.php artisan composer.json; do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://yourdomain.com/$path)
  echo "$STATUS - /$path"
done
```

---

## Technische Details

### Shared Hosting Defaults
```
CACHE_DRIVER=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
LOG_CHANNEL=single
BROADCAST_DRIVER=log
```

### Keine Abhaengigkeit auf:
- Redis / Memcached
- Laravel Horizon
- Laravel Websockets / Pusher (Broadcasting deaktiviert)
- Laravel Telescope
- Node.js / npm (Frontend ist vorgebaut)

### StarMap Generierung
- Artisan Command: `php artisan game:generate-starmap`
- Parameter: `--stars`, `--planets-per-star`, `--seed`, `--clear`, `--shared-hosting`
- Shared-Hosting Mode: Max 2000 Stars, Memory-Management, Chunked Inserts
- Speichert Metadaten in `game_settings` Tabelle
