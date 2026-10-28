SELECT R.*, (SELECT COUNT(*) FROM {PREFIX}bookings AS B WHERE B.resource_id = R.id) AS booking_count
FROM {TABLE} AS R
ORDER BY R.sort_order ASC, R.name ASC
