-- MAS-DUP: запрет второй активной заявки одного клиента.
--
-- Добавляет вычисляемую колонку active_ref и UNIQUE по ней.
-- Для заявки со status <> 'cancelled' active_ref = applicant_ref,
-- для закрытой (cancelled) active_ref = NULL, а NULL в UNIQUE не
-- конфликтует — закрытая заявка освобождает ключ.
--
-- ВАЖНО ДО ALTER: read-only предпроверка дублей ниже. Если она вернёт
-- строки, ALTER упадёт с Duplicate entry. Данные самому не чинить:
-- это блокер для миграции живого тома, решение принимает владелец данных.

-- 1. Предпроверка (ничего не меняет).
SELECT applicant_ref, COUNT(*) c
FROM applications
WHERE status <> 'cancelled'
GROUP BY applicant_ref
HAVING c > 1;

-- 2. Изменение схемы.
-- В utf8mb4_0900_ai_ci (NO PAD) 'X' и 'X ' — разные ключи, поэтому
-- applicant_ref оборачивается в TRIM(): хвостовой/ведущий пробел в
-- client_ref больше не открывает «второй активный слот». Сам applicant_ref
-- хранится как прислали — TRIM только в ключе.
ALTER TABLE applications
    ADD COLUMN active_ref VARCHAR(32)
        GENERATED ALWAYS AS (IF(status = 'cancelled', NULL, TRIM(applicant_ref))) VIRTUAL,
    ADD UNIQUE KEY uq_applications_active_ref (active_ref);

-- 3. Проверка после ALTER.
SHOW CREATE TABLE applications;