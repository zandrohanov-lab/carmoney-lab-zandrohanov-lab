-- MAS-DUP: запрет второй активной заявки одного клиента.
--
-- Добавляет вычисляемую колонку active_ref и UNIQUE по ней.
-- Для заявки со status <> 'cancelled' active_ref = applicant_ref,
-- для закрытой (cancelled) active_ref = NULL, а NULL в UNIQUE не
-- конфликтует — закрытая заявка освобождает ключ.
--
-- Решение владельца данных (2026-10-22): существующие дубли активных
-- заявок обрабатывает сама миграция — у каждого клиента остаётся
-- одна активная заявка (последняя по id), остальные закрываются в
-- 'cancelled'. Строки не удаляются.

-- 1. Read-only предпросмотр: кто будет закрыт (все активные,
--    кроме последней по id на клиента).
SELECT a.id, a.applicant_ref, a.status, a.created_at
FROM applications a
LEFT JOIN (
    SELECT applicant_ref, MAX(id) AS keep_id
    FROM applications
    WHERE status <> 'cancelled'
    GROUP BY applicant_ref
) k ON a.applicant_ref = k.applicant_ref AND a.id = k.keep_id
WHERE a.status <> 'cancelled'
  AND k.keep_id IS NULL
ORDER BY a.applicant_ref, a.id;

-- 2. Обработка дублей: закрыть все активные заявки клиента,
--    кроме последней по id. Затрагивает учебные синтетические
--    данные (например, CL-0025: id 25,26,27,29,30 → cancelled,
--    остаётся id 31).
UPDATE applications a
LEFT JOIN (
    SELECT applicant_ref, MAX(id) AS keep_id
    FROM applications
    WHERE status <> 'cancelled'
    GROUP BY applicant_ref
) k ON a.applicant_ref = k.applicant_ref AND a.id = k.keep_id
SET a.status = 'cancelled'
WHERE a.status <> 'cancelled'
  AND k.keep_id IS NULL;

-- 3. Проверка после обработки: активных дублей быть не должно.
SELECT applicant_ref, COUNT(*) c
FROM applications
WHERE status <> 'cancelled'
GROUP BY applicant_ref
HAVING c > 1;

-- 4. Изменение схемы.
-- В utf8mb4_0900_ai_ci (NO PAD) 'X' и 'X ' — разные ключи, поэтому
-- applicant_ref оборачивается в TRIM(): хвостовой/ведущий пробел в
-- applicant_ref больше не открывает «второй активный слот». Сам
-- applicant_ref хранится как прислали — TRIM только в ключе.
ALTER TABLE applications
    ADD COLUMN active_ref VARCHAR(32)
        GENERATED ALWAYS AS (IF(status = 'cancelled', NULL, TRIM(applicant_ref))) VIRTUAL,
    ADD UNIQUE KEY uq_applications_active_ref (active_ref);

-- 5. Проверка после ALTER.
SHOW CREATE TABLE applications;