CREATE TABLE organizers (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    owner_id INTEGER UNIQUE REFERENCES users(id),
    active INTEGER NOT NULL DEFAULT 1 CHECK(active IN (0,1))
);
CREATE UNIQUE INDEX organizers_platform ON organizers ((1)) WHERE owner_id IS NULL;
INSERT INTO organizers (name) VALUES ('Stævnevagt');

ALTER TABLE events ADD COLUMN organizer_id INTEGER REFERENCES organizers(id);
UPDATE events SET organizer_id = (SELECT id FROM organizers WHERE owner_id IS NULL);
CREATE INDEX events_organizer ON events(organizer_id, starts_on);
CREATE TRIGGER events_organizer_required_on_insert BEFORE INSERT ON events
WHEN NEW.organizer_id IS NULL
BEGIN
    SELECT RAISE(ABORT, 'Event requires an organizer');
END;
CREATE TRIGGER events_organizer_required_on_update BEFORE UPDATE OF organizer_id ON events
WHEN NEW.organizer_id IS NULL
BEGIN
    SELECT RAISE(ABORT, 'Event requires an organizer');
END;
