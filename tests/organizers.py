"""Run with python3 tests/organizers.py on a machine with PHP and pdo_sqlite."""
import datetime
import hashlib
import hmac
import http.cookiejar
import json
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
WEBHOOK_SECRET = 'whsec_local_test_fixture'


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

        config = directory / 'config.php'
        config.write_text("<?php return ['driver'=>'sqlite','stripe'=>['secret_key'=>'sk_test_fixture','webhook_secret'=>'" + WEBHOOK_SECRET + "']];")
        env = dict(os.environ, STAEVNE_CONFIG=str(config), STAEVNE_DB=str(database), STAEVNE_DEMO='0', STAEVNE_SECURE_COOKIE='0')
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
        helper = self.db.execute("SELECT id FROM users WHERE email='helper@example.test'").fetchone()[0]
        self.assertEqual(self.db.execute("SELECT status FROM helper_applications WHERE event_id=1 AND user_id=1").fetchone()[0], 'approved')
        for event, shift, owner, wrong_owner in ((north_event, north_shift, first, second), (south_event, south_shift, second, first)):
            event_url = f'?event={event}'
            admin_url = f'?page=admin&event={event}'
            _, html, _ = guest.post(event_url, 'join', id=shift)
            self.assertIn('Arrangøren skal godkende dig', html)
            _, html, _ = guest.post(event_url, 'apply')
            self.assertIn('Din ansøgning er sendt', html)
            self.assertEqual(wrong_owner.post(admin_url, 'application', '?page=admin', id=helper, decision='approved')[0], 403)
            _, html, _ = owner.post(admin_url, 'application', id=helper, decision='rejected')
            self.assertIn('Ansøgningen er afvist', html)
            self.assertIn('Din ansøgning er afvist', guest.request(event_url)[1])
            _, html, _ = guest.post(event_url, 'join', id=shift)
            self.assertIn('Arrangøren skal godkende dig', html)
            _, html, _ = owner.post(admin_url, 'application', id=helper, decision='approved')
            self.assertIn('Hjælperen er godkendt', html)
            _, html, _ = guest.post(event_url, 'join', id=shift)
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
        self.assertEqual(self.db.execute('PRAGMA user_version').fetchone()[0], 3)

    def stripe_webhook(self, event, valid=True):
        now = int(time.time())
        body = json.dumps(event).encode()
        signature = hmac.new(WEBHOOK_SECRET.encode(), str(now).encode() + b'.' + body, hashlib.sha256).hexdigest()
        request = urllib.request.Request(self.base + '?page=stripe-webhook', body, {
            'Content-Type': 'application/json', 'Stripe-Signature': f't={now},v1={signature if valid else "invalid"}'
        })
        try:
            with urllib.request.urlopen(request, timeout=5) as response:
                return response.status
        except urllib.error.HTTPError as error:
            return error.code

    def test_billing(self):
        client = self.organizer('Klub med betaling', 'billing@example.test')
        organizer = self.db.execute("SELECT id FROM organizers WHERE name='Klub med betaling'").fetchone()[0]
        for number in range(3):
            self.create_event(client, f'Gratis stævne {number}')
        path = '?page=admin&event=0'
        blocked = client.post(path, 'event', id=0, **self.event_fields('Blokeret'))
        self.assertIn('tre gratis stævner', blocked[1])
        self.assertIn('?page=billing', blocked[2])
        first_event = self.db.execute('SELECT MIN(id) FROM events WHERE organizer_id=?', (organizer,)).fetchone()[0]
        delete_url = f'?page=delete&event={first_event}'
        confirmation_url = delete_url + f'&kind=event&id={first_event}'
        signature = re.search(r'name="confirmation" value="([a-f0-9]+)"', client.request(confirmation_url)[1]).group(1)
        self.assertIn('Stævnet er slettet', client.post(delete_url, 'delete', confirmation_url, id=first_event,
                                                       kind='event', confirmation=signature, confirm_delete=1)[1])
        self.assertIn('tre gratis stævner', client.post(path, 'event', id=0, **self.event_fields('Stadig blokeret'))[1])

        def order(plan, suffix, mode=0):
            now = int(time.time())
            order_id = str(suffix).zfill(32)
            session = f'cs_test_fixture{suffix}'
            self.db.execute('INSERT INTO billing_orders (id,organizer_id,plan,live_mode,price_id,session_id,expires_at,created_at) VALUES (?,?,?,?,?,?,?,?)',
                            (order_id, organizer, plan, mode, 'price_fixture', session, now + 3600, now))
            self.db.commit()
            return {'type': 'checkout.session.completed', 'livemode': False, 'created': now, 'data': {'object': {
                'id': session, 'mode': 'payment', 'payment_status': 'paid', 'amount_total': 7900 if plan == 'event' else 49900,
                'payment_intent': f'pi_fixture{suffix}', 'metadata': {'staevnevagt_order': order_id}
            }}}

        event_order = order('event', 1)
        self.assertNotIn('Betalingen er registreret', client.request('?page=billing&session_id=cs_test_fixture1')[1])
        self.assertEqual(self.stripe_webhook(event_order, valid=False), 400)
        unpaid = json.loads(json.dumps(event_order))
        unpaid['data']['object']['payment_status'] = 'unpaid'
        self.assertEqual(self.stripe_webhook(unpaid), 204)
        self.assertIn('tre gratis stævner', client.post(path, 'event', id=0, **self.event_fields('Ubetalt'))[1])

        for _ in range(2):
            self.assertEqual(self.stripe_webhook(event_order), 204)
        self.assertIn('Betalingen er registreret', client.request('?page=billing&session_id=cs_test_fixture1')[1])
        self.create_event(client, 'Købt stævne')
        self.assertIn('tre gratis stævner', client.post(path, 'event', id=0, **self.event_fields('Ekstra uden betaling'))[1])

        wrong_mode = order('event', 2, 1)
        self.assertEqual(self.stripe_webhook(wrong_mode), 204)
        self.assertIn('tre gratis stævner', client.post(path, 'event', id=0, **self.event_fields('Forkert miljø'))[1])

        annual_order = order('annual', 3)
        self.assertEqual(self.stripe_webhook(annual_order), 204)
        first_expiry = self.db.execute("SELECT access_until FROM billing_orders WHERE session_id='cs_test_fixture3'").fetchone()[0]
        self.assertGreater(first_expiry - int(time.time()), 364 * 86400)
        self.assertEqual(self.stripe_webhook(annual_order), 204)
        self.assertEqual(self.db.execute("SELECT access_until FROM billing_orders WHERE session_id='cs_test_fixture3'").fetchone()[0], first_expiry)
        self.create_event(client, 'Årsstævne 1')
        self.create_event(client, 'Årsstævne 2')

        charge = annual_order['data']['object']
        refund = {'type':'charge.refunded','livemode':False,'data':{'object':{
            'refunded':True, 'metadata':charge['metadata'], 'payment_intent':charge['payment_intent']
        }}}
        self.assertEqual(self.stripe_webhook(refund), 204)
        self.assertIn('tre gratis stævner', client.post(path, 'event', id=0, **self.event_fields('Efter refusion'))[1])
        self.assertIn('Årsstævne 2', client.request('?page=admin')[1])


if __name__ == '__main__':
    unittest.main()
