# Stævnevagt

Dansk hjælperportal til dressur- og springstævner. Hjælpere kan oprette en profil, tilmelde sig ledige poster og se deres vagter på mobilen. Administratorer kan planlægge stævner og vagter, følge tilmeldinger og føre en privat kontaktliste over eksterne hjælpere.

## Installation på Plesk eller andet PHP-webhotel

1. Hent repositoryet til en mappe på webhotellet. Sæt domænets **Document root** i Plesk til projektets `public/`-mappe. PHP skal kunne læse de øvrige projektmapper, og `var/` skal være skrivbar for PHP. Databasen oprettes automatisk i `var/staevnevagt.sqlite`. Sørg for, at PHP og planlagte opgaver kører som samme bruger.
2. Vælg PHP 8.2 eller nyere med `pdo_sqlite` og SQLite 3.35 eller nyere. Brug HTTPS. Sæt `upload_max_filesize` til mindst `3M` og `post_max_size` til mindst `8M` i PHP-indstillingerne for at kunne uploade to billeder ad gangen.
3. Kør følgende fra projektmappen via SSH. Erstat e-mail og navn, og brug webhotellets PHP-binær, hvis den hedder noget andet end `php`.

   ```bash
   read -rsp 'Adgangskode til admin (mindst 8 tegn): ' staevne_adgangskode
   printf '\n'
   printf '%s\n' "$staevne_adgangskode" | php bin/admin.php admin@rideklub.dk 'Stævneansvarlig'
   unset staevne_adgangskode
   ```

4. Åbn domænet, log ind, og opret et stævne. Standardgrupper og poster kan indsættes ud fra disciplinen. De starter skjult for hjælpere og lukket for tilmelding. Tilpas tider og antal pladser, og slå de relevante grupper og poster til.
5. Opret en planlagt opgave i Plesk, der kører `php /absolut/sti/til/projekt/bin/notifications.php` hvert minut. Den opretter påmindelser 30 minutter før vagtstart ud fra tiderne i Stævnevagt. Brug den samme database og PHP-version i webappen og i den planlagte opgave.

### Notifikationer og indstillinger

Tilmeldinger giver besked til administratorer, og ændringer i vagtens navn eller tider, stævneoplysninger, jobbeskrivelser og afmeldinger giver besked til berørte hjælpere. Beskederne kan læses under klokken i topmenuen. Kun administratorer kan afmelde hjælpere. Lukkede eller skjulte poster bevarer deres tilmeldinger, og allerede tilmeldte hjælpere kan se vagten under **Mine vagter**.

E-mail er valgfrit og sendes af den planlagte opgave gennem webhotellets PHP `mail()`. Angiv en afsenderadresse, som webhotellet accepterer, i kommandoen for den planlagte opgave. Eksempel:

```bash
STAEVNE_MAIL_FROM=staevner@rideklub.dk STAEVNE_URL=https://hjaelpere.rideklub.dk php /absolut/sti/til/projekt/bin/notifications.php
```

| Miljøvariabel | Brug |
| --- | --- |
| `STAEVNE_DB` | Valgfri absolut sti til SQLite-databasen uden for webroden. Angiv samme sti for webappen og begge CLI-kommandoer. |
| `STAEVNE_MAIL_FROM` | Valgfri e-mailafsender til den planlagte opgave. |
| `STAEVNE_URL` | Valgfri offentlig basisadresse til link i e-mails. |
| `STAEVNE_SECURE_COOKIE` | Sæt til `1`, hvis HTTPS afsluttes foran PHP, og webserveren ikke videregiver HTTPS-status. |

Påmindelser følger de tider, administratoren gemmer i systemet. Ved forsinkelser eller programændringer redigerer administratoren vagtens start og slut i Stævnevagt. Equipe-synkronisering kræver adgang til en aftalt kilde for klassernes starttider.

Sikkerhedskopiér SQLite-databasen med webhotellets backup eller SQLite-funktionen `.backup`. Databasen indeholder også de uploadede gruppebilleder.

## Lokal afprøvning

```bash
STAEVNE_DEMO=1 STAEVNE_DB="$PWD/var/demo.sqlite" php -S localhost:8080 -t public
```

Åbn [localhost:8080](http://localhost:8080). Demovisningen indsætter fiktive stævner i en tom database. Brug den samme `STAEVNE_DB` med `bin/admin.php`, hvis du også vil prøve administrationen lokalt.

Skrifttyperne DM Sans og Playfair Display leveres med deres licenser i `licenses/`.
