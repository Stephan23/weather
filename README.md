# SmartMirror Weather Dashboard

Ein leichtgewichtiges SmartMirror-Dashboard auf Basis von HTML, CSS und JavaScript.

## Funktionen

- 🕒 Aktuelle Uhrzeit
- 📅 Aktuelles Datum
- 📍 Frei wählbarer Ortsname
- 🌤 Aktuelles Wetter
- 🌡 Aktuelle Temperatur
- 💧 Luftfeuchtigkeit
- 🌬 Windgeschwindigkeit
- 🌧 Niederschlagswahrscheinlichkeit
- ⏱ Stundenvorhersage (konfigurierbar)
- 📆 7-Tage-Wettervorhersage
- 🌙 Tag-/Nacht-Icons
- 🎨 Optimiert für SmartMirror (Dark Mode)

Die Wetterdaten stammen von **Open-Meteo**.

---

# URL-Parameter

Alle Einstellungen können direkt über URL-Parameter vorgenommen werden.

| Parameter | Beschreibung | Standard |
|-----------|--------------|----------|
| `lat` | Breitengrad | `51.06` |
| `lon` | Längengrad | `6.10` |
| `city` | Angezeigter Ortsname | `Heinsberg` |
| `dt` | Abstand der Stundenvorhersage in Stunden | `3` |
| `dt_count` | Anzahl der Stundenkarten | `8` |

## Beispiele

### Standard

```
index.html
```

### Heinsberg

```
index.html?lat=51.06&lon=6.10&city=Heinsberg
```

### Mönchengladbach

```
index.html?lat=51.194&lon=6.435&city=Mönchengladbach
```

### 24 Stunden

```
index.html?dt=1&dt_count=24
```

### Alle 3 Stunden

```
index.html?dt=3&dt_count=8
```

### Alle 6 Stunden

```
index.html?dt=6&dt_count=8
```

---

# Projektstruktur

```
/
│
├── index.html
└── svg/
    ├── 01d.svg
    ├── 01n.svg
    ├── 02d.svg
    ├── …
    ├── wind.svg
    └── luftfeuchtigkeit.svg
```

---

# Verwendete Wetterdaten

## Aktuelles Wetter

- Temperatur
- Wettercode
- Tag/Nacht
- Windgeschwindigkeit
- Luftfeuchtigkeit

## Stundenvorhersage

- Temperatur
- Wettercode
- Niederschlagswahrscheinlichkeit

## Tagesvorhersage

- Wettercode
- Höchsttemperatur
- Tiefsttemperatur
- Niederschlagswahrscheinlichkeit

---

# Wetter-API

Es wird die kostenlose API von Open-Meteo verwendet.

```
https://api.open-meteo.com/v1/forecast
```

Folgende Datensätze werden abgefragt:

- current
- hourly
- daily

---

# Aktualisierung

Die Wetterdaten werden automatisch alle **10 Minuten** neu geladen.

```
setInterval(load, 600000);
```

---

# Verwendete Icons

Das Dashboard nutzt lokale SVG-Dateien.

```
svg/
```

Benötigte Wettericons:

```
01
02
03
09
10
11
13
50
```

jeweils als

```
01d.svg
01n.svg
...
```

Zusätzlich:

```
wind.svg
luftfeuchtigkeit.svg
```

---

# Geplante Erweiterungen

- 📅 Synology Calendar (CalDAV)
- 🌅 Sonnenaufgang / Sonnenuntergang
- 🌙 Mondphase
- 🚮 Müllabfuhr
- 🏠 Home Assistant
- ⚡ PV-Ertrag
- 📈 Wetterdiagramme
- 📰 Nachrichten
- 🚦 Verkehrsinfo
- 📷 Webcam
- 🌡 Innen-/Außensensoren (MQTT)

---

# Lizenz

Private Nutzung frei.

Wetterdaten:
Open-Meteo

Icons:
Lokale SVG-Dateien.
