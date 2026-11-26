INSERT INTO helper_applications (event_id,user_id,status,requested_at,decided_at)
SELECT g.event_id,s.user_id,'approved',MIN(s.created_at),MIN(s.created_at)
FROM signups s JOIN shifts v ON v.id=s.shift_id JOIN `groups` g ON g.id=v.group_id
GROUP BY g.event_id,s.user_id;
INSERT INTO organizer_event_usage (organizer_id,created_count)
SELECT e.organizer_id,COUNT(*) FROM events e JOIN organizers o ON o.id=e.organizer_id
WHERE o.owner_id IS NOT NULL
GROUP BY e.organizer_id;
