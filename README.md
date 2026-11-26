# Stævnevagt

Dansk hjælperportal til dressur- og springstævner.

- **Platformadministrator** administrerer alle arrangører og stævner. Under **Platformadmin** kan du rette arrangørnavne og sætte en arrangør på pause. Det lukker arrangørens administration og nye tilmeldinger, og eksisterende tilmeldinger er stadig synlige for de berørte hjælpere.
- **Arrangør eller rideklub** opretter sig via **Opret arrangør** og driver sine stævner under **Stævneadmin**, herunder vagter, tilmeldinger og private kontaktlister. En bruger med en hjælperprofil kan bruge den samme profil til at oprette en arrangør.
- **Hjælper** kan tilmelde sig hos forskellige arrangører med én profil. Alle tilmeldinger vises under **Mine vagter**.

## Installation på Plesk eller andet PHP-webhotel

1. Hent repositoryet, og sæt domænets **Document root** i Plesk til projektets `public/`-mappe. PHP og planlagte opgaver skal kunne læse projektfilerne, inklusive `config.php` uden for webroden.
2. Vælg PHP 8.2 eller nyere med `pdo_mysql` til MySQL 8.0+ eller MariaDB 10.6+. Brug HTTPS. Sæt `upload_max_filesize` til mindst `3M` og `post_max_size` til mindst `8M`.
3. Opret en tom database og en databasebruger i Plesk. Databasebrugeren skal have læse- og skriveadgang samt tilladelse til at oprette tabeller og indekser i den valgte database. Kopiér `config.example.php` til `config.php` i projektroden og udfyld `host`, `port`, `database`, `username` og `password` med oplysningerne fra Plesk. Brug `socket` i stedet for vært og port, hvis webhotellet angiver en Unix-socket. Stævnevagt opretter tabellerne automatisk. `config.php` er udeladt fra Git.

   ```bash
   cp config.example.php config.php
   ```

4. Ved en eksisterende SQLite-installation skal du først følge importvejledningen nedenfor. Ved en ny installation opretter du platformadministratoren via SSH fra projektmappen. Erstat e-mail og navn, og brug samme PHP-version som websitet.

   ```bash
   read -rsp 'Adgangskode til admin (mindst 8 tegn): ' staevne_adgangskode
   printf '\n'
   printf '%s\n' "$staevne_adgangskode" | php bin/admin.php admin@rideklub.dk 'Stævneansvarlig'
   unset staevne_adgangskode
   ```

5. Log ind og åbn **Stævneadmin**, eller lad rideklubben oprette sig via **Opret arrangør**. Opret en planlagt opgave i Plesk, der kører `php /absolut/sti/til/projekt/bin/notifications.php` hvert minut. Den bruger samme `config.php` og opretter påmindelser ud fra de gemte vagttider.

### Glemt kodeord og hurtigt login

Under **Log ind** kan brugere bede om et link til at vælge et nyt kodeord eller logge ind via e-mail. Loginlinket gælder i 15 minutter, og linket til nyt kodeord gælder i 30 minutter. Man åbner mailen og trykker **Log ind** for at bekræfte, så en automatisk mailscanner kan åbne linket uden at bruge det. Et nyt kodeord afslutter eksisterende login og gør udestående links ugyldige.

Tilføj `auth_mail` fra `config.example.php` til din lokale `config.php`. Sæt `from` til en afsenderadresse, som Plesk accepterer, og `public_url` til forsiden over HTTPS, f.eks. `https://event.coursetool.org/public/`. Hvis felterne er udeladt, kan web-php bruge `STAEVNE_MAIL_FROM` og `STAEVNE_URL` fra sit miljø. Linkmails bruger webhotellets PHP `mail()` og afsendes med det samme. Afprøv med din egen e-mail efter opsætning.

### Betaling med Stripe

De første **tre oprettede stævner pr. arrangør er gratis**. Stævner, der allerede findes, tæller med. Sletning gendanner hverken en gratis plads eller et købt stævne. De foreslåede priser er **79 DKK for ét ekstra stævne** og **499 DKK for årsadgang**. Årsadgangen giver ubegrænset oprettelse i 12 måneder og fornyes med et nyt køb. Hjælpere betaler ikke. Platformens egen arrangør er fritaget.

1. Aktivér PHP-udvidelsen `curl`. Tilføj `stripe`-afsnittet fra `config.example.php` til din lokale `config.php`, og udfyld `public_url` med den fulde HTTPS-adresse til forsiden, f.eks. `https://event.coursetool.org/public/`. Angiv din hemmelige Stripe-nøgle i `secret_key`. Brug Stripe-testtilstand med en separat database til afprøvning.
2. Kør `php bin/stripe-prices.php test` eller `php bin/stripe-prices.php live` fra projektmappen. Kommandoen opretter eller genbruger de to engangspriser i det valgte Stripe-miljø. Kopiér de returnerede pris-ID’er til `event_price` og `annual_price` i `config.php`. Du kan også sætte dine egne Stripe-priser til de to engangsprodukter.
3. I Stripe opretter du et webhook til din forside med `?page=stripe-webhook` til sidst. Vælg hændelserne `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.expired` og `charge.refunded`. Kopiér webhookets underskriftshemmelighed til `webhook_secret` i `config.php`. Pris-ID’er, API-nøgle og webhook skal høre til samme Stripe-miljø.
4. Åbn **Betaling** som arrangør. Stripe viser den gældende pris i Checkout. Stævnevagt giver adgang, når Stripe har bekræftet betalingen via webhook. En fuld refusion fjerner ubrugt adgang, og stævner, der allerede er oprettet, bevares.

### Godkendelse af hjælpere

På et offentligt stævne kan en hjælper trykke **Ansøg som hjælper**. Under **Hjælperansøgninger** i Stævneadmin kan arrangøren godkende eller afvise ansøgningen. Hjælperen får besked og kan først tilmelde sig vagter, når arrangøren har godkendt ansøgningen. Godkendelsen gælder det enkelte stævne. Eksisterende tilmeldinger bevares og bliver automatisk godkendt ved opgraderingen.

### Redigering, sletning og eksport

Vælg et stævne under **Stævneadmin**. Brug **Gem stævne** for at redigere stævnet. Åbn en vagtgruppe eller post for at gemme ændringer eller slette den. Sletning kræver en særskilt bekræftelse og fjerner de berørte tilmeldinger. Tilmeldte hjælpere får besked. Sletning af et stævne fjerner også den private kontaktliste til stævnet.

Under **Hjælperoversigt** kan du hente en PDF eller en Excel-fil med vagtgrupper, poster, start og slut, hjælpernes navne og e-mails samt antal tilmeldte og pladser. Ledige poster fremgår også, og Excel-filen kan filtreres. Eksporten er tilgængelig for stævnets arrangør og platformadministratorer.

Aktivér PHP-udvidelserne `mbstring` og `zlib` til PDF og `zip` til Excel på Plesk. PDF-biblioteket og skrifttypen ligger under `app/vendor/tfpdf/` med deres respektive licenser.

### Flyt en eksisterende SQLite-installation til MySQL eller MariaDB

1. Stop webadgang og det planlagte notifikationsjob midlertidigt, så der ikke kommer nye ændringer under flytningen. Tag en backup af SQLite-databasen.
2. Opret en tom database i Plesk og udfyld `config.php` som ovenfor. Importen bruger både `pdo_mysql` og `pdo_sqlite` og kræver `ALTER`-adgang til den nye database for at bevare nummerserien for tilmeldinger.
3. Kør importen fra projektmappen. Tilpas stien til backupfilen eller den oprindelige SQLite-fil.

   ```bash
   php bin/import-sqlite.php /absolut/sti/til/staevnevagt.sqlite
   ```

4. Kontrollér, at importen melder færdig, og åbn derefter websitet og notifikationsjobbet igen. Log ind med din eksisterende konto. Importen kopierer arrangører, konti, stævner, vagter, tilmeldinger, kontaktlister, billeder og notifikationer. Behold backupfilen til kontrol. Brugere kan bede om nye login- og kodeordslinks efter importen.

### Brug SQLite

Uden `config.php` bruger Stævnevagt fortsat SQLite. Du kan også sætte `driver` til `sqlite` i `config.php` og eventuelt angive `sqlite.path`. SQLite kræver `pdo_sqlite` og SQLite 3.7.17 eller nyere. Filen ligger som standard i `var/staevnevagt.sqlite`, og PHP skal have skriveadgang til mappen. Ved opdatering af en gammel installation får eksisterende administratorkonti platformadgang, og stævner uden en arrangør placeres under **Stævnevagt**. En platformadministrator kan vælge en ny arrangør på stævnet. Den nye arrangør får adgang til stævnets tilmeldinger og kontaktliste.

### Notifikationer og indstillinger

Tilmeldinger giver besked til stævnets arrangør. Ved stævner, der administreres af platformen, får platformadministratorerne besked. Ændringer i vagtens navn eller tider, stævneoplysninger, jobbeskrivelser og afmeldinger giver besked til berørte hjælpere. Beskederne kan læses under klokken i topmenuen. Arrangøren og platformadministratoren kan afmelde hjælpere. Lukkede eller skjulte poster bevarer deres tilmeldinger, og allerede tilmeldte hjælpere kan se vagten under **Mine vagter**.

E-mail er valgfrit og sendes af den planlagte opgave gennem webhotellets PHP `mail()`. Angiv en afsenderadresse, som webhotellet accepterer, i kommandoen for den planlagte opgave. Eksempel:

```bash
STAEVNE_MAIL_FROM=staevner@rideklub.dk STAEVNE_URL=https://hjaelpere.rideklub.dk php /absolut/sti/til/projekt/bin/notifications.php
```

| Miljøvariabel | Brug |
| --- | --- |
| `STAEVNE_CONFIG` | Valgfri absolut sti til en anden konfigurationsfil. Brug samme konfiguration for webappen og CLI-kommandoerne. |
| `STAEVNE_DB` | Valgfri absolut sti til SQLite-filen, når driveren er `sqlite`. |
| `STAEVNE_MAIL_FROM` | Valgfri e-mailafsender til den planlagte opgave. |
| `STAEVNE_URL` | Valgfri offentlig basisadresse til link i e-mails. |
| `STAEVNE_SECURE_COOKIE` | Sæt til `1`, hvis HTTPS afsluttes foran PHP, og webserveren ikke videregiver HTTPS-status. |

Påmindelser følger de tider, administratoren gemmer i systemet. Ved forsinkelser eller programændringer redigerer administratoren vagtens start og slut i Stævnevagt. Equipe-synkronisering kræver adgang til en aftalt kilde for klassernes starttider.

Brug webhotellets databasebackup, eller brug SQLite-funktionen `.backup`, hvis du bruger SQLite. De uploadede gruppebilleder ligger i databasen.

## Lokal afprøvning

```bash
STAEVNE_DEMO=1 STAEVNE_DB="$PWD/var/demo.sqlite" php -S localhost:8080 -t public
```

Kommandoen bruger SQLite på en installation uden `config.php`. Åbn [localhost:8080](http://localhost:8080). Demovisningen indsætter fiktive stævner i en tom database. Brug den samme `STAEVNE_DB` med `bin/admin.php`, hvis du også vil prøve administrationen lokalt.

Kør `python3 tests/organizers.py` for at teste arrangøradgang, tilmeldinger på tværs og automatisk databaseopgradering. Testen bruger en separat, midlertidig database.

Skrifttypen DM Sans leveres med licens i `licenses/`.
