CREATE TABLE external_shift_assignments (
    shift_id INTEGER NOT NULL REFERENCES shifts(id) ON DELETE CASCADE,
    contact_id INTEGER NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
    PRIMARY KEY (shift_id,contact_id)
);
CREATE TABLE group_leads (
    group_id INTEGER PRIMARY KEY REFERENCES `groups`(id) ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
    contact_id INTEGER REFERENCES contacts(id) ON DELETE CASCADE,
    CHECK ((user_id IS NOT NULL AND contact_id IS NULL) OR (user_id IS NULL AND contact_id IS NOT NULL))
);
CREATE INDEX group_leads_user ON group_leads(user_id);
