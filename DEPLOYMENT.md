# Galaxy of Drones - Shared Hosting Deployment Guide

## Uebersicht

Galaxy of Drones ist fuer Shared Hosting (z.B. Hostinger CloudLinux) optimiert:
- **Kein Docker** erforderlich
- **Kein Redis** erforderlich (file cache/session, queue sync)
- **Kein SSH** Pflicht – Installation komplett per Web-Installer
- **Kein Node.js / npm Build auf dem Server** – Frontend-Assets werden per GitHub Actions gebaut und committed
- **PHP 8.1-8.4** kompatibel, Laravel 10.x
- StarMap-Generierung im Browser (kein CLI noetig)

---

## Zero-to-Play Checkliste

```
1. [ ] Dateien per FTP/Git auf den Server hochladen
2. [ ] composer install (per SSH oder lokal + vendor/ hochladen)
3. [ ] chmod -R 775 storage bootstrap/cache
4. [ ] Browser: https://yourdomain.com/install oeffnen
5. [ ] Step 1: System Check bestehen
6. [ ] Step 2: Datenbank-Zugangsdaten eingeben → "Test Connection" → "Next"
7. [ ] Step 3: Migrations + Seeders laufen automatisch
8. [ ] Step 4: StarMap generieren (empfohlen: 2000 Sterne)
9. [ ] Step 5: Admin-Konto erstellen
10. [ ] Step 6: Installation abgeschlossen – Tokens notieren!
11. [ ] Cron-Job einrichten (Shell oder HTTP)
12. [ ] Browser: https://yourdomain.com → Registrieren → Spielen!
```

---

## Schnellstart: Upload → /install → Fertig

### 1. Dateien hochladen

Lade das gesamte Projekt auf deinen Server (per FTP, File Manager, oder Git).

Beispiel Hostinger-Pfad:
```
/home/username/domains/yourdomain.com/public_html/god/
├── app/
├── bootstrap/
├── config/
├── database/
├── public/          ← Erreichbar via Root .htaccess Rewrite
├── resources/
├── routes/
├── storage/
├── vendor/          ← wird per composer install erstellt
├── .htaccess        ← Root-Schutz, leitet auf public/ um
├── composer.json
└── ...
```

### 2. Composer install

**Per SSH (falls verfuegbar):**
```bash
cd /home/username/domains/yourdomain.com/public_html/god
composer install --no-dev --optimize-autoloader --no-interaction
```

**Hinweis:** `package:discover` wird beim ersten Install automatisch uebersprungen
(kein .env vorhanden). Das ist beabsichtigt – der Web-Installer erledigt das spaeter.

**Falls kein SSH:** Fuehre `composer install` lokal aus und lade den `vendor/` Ordner per FTP hoch.

### 3. Berechtigungen setzen

```bash
chmod -R 775 storage bootstrap/cache
mkdir -p storage/framework/sessions storage/framework/cache/data storage/framework/views storage/logs
```

### 4. Web-Installer ausfuehren

Oeffne im Browser:
```
https://yourdomain.com/install
```

Der Installer fuehrt 6 Schritte durch:

| Step | Beschreibung |
|------|-------------|
| 1 | **System Check** – PHP Version, Extensions, Verzeichnis-Rechte |
| 2 | **Database** – MySQL-Zugangsdaten eingeben + testen |
| 3 | **Migration** – .env wird geschrieben, Tabellen + Seeders ausgefuehrt |
| 4 | **StarMap** – Spielwelt (Sterne + Planeten) generieren |
| 5 | **Admin** – Administrator-Konto erstellen |
| 6 | **Complete** – Cron-Token + Installer-Token werden angezeigt |

**Nach Abschluss ist der Installer automatisch gesperrt.**

### 5. Cron-Job einrichten

**Option A: Shell-Cron (empfohlen)**

Im Hosting Control Panel (z.B. Hostinger hPanel > Erweitert > Cron Jobs):
```
* * * * * cd /home/username/domains/yourdomain.com/public_html/god && php artisan schedule:run >> /dev/null 2>&1
```

**Option B: HTTP-Cron (wenn kein Shell-Cron verfuegbar)**

Der Installer generiert ein `CRON_TOKEN`. Richte einen HTTP-Aufruf ein:
```
URL:      https://yourdomain.com/cron/tick?token=DEIN_CRON_TOKEN
Intervall: Jede Minute (* * * * *)
Methode:   GET
```

Du kannst dafuer auch einen externen Dienst nutzen:
- [cron-job.org](https://cron-job.org) (kostenlos, jede Minute)
- Hostinger hPanel > Erweitert > Cron Jobs > URL aufrufen

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

Das ist der Standard-Modus fuer Hostinger Shared Hosting, wo das Projekt z.B. unter
`/public_html/god/` liegt und DocumentRoot nicht geaendert werden kann.

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
- **Clear All Caches** – config, view, route, cache leeren
- **Clear Sessions** – Alle Sessions loeschen
- **Regenerate StarMap** – Komplette Spielwelt neu generieren (ACHTUNG: loescht bestehende!)
- **Expand StarMap** – Zusaetzliche Sterne/Planeten hinzufuegen (bestehende bleiben)
- **Reinstall Passport** – OAuth-Keys neu erstellen

---

## Installer-Architektur (technische Details)

### File-Based State Tracking

Der Installer nutzt **keine Sessions** fuer den Installationsfortschritt.
Stattdessen wird der Zustand in `storage/app/installer_state.json` gespeichert.

**Grund:** Wenn der Installer eine neue `.env` mit einem neuen `APP_KEY` schreibt,
wird der Session-Cookie (verschluesselt mit dem alten Key) ungueltig. Das fuehrte
frueher zum "DB Step springt zurueck"-Bug (Endlosschleife).

### Eigene Middleware-Gruppe

Installer-Routes nutzen eine eigene Middleware-Gruppe `installer`, die NICHT enthaelt:
- `VerifyCsrfToken` (Installer hat eigene Token-Pruefung)
- `CreateFreshApiToken` / Passport (evtl. noch nicht installiert)
- `CheckInstalled` (Installer prueft selbst)

Sessions werden trotzdem gestartet (fuer `old()` / `withErrors()`), aber der
Installer-Flow haengt nicht davon ab.

### Installer-Log

Jeder Schritt wird in `storage/logs/installer.log` protokolliert.
Bei `APP_DEBUG=true` zeigt der Installer eine Debug-Box mit System-Infos.

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

Der Installer selbst ist davon **nicht betroffen** (eigene Middleware-Gruppe ohne CSRF).
Falls das Problem im Spiel (Login etc.) auftritt:

1. **Storage-Verzeichnisse pruefen:**
   ```bash
   mkdir -p storage/framework/sessions storage/framework/cache/data storage/framework/views
   chmod -R 775 storage
   ```

2. **Caches leeren:**
   ```bash
   php artisan config:clear
   php artisan cache:clear
   ```

3. **Browser-Cookies loeschen** (oder Inkognito-Tab nutzen)

4. **SESSION_SECURE_COOKIE in .env:**
   ```
   SESSION_SECURE_COOKIE=null   # Auto-Erkennung (empfohlen!)
   SESSION_SAME_SITE=lax
   ```
   NICHT `SESSION_DOMAIN` setzen – `null` (Standard) funktioniert ueberall.

### 500 Internal Server Error

```bash
# Logs pruefen
tail -50 storage/logs/laravel.log

# Installer-Logs pruefen
tail -50 storage/logs/installer.log

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

### DB Step springt zurueck (geloest)

Dieses Problem hatte mehrere Ursachen:

1. **Fehlende APP_KEY:** Ohne `.env`/`APP_KEY` konnte `EncryptCookies` den Session-Cookie
   nicht ver-/entschluesseln → Session-Daten (Flash-Daten, alte Eingaben) gingen verloren.
   **Fix:** `EncryptCookies` und `SafeEncryptCookies` fangen `MissingAppKeyException` ab
   und arbeiten ohne Verschluesselung waehrend der Installation.

2. **POST-Redirect auf Hostinger/LiteSpeed:** Server-seitige HTTPS-Redirects koennen POST
   in GET umwandeln → Formulardaten gehen verloren. **Fix:** `public/.htaccess` erzwingt
   HTTPS auf App-Ebene; der Installer nutzt AJAX + GET-Fallback (`/install/save-environment`)
   statt reiner POST-Formulare.

3. **SESSION_DOMAIN als URL:** `SESSION_DOMAIN=https://domain.com` (statt `null` oder
   `.domain.com`) bricht Cookie-Zustellung komplett. **Fix:** `config/session.php` und
   `AppServiceProvider` sanitieren SESSION_DOMAIN automatisch.

4. **redirect('/') Endlosschleife:** Wenn `installed.lock` existierte, redirectete der
   Installer zu `/`, was CheckInstalled wieder zu `/install` schickte. **Fix:** Statt
   `redirect('/')` zeigt der Installer eine informative HTML-Seite mit Hinweis auf den
   INSTALL_TOKEN.

Falls das Problem trotzdem auftritt:

1. Loesche `storage/app/installer_state.json`
2. Loesche `storage/installed.lock`
3. Loesche alle Sessions: `rm -f storage/framework/sessions/*`
4. Starte den Installer neu: `/install`

### mix-manifest.json fehlt (500)

Alle `mix()` Aufrufe wurden durch `asset()` ersetzt. Die CSS/JS Assets werden
automatisch per GitHub Actions gebaut und in den `master` Branch committed.
`public/mix-manifest.json` wird ebenfalls committed (nicht mehr in `.gitignore`).

Nach einem Pull/Import auf Hostinger sollten die Assets automatisch vorhanden sein.
Falls nicht: GitHub Actions Tab prüfen, ob der "Build Assets" Workflow erfolgreich lief.

### TrustProxies

Wird automatisch gehandhabt. Die App vertraut allen Proxies (`*`), was fuer
Shared Hosting hinter Load Balancern/Reverse Proxies noetig ist.
Headers werden mit den korrekten Symfony 6 Konstanten konfiguriert.

---

## PHP Extensions (Hostinger)

Diese Extensions werden benoetigt und sind bei Hostinger standardmaessig aktiv:

| Extension | Status | Noetig fuer |
|-----------|--------|-------------|
| pdo_mysql | Pflicht | Datenbank |
| mbstring | Pflicht | String-Handling |
| openssl | Pflicht | Verschluesselung |
| tokenizer | Pflicht | Laravel |
| ctype | Pflicht | Validation |
| json | Pflicht | API/Config |
| fileinfo | Pflicht | File Upload |
| xml | Pflicht | Laravel |
| curl | Pflicht | HTTP Client |
| dom | Pflicht | Laravel |
| bcmath | Pflicht | Berechnungen |
| imagick | Optional | StarMap Tiles |

Falls eine Extension fehlt: Hostinger hPanel > Erweitert > PHP Konfiguration > Extensions.

---

## Dateien die NIEMALS committed werden duerfen

Diese Dateien sind in `.gitignore` eingetragen:
- `.env` – Umgebungsvariablen
- `storage/installed.lock` – Installations-Status
- `storage/install.unlock` – Installer-Entsperrung
- `storage/app/installer_state.json` – Installer-Zwischenstand
- `vendor/` – Composer-Abhaengigkeiten
- `node_modules/` – NPM-Abhaengigkeiten
- `public/mix-manifest.json` – ~~Build-Artefakt~~ WIRD jetzt committed (GitHub Actions Build)
- `public/tile/` – Generierte StarMap-Tiles

---

## Sicherheitshinweise

- `.env` ist durch `.htaccess` geschuetzt (403)
- Alle sensiblen Verzeichnisse sind blockiert (vendor/, storage/, config/, etc.)
- Der Installer sperrt sich automatisch nach Installation
- `APP_DEBUG=false` in Production
- CRON_TOKEN und INSTALL_TOKEN werden bei Installation generiert
- Session-Cookies: HttpOnly, Secure (auto-detect via TrustProxies), SameSite=Lax
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
SESSION_SECURE_COOKIE=null
SESSION_SAME_SITE=lax
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
- Kann auch ueber den Web-Installer/Updater gestartet werden
