<?php

return [
    // CalDAV-Server, z. B. Synology Calendar
    'server' => 'http://192.168.1.10:5000',

    // Benutzername für CalDAV
    'user' => 'Max',

    // Passwort oder App-Passwort
    'password' => 'CHANGE_ME',

    // Kalendernamen, die abgefragt werden sollen
    'calendars' => [
        'home',
        'example-calendar',
    ],

    // Zeitzone für die Ausgabe
    'timezone' => 'Europe/Berlin',

    // Anzahl der Tage ab heute
    'days' => 8,
];