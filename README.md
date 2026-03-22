# Viessmann Benny — Persönlicher Fork für IP-Symcon

> **Achtung:** Dies ist ein **persönlicher Fork** des [Original-Moduls von paresy](https://github.com/paresy/Viessmann).
> Dieses Modul verwendet bewusst die gleiche Modul-ID wie das Original, um eine nahtlose Migration zu ermöglichen.
>
> **Nicht parallel zum Original-Modul installieren!**
> Die Installation dieses Moduls **ersetzt** das Original-Modul (VitoConnect aus dem IP-Symcon Module Store).
> Wenn du das Original nutzt und beibehalten möchtest, installiere dieses Repository **nicht**.

## Enthaltene Module

- __VitoConnect__ ([Dokumentation](VitoConnect))
  Erlaubt das Abfragen/Ändern vieler Werte, die auch in der ViCare App verfügbar sind.

## Änderungen gegenüber dem Original

- PKCE-Authentifizierung korrigiert (Challenge statt Verifier, S256-Methode, API v3)
- Token-Ablauf korrekt als absoluter Zeitstempel gespeichert
- Saubere Fehlerbehandlung (keine `die()`-Aufrufe mehr)
- HTTP 429 Rate-Limit und 401 Token-Expired Behandlung
- Zirkulationspumpen-Zeitplan (CreateZirku)

## Voraussetzungen

- IP-Symcon 6.0 oder neuer
- Symcon Connect (für OAuth-Redirect)
- Viessmann Developer Account mit registrierter ClientID
