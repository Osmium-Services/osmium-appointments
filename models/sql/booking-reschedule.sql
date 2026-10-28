UPDATE {TABLE}
SET type_id = :type_id, resource_id = :resource_id, starts_at = :starts_at, ends_at = :ends_at,
    admin_notes = :admin_notes, ics_sequence = :ics_sequence
WHERE id = :id
