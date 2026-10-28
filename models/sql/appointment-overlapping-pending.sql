SELECT A.id, A.reference, A.customer_name, A.starts_at, A.ends_at
FROM {TABLE} AS A
LEFT JOIN {PREFIX}appointment_types AS T ON T.id = A.type_id
WHERE A.status IN ('requested', 'confirmed') AND A.deleted_at IS NULL AND A.id <> :id
  AND ((A.resource_id IS NULL AND :no_resource = 1) OR A.resource_id = :resource_id)
  AND A.starts_at < :ends_at AND DATE_ADD(A.ends_at, INTERVAL COALESCE(T.clean_minutes, 0) MINUTE) > :starts_at
LIMIT 1
