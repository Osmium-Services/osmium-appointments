SELECT id, reference, customer_name, starts_at, ends_at
FROM {TABLE}
WHERE status IN ('requested', 'confirmed') AND deleted_at IS NULL AND id <> :id
  AND ((resource_id IS NULL AND :no_resource = 1) OR resource_id = :resource_id)
  AND starts_at < :ends_at AND ends_at > :starts_at
LIMIT 1
