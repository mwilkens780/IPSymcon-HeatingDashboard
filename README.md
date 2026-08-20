# Heating Dashboard – IP-Symcon Modul

Grafische HTML-SDK-Kachel für die Heizung (Viessmann/VitoConnect), im selben dunklen Dashboard-Stil wie SunRiser8, BMW CarData und Weather Dashboard. Liest und schreibt ausschließlich bereits vorhandene VitoConnect-Variablen — keine eigene API-Anbindung, kein OAuth.

## Installation

Modulverwaltung → + → URL eintragen:
```
https://github.com/mwilkens780/IPSymcon-HeatingDashboard
```

## Konfiguration

Alle Felder sind mit den Objekt-IDs dieser Installation vorbelegt (VitoConnect-Instanz), bei Bedarf über die Variablen-Auswahl anpassbar.

## Funktionsweise

- **Anzeige**: liest die vorhandenen VitoConnect-Variablen direkt aus (Kennzahlen, Sollwerte, Betriebsart, Verbrauch).
- **Schreiben** (Soll-Temperaturen per Drehregler, Betriebsart per Buttons): ruft die globale IPS-Funktion `RequestAction($VariableID, $Value)` auf die jeweilige VitoConnect-Variable auf — IPS leitet das automatisch an VitoConnects eigene `RequestAction()`-Logik weiter (dieselbe OAuth-Session, derselbe Viessmann-API-Call), dieses Modul dupliziert die API-Anbindung nicht.
- **Temperaturverlauf**: VitoConnects Temperatur-Sensoren sind nicht archiviert. Das Modul führt daher eine eigene rollierende Historie (Attribut, alle `update_interval` Sekunden ein Messpunkt, 12h Fenster intern, 6h angezeigt).

## Kachel einrichten

Die Kachel in einer Tile-Visualization platzieren, mindestens 4 Spalten × 6 Zeilen für vollständige Darstellung.
