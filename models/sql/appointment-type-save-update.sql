UPDATE {TABLE}
SET name = :name, description = :description, duration_minutes = :duration_minutes,
    price_text = :price_text, enabled = :enabled, sort_order = :sort_order
WHERE id = :id
