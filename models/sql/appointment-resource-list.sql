SELECT R.*, (SELECT COUNT(*) FROM {PREFIX}appointments AS B WHERE B.resource_id = R.id) AS appointment_count
FROM {TABLE} AS R
ORDER BY R.sort_order ASC, R.name ASC
