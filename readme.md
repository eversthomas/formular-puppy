# Custom Puppy Application Form

WordPress-Plugin zur Verwaltung von Welpenbewerbungen. Bewerber füllen ein Formular aus, der Züchter erhält eine Benachrichtigungsmail und der Bewerber eine Bestätigung. Alle Einsendungen werden in einer eigenen Datenbanktabelle gespeichert.

## Anforderungen

- WordPress 5.8+
- PHP 7.4+
- MySQL 5.7+ (InnoDB, utf8mb4)

## Installation

1. Ordner `formular` in `/wp-content/plugins/` hochladen.
2. Plugin im WordPress-Backend unter **Plugins** aktivieren.
3. Unter **Welpenbewerbung → Einstellungen** Datenschutztext bestätigen und E-Mail-Adresse des Züchters hinterlegen.
4. Shortcode `[puppy_application_form]` auf einer Seite einfügen.

## Einstellungen

| Tab | Funktion |
|---|---|
| Allgemein | Züchter-E-Mail, DSGVO-Aktivierung, Datenlöschung bei Deinstallation |
| Felder | Felder aktivieren / als Pflichtfeld markieren |
| E-Mail | Betreff und Texte für Züchter- und Bewerbermail |
| Diagnose | Internes Log der letzten 50 Ereignisse (Fehler, Spam) |

## Sicherheit

- Nonce-Validierung bei jedem Formularabsenden
- Honeypot-Feld gegen einfache Bots
- Zeitverzögerungs-Check (15 s – 60 min)
- Mathematische Rechenaufgabe (wp_hash + wp_salt)
- IP-basiertes Rate-Limiting (proxy-aware)
- Prüfung auf Kyrillisch, URL-Muster und bekannte Spam-Phrasen
- Dynamische Absenderadresse (`no-reply@<site-domain>`) für SPF/DMARC-Kompatibilität
- Keine PII im internen Diagnose-Log (IP wird als MD5-Hash gespeichert)

## Changelog

### 1.2.5 — 2026-08-22
- `required`-Attribut in `render_limited_textarea()` und `render_limited_input()` wird jetzt korrekt nur gesetzt, wenn das Feld im Backend als Pflichtfeld konfiguriert ist.

### 1.2.4
- Live-Warnung bei weniger als 10 eingegebenen Zeichen in Freitextfeldern.
- Backend-Konfiguration: Felder einzeln aktivierbar und als Pflichtfeld markierbar.
- Mathe-Captcha: Zahlen werden als deutsche Wörter ausgegeben.

### 1.2.3
- Anti-Spam: Honeypot, Zeitverzögerung, Mathe-Captcha, IP-Rate-Limiting, Kyrillisch-Filter, URL-Filter, Name/Telefon-Konsistenzprüfung.
- Nur-Zahlen-Eingabe in Freitextfeldern wird abgewiesen.
- Mindestlänge 10 Zeichen für Freitextfelder.

### 1.2.2
- Fallback-Mail an Züchter mit vollständigen Bewerbungsdaten wenn DB-Insert fehlschlägt.
- Internes Diagnose-Log (`puppy_form_logs`-Tabelle) sichtbar im Admin-Tab „Diagnose".
- Log-Tabelle wird bereits beim ersten Frontend-Aufruf angelegt (nicht erst nach erstem Admin-Login).
- `wpdb->last_error` wird direkt nach dem Insert erfasst und geloggt.

### 1.2.1
- Bewerber-E-Mail in Züchterbenachrichtigung aufgenommen.
- Differenzierte Fehlerlogik: Formular gilt nur als erfolgreich wenn DB-Insert erfolgreich war.
- Dynamische Absenderadresse aus Site-Domain.
- Separate Reply-To-Header für Züchter- und Bewerbermail.

### 1.2.0
- Erstveröffentlichung mit Shortcode, DB-Tabelle, Züchtermail und Bewerber-Autoresponder.
