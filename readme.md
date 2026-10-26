## Anmerkung: Der Code wurde vollständig mit KI generiert! Ich übernehme natürlich keine Verantwortung für die Funktionalität. Der Code darf nur privat oder durch Vereine genutzt und nicht verkauft oder verändert werden!


# DartBracket

Webbasierte Dartturnierverwaltung mit PHP, HTML und CSS. Turnier- und Benutzerdaten werden in MariaDB gespeichert.

## Datenbank einrichten

Voraussetzungen: PHP 8.1 oder neuer mit `PDO_MYSQL` sowie eine MariaDB-Datenbank.

Die Verbindung wird über die Umgebungsvariablen `DART_DB_HOST`, `DART_DB_PORT`, `DART_DB_NAME`, `DART_DB_USER` und `DART_DB_PASSWORD` konfiguriert. Host, Datenbankname, Benutzer und Passwort müssen gesetzt sein; der Port ist standardmäßig `3306`. Hinterlege die tatsächlichen Verbindungswerte ausschließlich in der Hosting-Umgebung oder einem Secret-Store und nicht in dieser Dokumentation oder im Quellcode. Die benötigten Tabellen und Indizes werden beim ersten Aufruf automatisch angelegt.

Setze die Variablen in der Hosting-Verwaltung für PHP-FPM/Apache oder in der Umgebung, mit der der PHP-Prozess gestartet wird. Für den lokalen Testserver kannst du das Passwort interaktiv und ohne Anzeige eingeben:

```sh
read -rsp "MariaDB-Passwort: " DART_DB_PASSWORD; printf '\n'; export DART_DB_PASSWORD
php -S 127.0.0.1:8000 -t backend
```

Danach `http://127.0.0.1:8000` im Browser öffnen.

## Voraussetzungen und Start

Für PHP wird `PDO_MYSQL` benötigt. Nach dem Setzen der Datenbank-Umgebungsvariablen startest du den Webserver im Projektverzeichnis mit `php -S 127.0.0.1:8000 -t backend`.

## Anmeldung und Benutzer

Beim ersten Aufruf von `backend/login.php` wird die Ersteinrichtung angezeigt. Lege dort den ersten Benutzernamen und ein mindestens 6 Zeichen langes Passwort fest. Danach ist die öffentliche Registrierung geschlossen; weitere Konten werden nach der Anmeldung über „Benutzerverwaltung“ angelegt.

Alle Benutzer haben dieselben Rechte. In der Benutzerverwaltung können Konten erstellt und gelöscht sowie das eigene Passwort geändert werden. Benutzerpasswörter werden mit `password_hash()` gespeichert. Der Backend-Zugriff und alle schreibenden Formulare sind durch Anmeldung und CSRF-Tokens geschützt. Eine HTTPS-Verbindung wird für sichere Session-Cookies verwendet, wenn der Webserver HTTPS meldet.

## Backend-Funktionen

- Turniere anlegen und Stammdaten vor der Auslosung bearbeiten
- Teilnehmer hinzufügen und entfernen sowie Spieler aus anderen nicht archivierten Turnieren importieren; vorhandene Namen werden beim Import übersprungen
- zufällige Erstauslosung mit automatisch zugeordneten Freilosen
- Einfach-KO mit automatischem Rundenfortschritt, Doppel-KO mit Ausscheiden nach der zweiten Niederlage, Rundenturnier (einmal jeder gegen jeden) sowie Gruppenphase mit anschließender K.-o.-Runde
- Rundenturnier- und Gruppentabellen mit Siegen, Punktedifferenz und erzielten Punkten; bei der Gruppenphase qualifizieren sich pro Turnier wählbar ein bis vier Spieler je Gruppe
- Ergebnisse erfassen; Paarungen der nächsten Runde werden danach erzeugt
- gespeicherte Ergebnisse korrigieren, solange kein Spiel einer späteren Runde beendet wurde; abhängige, noch ungespielte Runden werden bei einer Siegeränderung neu erzeugt
- K.-o.-Paarungen als Viertelfinale, Halbfinale oder Finale kennzeichnen; Finalspiele und ausdrücklich benannte Platzierungsspiele zeigen bei Bedarf den Bezug zu ausgeschütteten Plätzen
- bei offenen Paarungen Dartboard-Kennzeichnung zuweisen, ändern oder entfernen; dasselbe Board kann nicht zwei offenen Spielen derselben Runde zugewiesen werden
- Vorschau der nächsten Runde mit bereits bekannten Spielern; die Vorschau-Paarungen bleiben nach Abschluss der Runde unverändert bestehen
- Doppel-KO-Finale mit Rückspiel, wenn der Spieler aus der Verliererrunde das erste Finale gewinnt
- Turnierstatus, offene Spiele und Turniersieger in der Übersicht anzeigen
- mehrere Turniere parallel speichern und über die Turnierauswahl wechseln
- Turniere unabhängig vom Status archivieren und wiederherstellen; archivierte Turniere erscheinen nicht in der aktiven Turnierauswahl und können im Archiv endgültig gelöscht werden
- pro Turnier Ausschüttungsquote, Gewinnplätze und Verteilung festlegen: flach abfallend mit linear sinkenden Gewichten oder kopflastig mit ungefähr halbiertem Betrag je weiterem Platz; Cent-Rundungsreste gehen vorrangig an die vorderen Plätze
- Turniere löschen; vor dem endgültigen Entfernen von Teilnehmern, Paarungen und Ergebnissen wird eine Bestätigung angefordert

Nach der Auslosung bleiben Modus und Teilnehmerliste gesperrt. Bei laufenden Turnieren lassen sich Name, Datum, Startgebühr sowie die Ausschüttung weiterhin bearbeiten; abgeschlossene Turniere sind schreibgeschützt. Über „Neues Turnier“ wird ein zusätzliches Turnier angelegt; alle vorhandenen Turniere bleiben in MariaDB erhalten und können jederzeit über die Auswahl oben wieder geöffnet werden.

## Beameransicht

Über „Beameransicht öffnen“ in der Turnierübersicht wird eine kontrastreiche, responsive Live-Ansicht des ausgewählten Turniers geöffnet. Sie zeigt den bisherigen Spielplan mit Ergebnissen, kommende Paarungen und Dartboards sowie bei Rundenturnieren und Gruppenphasen die Tabellen. Änderungen werden alle vier Sekunden aktualisiert. Die Ansicht ist wie das Backend nur nach der Anmeldung erreichbar.

Über „Turnierbaum öffnen“ ist zusätzlich eine grafische Baumansicht verfügbar. K.-o.-Spiele werden mit Verbindungslinien zwischen den Runden dargestellt; Gruppen- und Rundenturniere erscheinen nach Gruppen und Spielrunden sortiert. Ergebnisse, Paarungen und Boards aktualisieren sich ebenfalls alle vier Sekunden.

## Code- und Architekturübersicht

Die Anwendung ist eine serverseitig gerenderte PHP-Anwendung ohne Framework. PHP-Seiten erzeugen das HTML, PDO führt Datenbankabfragen aus, und kleine JavaScript-Dateien ergänzen interaktive beziehungsweise regelmäßig aktualisierte Ansichten. Das öffentliche Webroot wird beim Entwicklungsserver auf `backend/` gesetzt:

```sh
php -S 127.0.0.1:8000 -t backend
```

### Verzeichnisstruktur

| Pfad | Verantwortung |
| --- | --- |
| `backend/index.php` | Hauptübersicht; verarbeitet Turnierauswahl und POST-Aktionen, lädt die Daten für die Ansicht und rendert Dashboard, Teilnehmer, Ausschüttung und Spielplan. |
| `backend/login.php` | Anmeldung und einmalige Erstellung des ersten Benutzerkontos. |
| `backend/users.php` | Benutzerverwaltung und Passwortänderung. |
| `backend/archive.php` | Archivierte Turniere wiederherstellen oder endgültig löschen. |
| `backend/auth.php` | Sessions, CSRF-Token, Benutzerprüfung, Passwortvalidierung und Benutzeroperationen. |
| `backend/tournament.php` | Fachlogik für Turniere, Teilnehmer, Auslosung, Paarungen, Ergebnisse, Tabellen, Vorschauen, Labels und Ausschüttung. |
| `backend/database.php` | PDO-Verbindung, Tabellenanlage sowie idempotente Schema- und Index-Ergänzungen für MariaDB. |
| `backend/screen.php` | Backend-Einstiegspunkt für die Beameransicht; bindet `frontend/index.php` unter der geschützten Backend-URL ein. |
| `backend/tree.php` | Backend-Einstiegspunkt für den grafischen Turnierbaum. |
| `backend/style.css` | Gemeinsame Styles der Backend- und Login-Seiten. |
| `frontend/index.php` | Beameransicht und deren JSON-Datenendpunkt. |
| `frontend/screen.js`, `frontend/screen.css` | Darstellung und Layout der Beameransicht; Datenabruf im Vier-Sekunden-Intervall. |
| `frontend/tree.php` | Eigenständige Baumansicht und Ausgabe ihrer geschützten Assets. |
| `frontend/tree.js`, `frontend/tree.css` | Aufbau der Spielkarten, Runden-Spalten und SVG-Verbindungen sowie das Layout des Turnierbaums. |

### Anfrage- und Datenfluss

1. `backend/index.php` startet eine sichere Session und verlangt eine angemeldete Person. Die aktive Turnier-ID wird in der Session gespeichert; eine gültige `tournament_id` in der URL kann den Kontext wechseln.
2. POST-Aktionen werden anhand des `action`-Feldes ausgewählt, durch CSRF-Prüfung geschützt und an die Fachfunktionen in `backend/tournament.php` oder `backend/auth.php` weitergegeben. Danach leitet das Backend per HTTP 303 zur Übersicht zurück.
3. Die Fachfunktionen validieren Eingaben und setzen Zustandsänderungen um. Die Auslosung und Ergebniserfassung verwenden Datenbanktransaktionen, damit eine Runde beziehungsweise ein Ergebnis mit seinem Fortschritt zusammenhängend gespeichert wird.
4. `backend/screen.php` und `backend/tree.php` sind kleine Routing-Wrapper. Sie behalten die URLs unter `backend/`, während die Darstellungsdateien unter `frontend/` liegen. Beide Ansichten erfordern dieselbe Anmeldung wie das Backend.
5. Die Beameransicht bezieht ihren JSON-Zustand von `frontend/index.php?data=1&tournament_id=…`. Die Baumansicht nutzt denselben Datenendpunkt. Die Daten enthalten Turniermetadaten, Matches in Runden, Format-Tabellen, Vorschau-Paarungen und Spielzähler.
6. JavaScript ersetzt nach erfolgreichem Abruf den dargestellten Ansichtsinhalt und fragt im Abstand von vier Sekunden erneut ab. Das Frontend ist read-only; Änderungen erfolgen ausschließlich über die Backend-Formulare.

### Datenmodell

`backend/database.php` legt die Tabellen bei einer erfolgreichen Verbindung an. Die Tabellen verwenden InnoDB, `utf8mb4`, Primärschlüssel mit Auto-Increment und Fremdschlüssel:

| Tabelle | Inhalt und Beziehungen |
| --- | --- |
| `users` | Benutzername, Passwort-Hash und Erstellungszeit. Der Benutzername ist eindeutig und wird ohne Beachtung der Groß-/Kleinschreibung verglichen. |
| `tournaments` | Turniername, Datum, Startgebühr in Cent, Ausschüttungseinstellungen, Format, Modus, Status und Archivstatus. |
| `participants` | Spieler pro Turnier, Niederlagen und Freilose. Gehört per Fremdschlüssel zu `tournaments`; Namen sind innerhalb eines Turniers eindeutig. |
| `matches` | Runde, Matchnummer, Turnierbaum/Gruppe, Spieler, Sieger, Ergebnis, Dartboard und Spielstatus. Verweist auf Turnier und optionale Teilnehmer. |

Die Datenbank-Verbindung wird über `DART_DB_HOST`, `DART_DB_PORT`, `DART_DB_NAME`, `DART_DB_USER` und `DART_DB_PASSWORD` konfiguriert; konkrete Zugangswerte sind nicht im Quellcode hinterlegt. Schemaänderungen werden beim Verbindungsaufbau über `information_schema` erkannt; fehlende unterstützte Spalten und Indizes werden ergänzt. Bereits vorhandene Tabellen werden nicht gelöscht.

### Turnierfachlogik

- `drawTournament()` sperrt weitere Teilnehmeränderungen, mischt die Teilnehmenden und erzeugt je nach Format die erste Runde. Einzel-KO und Doppel-KO verwenden K.-o.-Runden; Round Robin und Gruppenphase erzeugen ihre Begegnungen paarweise.
- `recordResult()` akzeptiert nur offene Spiele mit zwei verschiedenen, nicht negativen Punktzahlen. Danach aktualisiert es Sieger und Niederlagen und ruft den Fortschritt des Turniers auf.
- `editResult()` akzeptiert nur beendete Spiele, sofern in keiner späteren Runde bereits ein Spiel beendet wurde. Ändert sich der Sieger, entfernt die Funktion noch ungespielte Folgerunden, korrigiert die Verlust-/Freiloszähler und lässt den Turnierfortschritt aus den gültigen Ergebnissen erneut berechnen. Ein bereits gespieltes Spiel einer späteren Runde blockiert die Änderung serverseitig.
- `advanceTournament()` erzeugt für KO-Formate Folgerunden, wertet bei Gruppen-KO die Tabellen aus und startet nach Abschluss der Gruppenphase die K.-o.-Runde. Round Robin endet, sobald keine offenen Begegnungen übrig sind.
- `standingsForTournament()` berechnet Gruppen- und Rundentabellen aus abgeschlossenen Ergebnissen; sortiert wird nach Siegen, Punktedifferenz, erzielten Punkten und anschließend Namen.
- `upcomingMatchPreviews()` berechnet mögliche nächste Paarungen aus bekannten Gewinnern und offenen Begegnungen, ohne diese vorzeitig als echte Matches zu speichern.
- Ausschüttungen werden in Cent berechnet. Die festgelegte Prozentquote bestimmt den Preispool; die Gewichte verteilen ihn entweder linear oder kopflastig. Rundungsreste werden den vorderen Plätzen zugewiesen.

### Authentifizierung und Eingabesicherheit

- `startSecureSession()` setzt HTTP-only- und SameSite-Cookies; das Secure-Flag wird verwendet, wenn PHP HTTPS erkennt.
- `loginUser()` erneuert nach erfolgreicher Anmeldung die Session-ID. Passwörter werden ausschließlich mit `password_hash()` gespeichert und mit `password_verify()` geprüft.
- `currentUser()` prüft den Session-Benutzer erneut in der Datenbank. Geschützte Seiten leiten nicht angemeldete Personen zur Anmeldung weiter.
- Schreibende Formulare benötigen ein Session-CSRF-Token. Serverseitige Fachfunktionen prüfen außerdem Zustände und fachliche Grenzen, auch wenn Eingaben aus einem Formular kommen.
- Ausgaben in HTML müssen kontextgerecht maskiert werden. Die Backend-Seiten verwenden dafür den lokalen Helfer `e()`; Frontend-JavaScript setzt dynamische Inhalte über `textContent` statt HTML-Strings ein.

### Betrieb

Für den Betrieb sind `PDO_MYSQL` und der Zugriff des PHP-Prozesses auf den MariaDB-Host erforderlich. Datenbankpasswörter sollten ausschließlich in der Hosting-Umgebung oder einem Secret-Store gesetzt und niemals in öffentliche Repository-Dateien oder Webserver-Ausgaben geschrieben werden.
