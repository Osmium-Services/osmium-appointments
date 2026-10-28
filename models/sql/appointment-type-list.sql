SELECT T.*, (SELECT COUNT(*) FROM {PREFIX}appointments AS B WHERE B.type_id = T.id) AS appointment_count
FROM {TABLE} AS T
ORDER BY T.sort_order ASC, T.name ASC
