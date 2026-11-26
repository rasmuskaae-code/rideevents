"""Run with python3 tests/organizers.py on a machine with PHP and pdo_sqlite."""
import datetime
import http.cookiejar
import os
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('STAEVNE_TEST_PHP', 'php')
PASSWORD = 'local-test-password'


class Browser:
    def __init__(self, base):
        self.base = base
        self.client = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        )

    def request(self, path, fields=None):
        data = urllib.parse.urlencode(fields).encode() if fields is not None else None
        try:
            response = self.client.open(self.base + path, data, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(errors='replace'), response.url

    def post(self, path, action, csrf_path=None, **fields):
        _, html, _ = self.request(csrf_path if csrf_path is not None else path)
        token = re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)
        return self.request(path, dict(fields, csrf=token, action=action))


class OrganizerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        directory = Path(self.temp.name)
        database = directory / 'test.sqlite'
        self.db = sqlite3.connect(database)
        self.addCleanup(self.db.close)
        self.day = (datetime.date.today() + datetime.timedelta(days=2)).isoformat()
        self.create_legacy_database()

        env = dict(os.environ, STAEVNE_DB=str(database), STAEVNE_DEMO='0', STAEVNE_SECURE_COOKIE='0')
        subprocess.run([PHP, 'bin/admin.php', 'platform@example.test', 'Platform'], cwd=ROOT, env=env,
                       input=PASSWORD + '\n', text=True, capture_output=True, check=True)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        self.base = f'http://127.0.0.1:{port}/'
        self.log = (directory / 'server.log').open('w+')
        self.addCleanup(self.log.close)
        self.server = subprocess.Popen(
            [PHP, '-d', 'session.save_path=' + str(directory), '-S', f'127.0.0.1:{port}', '-t', 'public'],
            cwd=ROOT, env=env, stdout=self.log, stderr=self.log
        )
        self.addCleanup(self.stop_server)
        for _ in range(30):
            try:
                if Browser(self.base).request('')[0] == 200:
                    return
            except urllib.error.URLError:
                pass
            time.sleep(0.1)
        self.log.seek(0)
        self.fail('PHP server did not start: ' + self.log.read())

    def stop_server(self):
        self.server.terminate()
        self.server.wait(timeout=5)

    def create_legacy_database(self):
        self.db.executescript((ROOT / 'app/schema.sql').read_text())
        self.db.execute('PRAGMA user_version = 1')
        self.db.execute("INSERT INTO users (id,name,email,password) VALUES (1,'Historisk hjælper','legacy@example.test','test')")
        self.db.execute(
            "INSERT INTO events VALUES (1,'Historisk stævne','spring','Ridehallen',?,?,'',1)",
            (self.day, self.day)
        )
        self.db.execute("INSERT INTO groups VALUES (1,1,'Historisk gruppe','',1)")
        starts = int(datetime.datetime.fromisoformat(self.day + 'T09:00+00:00').timestamp())
        self.db.execute("INSERT INTO shifts VALUES (1,1,'Historisk vagt',?,?,1,1,1)", (starts, starts + 3600))
        self.db.execute("INSERT INTO signups VALUES (1,1,1,1)")
        self.db.commit()

    def organizer(self, name, email):
        browser = Browser(self.base)
        status, html, url = browser.post('?page=organizer', 'organizer', organizer_name=name, name=name,
                                         email=email, password=PASSWORD)
        self.assertEqual(status, 200)
        self.assertIn('Arrangøren er oprettet', html)
        self.assertIn('?page=admin', url)
        return browser

    def event_fields(self, name):
        return dict(name=name, discipline='spring', location='Ridehallen', starts_on=self.day, ends_on=self.day, visible=1)

    def create_event(self, browser, name, **fields):
        status, html, url = browser.post('?page=admin&event=0', 'event', id=0, **self.event_fields(name), **fields)
        self.assertEqual(status, 200)
        self.assertIn('Ændringen er gemt', html)
        return int(urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)['event'][0])

    def create_shift(self, browser, event):
        path = f'?page=admin&event={event}'
        status, html, _ = browser.post(path, 'group', id=0, name='Testgruppe', visible=1)
        self.assertEqual(status, 200)
        self.assertIn('Ændringen er gemt', html)
        group = self.db.execute('SELECT id FROM groups WHERE event_id=?', (event,)).fetchone()[0]
        status, html, _ = browser.post(path, 'shift', id=0, group_id=group, name='Testvagt', capacity=2,
                                       starts_at=self.day+'T09:00', ends_at=self.day+'T11:00', visible=1, bookable=1)
        self.assertEqual(status, 200)
        self.assertIn('Ændringen er gemt', html)
        return self.db.execute('SELECT id FROM shifts WHERE group_id=?', (group,)).fetchone()[0]

    def test_scope_and_booking(self):
        first = self.organizer('Rideklub Nord', 'nord@example.test')
        second = self.organizer('Rideklub Syd', 'syd@example.test')
        south = self.db.execute("SELECT id FROM organizers WHERE name='Rideklub Syd'").fetchone()[0]
        north_event = self.create_event(first, 'Nordstævnet', organizer_id=south)
        self.create_event(first, 'Nordstævnet igen')
        south_event = self.create_event(second, 'Sydstævnet')
        north_shift = self.create_shift(first, north_event)
        south_shift = self.create_shift(second, south_event)
        north_admin = f'?page=admin&event={north_event}'
        south_admin = f'?page=admin&event={south_event}'

        self.assertIn('Nordstævnet igen', first.request(north_admin)[1])
        self.assertNotIn('Sydstævnet', first.request(north_admin)[1])
        self.assertEqual(first.request(south_admin)[0], 404)
        self.assertEqual(first.request('?page=platform')[0], 403)
        _, html, _ = second.post(south_admin, 'contact', id=0, name='Privat hjælper', contact='12345678', task='Bane')
        self.assertIn('Privat hjælper', html)
        self.assertNotIn('Privat hjælper', first.request(south_admin)[1])
        self.assertEqual(first.post(south_admin, 'event', north_admin, id=south_event, **self.event_fields('Kapret'))[0], 403)
        self.assertEqual(first.post(south_admin, 'contact', north_admin, id=0, name='Ugyldig', contact='1234', task='Bane')[0], 403)
        south_group = self.db.execute('SELECT group_id FROM shifts WHERE id=?', (south_shift,)).fetchone()[0]
        private_group = self.db.execute(
            "INSERT INTO groups (event_id,name,description,visible) VALUES (?,'Privat gruppe','',0)", (south_event,)
        ).lastrowid
        private_image = self.db.execute(
            "INSERT INTO images (group_id,position,mime,data) VALUES (?,1,'image/webp',?)",
            (private_group, (ROOT / 'public/assets/stadium.webp').read_bytes())
        ).lastrowid
        self.db.commit()
        self.assertEqual(first.request(f'?page=image&id={private_image}')[0], 404)
        self.assertEqual(second.request(f'?page=image&id={private_image}')[0], 200)
        _, html, _ = first.post(north_admin, 'shift', id=south_shift, group_id=south_group, name='Kapret',
                                starts_at=self.day+'T09:00', ends_at=self.day+'T11:00', capacity=1)
        self.assertIn('Vi kunne ikke finde det valgte element', html)
        self.assertEqual(self.db.execute('SELECT name FROM shifts WHERE id=?', (south_shift,)).fetchone()[0], 'Testvagt')

        guest = Browser(self.base)
        _, html, _ = guest.request(f'?organizer={south}')
        self.assertIn('Sydstævnet', html)
        self.assertNotIn('Nordstævnet', html)
        _, html, _ = guest.post('?page=register', 'register', name='Hjælper', email='helper@example.test', password=PASSWORD)
        self.assertIn('Din profil er oprettet', html)
        for event, shift in ((north_event, north_shift), (south_event, south_shift)):
            _, html, _ = guest.post(f'?event={event}', 'join', id=shift)
            self.assertIn('Du er tilmeldt vagten', html)
        _, html, _ = guest.request('?page=mine')
        for name in ('Nordstævnet', 'Sydstævnet', 'Rideklub Nord', 'Rideklub Syd'):
            self.assertIn(name, html)
        self.assertIn('Ny tilmelding', first.request('?page=notices')[1])
        self.assertNotIn('Sydstævnet', first.request('?page=notices')[1])
        signup = self.db.execute('SELECT id FROM signups WHERE shift_id=?', (south_shift,)).fetchone()[0]
        _, html, _ = first.post(north_admin, 'cancel', id=signup)
        self.assertIn('Vi kunne ikke finde det valgte element', html)

        _, html, _ = guest.post('?page=organizer', 'organizer', organizer_name='Hjælperklubben')
        self.assertIn('Arrangøren er oprettet', html)
        self.assertIn('Sydstævnet', guest.request('?page=mine')[1])

        platform = Browser(self.base)
        _, html, url = platform.post('?page=login', 'login', email='platform@example.test', password=PASSWORD)
        self.assertIn('?page=platform', url)
        self.assertIn('Rideklub Syd', html)
        self.assertIn('Privat hjælper', platform.request(south_admin)[1])
        self.assertEqual(platform.request(f'?page=image&id={private_image}')[0], 200)
        platform.post('?page=platform', 'save_organizer', id=south, organizer_name='Rideklub Syd')
        self.assertEqual(second.request(south_admin)[0], 403)
        self.assertEqual(second.request(f'?page=image&id={private_image}')[0], 404)
        self.assertNotIn('Sydstævnet', Browser(self.base).request(f'?event={south_event}')[1])
        self.assertIn('Sydstævnet', guest.request('?page=mine')[1])
        _, html, _ = guest.post(f'?event={south_event}', 'join', id=south_shift)
        self.assertIn('Vi kunne ikke finde det valgte element', html)
        platform.post('?page=platform', 'save_organizer', id=south, organizer_name='Rideklub Syd', active=1)
        self.assertEqual(second.request(south_admin)[0], 200)

        _, html, _ = platform.post('?page=admin&event=1', 'event', id=1, organizer_id=south,
                                    **self.event_fields('Historisk stævne'))
        self.assertIn('Ændringen er gemt', html)
        self.assertIn('Historisk stævne', second.request('?page=admin&event=1')[1])
        self.assertEqual(self.db.execute('SELECT COUNT(*) FROM signups WHERE shift_id=1').fetchone()[0], 1)
        self.assertEqual(Browser(self.base).request(f'?page=export&event={north_event}&format=pdf')[0], 403)
        self.assertEqual(guest.request(f'?page=export&event={north_event}&format=pdf')[0], 404)
        self.assertEqual(second.request(f'?page=export&event={north_event}&format=xlsx')[0], 404)
        event_delete = f'?page=delete&event={north_event}'
        confirmation_page = event_delete + f'&kind=event&id={north_event}'
        status, confirmation_html, _ = first.request(confirmation_page)
        self.assertEqual(status, 200)
        self.assertIn('Nordstævnet', confirmation_html)
        confirmation = re.search(r'name="confirmation" value="([a-f0-9]+)"', confirmation_html).group(1)
        fields = dict(id=north_event, kind='event', confirmation=confirmation)
        self.assertEqual(second.post(event_delete, 'delete', south_admin, **fields, confirm_delete=1)[0], 403)
        _, html, _ = first.post(event_delete, 'delete', confirmation_page, **fields)
        self.assertIn('bekræftelse mangler', html)
        self.assertIn('Nordstævnet', first.request(north_admin)[1])
        _, html, _ = first.post(event_delete, 'delete', confirmation_page, **fields, confirm_delete=1)
        self.assertIn('Stævnet er slettet', html)
        self.assertEqual(first.request(north_admin)[0], 404)
        self.assertNotIn('Nordstævnet · Rideklub Nord', guest.request('?page=mine')[1])
        self.assertIn('Stævnet Nordstævnet er slettet', guest.request('?page=notices')[1])
        self.assertIn('Sydstævnet', guest.request('?page=mine')[1])

        self.assertEqual(self.db.execute('PRAGMA foreign_key_check').fetchall(), [])
        self.assertEqual(self.db.execute('PRAGMA user_version').fetchone()[0], 2)


if __name__ == '__main__':
    unittest.main()
