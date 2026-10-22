-- Схема учебного сервиса carmoney-lab. MySQL 8.
-- Применяется автоматически при первом запуске контейнера db.

CREATE TABLE IF NOT EXISTS applications (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    applicant_ref   VARCHAR(32)  NOT NULL COMMENT 'синтетический идентификатор клиента, ПДн не храним',
    requested_amount INT UNSIGNED NOT NULL,
    term_months     SMALLINT UNSIGNED NOT NULL,
    status          ENUM('new', 'decided', 'cancelled') NOT NULL DEFAULT 'new',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Заявка считается активной, пока статус не 'cancelled'. Для активных
    -- заявок active_ref равен applicant_ref, для закрытых — NULL.
    -- UNIQUE по active_ref не даёт клиенту две активные заявки; NULL в MySQL
    -- не конфликтует, поэтому закрытая заявка ключ освобождает.
    -- Ограничение держит именно БД: кодовый SELECT не защищает от гонки
    -- двух параллельных INSERT.
    -- В utf8mb4_0900_ai_ci (NO PAD) 'X' и 'X ' — разные ключи, поэтому
    -- applicant_ref оборачивается в TRIM(): хвостовой/ведущий пробел в
    -- client_ref больше не открывает «второй активный слот» для того же
    -- клиента. Сам applicant_ref хранится как прислали — TRIM только в ключе.
    active_ref      VARCHAR(32) GENERATED ALWAYS AS (IF(status = 'cancelled', NULL, TRIM(applicant_ref))) VIRTUAL,
    PRIMARY KEY (id),
    KEY idx_applications_status (status),
    UNIQUE KEY uq_applications_active_ref (active_ref)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS vehicles (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id  INT UNSIGNED NOT NULL,
    vin             CHAR(17) NOT NULL,
    make            VARCHAR(40) NULL,
    model           VARCHAR(40) NULL,
    production_year SMALLINT UNSIGNED NOT NULL,
    mileage_km      INT UNSIGNED NOT NULL,
    market_value    INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_vehicles_application (application_id),
    CONSTRAINT fk_vehicles_application FOREIGN KEY (application_id)
        REFERENCES applications (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS decisions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id  INT UNSIGNED NOT NULL,
    ltv             DECIMAL(6, 2) NOT NULL,
    decision        ENUM('approve', 'review', 'reject') NOT NULL,
    approved_limit  INT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_decisions_application (application_id),
    CONSTRAINT fk_decisions_application FOREIGN KEY (application_id)
        REFERENCES applications (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
