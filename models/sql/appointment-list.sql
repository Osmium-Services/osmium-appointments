SELECT B.*, T.name AS type_name, T.duration_minutes, T.clean_minutes, R.name AS resource_name
FROM {TABLE} AS B
LEFT JOIN {PREFIX}appointment_types AS T ON T.id = B.type_id
LEFT JOIN {PREFIX}appointment_resources AS R ON R.id = B.resource_id
WHERE B.deleted_at IS NULL
ORDER BY (B.status = 'requested') DESC, B.starts_at DESC, B.id DESC
