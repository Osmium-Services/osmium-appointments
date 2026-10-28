SELECT T.*, (SELECT COUNT(*) FROM {PREFIX}bookings AS B WHERE B.type_id = T.id) AS booking_count
FROM {TABLE} AS T
ORDER BY T.sort_order ASC, T.name ASC
