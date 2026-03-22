# VitoConnect
Das Modul dient dazu die VitoConnect Cloud-API abzufragen und alle relevanten Informationen über das angeschlossene Viessmann Gerät darzustellen.

> **Hinweis:** Dies ist ein persönlicher Fork. Siehe [Hauptseite](../README.md) für wichtige Hinweise.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [WebFront](#6-webfront)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)

### 1. Funktionsumfang

* Abfrage der Daten, welche über die Cloud-API verfügbar sind
* Ändern von Werten (aktuell nur die Ziel-Temperaturen)
* Zirkulationspumpen-Steuerung über Zeitplan (CreateZirku)

### 2. Voraussetzungen

- IP-Symcon ab Version 6.0
- Symcon Connect (für OAuth-Redirect)
- Viessmann Developer Account mit registrierter ClientID

### 3. Software-Installation

* Über das Module Control folgende URL hinzufügen:
`https://github.com/Benny-FC-Bit/viessmann`

### 4. Einrichten der Instanzen in IP-Symcon

- Unter "Instanz hinzufügen" ist das 'VitoConnect'-Modul unter dem Hersteller 'Viessmann' aufgeführt. Zusätzlich muss eine ClientID über das Developer Portal von Viessmann beantragt werden. Dies ist unter https://developer.viessmann.com/ im Menüpunkt "My Dashbaord" zu finden. Beim erstellen des Clients darf der Name frei gewählt werden - bei der Redirect URI muss die ipmagic.de Adresse eingegeben werden, welche in der VitoConnect Instanz angezeigt wird. Zur Verknüpfung innerhalb der VitoConnect Instanz wird ein aktivierter Symcon Connect benötigt.

![ClientID bei Viessmann beantragen](clientid.png)

__Konfigurationsseite__:

Name            | Beschreibung
--------------- | ---------------------------------
ClientID        | ClientID, welche über https://developer.viessmann.com/ beantragt wurde
Intervall       | Abfrageintervall in Minuten

### 5. Statusvariablen und Profile

Es werden diverse zusätzliche Statusvariablen und Profile erstellt.
Diese sind je nach angeschlossenem Gerät unterschiedlich.

### 6. WebFront

Im WebFront werden alle Variablen angezeigt. Einige sind ggf. schaltbar.

### 7. PHP-Befehlsreferenz

#### VVC_Update(int $InstanzID)
Aktualisiert alle Daten von der Viessmann API.

```php
VVC_Update(36004);
```

#### VVC_CreateZirku(int $InstanzID, string $Start, string $End, bool $Aktivieren)
Setzt oder löscht den Zeitplan der Warmwasser-Zirkulationspumpe.

Zeiten müssen in 10-Minuten-Intervallen angegeben werden (z.B. "06:00", "06:10", "22:30").

```php
// Zirkulation aktivieren von 06:00 bis 06:10
VVC_CreateZirku(36004, '06:00', '06:10', true);

// Zeitplan komplett löschen (Zeiten sind dann Platzhalter)
VVC_CreateZirku(36004, '00:00', '00:10', false);
```

**Anwendungsbeispiel — Zirkulation per KNX-Taster für 10 Minuten aktivieren:**

Ein KNX-Lichtschalter (z.B. im Bad) kann als Auslöser dienen. Beim Drücken wird ein 10-Minuten-Zeitfenster ab der aktuellen Uhrzeit gesetzt. Ein Timer löscht den Zeitplan nach Ablauf automatisch wieder. So läuft die Pumpe nur bei Bedarf und spart Energie.
