# Spec MILEAGE

Источник требований: `docs/intent/intent_MILEAGE.md`. Числа — со ссылкой на источник.

## Входит / не входит

### Входит

- Правило решения по пробегу: `approve` при пробеге ≥ 400 000 км понижается до `review`
  (источник: intent — Constraints, ответ заказчика 2026-09-27).
- Порог правила решения в конфиге бизнес-правил `backend/config/rules.php`
  (vehicle, `review_mileage_km = 400000`), не хардкодом (источник: intent — Constraints).
- Правка валидации: неуказанный пробег (отсутствие ключа, `null`, `''`) — ошибка 422
  (источник: intent — Constraints, ответ в интервью).
- Пересчёт `approved_limit` до 0 после перевода в `review` по существующей логике
  результата (источник: intent — Constraints).
- Unit-тесты: `AssessmentServiceTest` (сочетания LTV и пробега, порог из `rules.php`),
  `ApplicationValidatorTest` (фиксация `ValidationException` для неуказанного пробега)
  (источник: docs/plan/plan_MILEAGE.md — Файлы, Тесты; intent — Constraints).

### Не входит

- Изменение формы фронтенда (источник: intent — Не входит).
- Изменение схемы БД и seed-данных (источник: intent — Не входит).
- Изменение существующих LTV-порогов, `max_mileage_km` (500000) и возрастных правил
  (источник: intent — Не входит; backend/config/rules.php:23,43-58).
- Изменение логики `DecisionEngine` по LTV (источник: intent — Не входит).
- Возврат причины перевода в `review` в ответе API (источник: intent — Не входит).
- HTTP feature-тест на 422 — отложен до появления изолированной инфраструктуры
  `tests/Feature/` (источник: intent — Constraints, ответ заказчика 2026-09-27).

## Требования

- **REQ-MILEAGE-01.** Если базовое решение по LTV — `approve` и пробег ≥ 400 000 км,
  решение понижается до `review` (источник: intent — Constraints; число 400000 —
  ответ заказчика 2026-09-27).
- **REQ-MILEAGE-02.** Правило только ужесточает решение: базовые `review` и `reject`
  (по LTV) не меняются, `reject` не смягчается (источник: intent — Constraints).
- **REQ-MILEAGE-03.** Порядок `validate → расчёт LTV → decide` сохраняется; правило
  пробега применяется только после успешной валидации, к валидированному пробегу
  (источник: intent — Constraints).
- **REQ-MILEAGE-04.** Пробег не указан (отсутствие ключа или `null`) — ошибка
  валидации (HTTP 422, `ValidationException` с ошибкой поля `mileage`), а не `review`;
  правило решения к таким заявкам не применяется (источник: intent — Constraints).
- **REQ-MILEAGE-05.** Пустая строка `''` в поле `mileage` — также ошибка валидации
  (HTTP 422), а не приведение к `0` (источник: intent — Constraints, ответ в интервью).
- **REQ-MILEAGE-06.** Валидационная граница `max_mileage_km = 500000` остаётся без
  изменений и не заменяется порогом решения 400000; заявки с пробегом
  400 000–500 000 км получают `review`, а не 422 (источник: intent — Constraints;
  число 500000 — backend/config/rules.php:23).
- **REQ-MILEAGE-07.** После перевода `approve` в `review` по пробегу `approved_limit`
  равен 0 (источник: intent — Constraints; существующая логика результата —
  docs/plan/plan_MILEAGE.md — шаг 6).
- **REQ-MILEAGE-08.** Порог правила решения задаётся в `backend/config/rules.php`
  (блок `vehicle`, `review_mileage_km = 400000`), код читает его из конфига
  (источник: intent — Constraints; docs/plan/plan_MILEAGE.md — шаг 1).
- **REQ-MILEAGE-09.** Ответ API не дополняется причиной перевода в `review`:
  существующее поле `decision`, новых полей нет (источник: intent — Constraints,
  ответ в интервью).
- **REQ-MILEAGE-10.** Поведение 422 фиксируется unit-тестом в
  `ApplicationValidatorTest` (`ValidationException`); HTTP feature-тест на 422 в этой
  задаче не создаётся (источник: intent — Constraints, ответ заказчика 2026-09-27).

## Критерии приёмки

Формат Given / When / Then. Базовое решение задаётся LTV: `approve` — LTV ≤ 60,0 %,
`review` — 60,0 % < LTV ≤ 85,0 %, `reject` — LTV > 85,0 % (источник чисел:
backend/config/rules.php:44-45).

- **AC-MILEAGE-01** (REQ-MILEAGE-01, REQ-MILEAGE-07). Given заявка с LTV в зоне
  `approve` и `mileage = 399999`. When рассчитано решение. Then `decision = approve`,
  `approved_limit` равен запрошенной сумме — порог не сработал (граница: 399999).
- **AC-MILEAGE-02** (REQ-MILEAGE-01, REQ-MILEAGE-07). Given заявка с LTV в зоне
  `approve` и `mileage = 400000`. When рассчитано решение. Then `decision = review`,
  `approved_limit = 0` — ровно на пороге переводится в `review`, граница строгая
  (граница: 400000; источник — ответ заказчика 2026-09-27).
- **AC-MILEAGE-03** (REQ-MILEAGE-01, REQ-MILEAGE-07). Given заявка с LTV в зоне
  `approve` и `mileage = 400001`. When рассчитано решение. Then `decision = review`,
  `approved_limit = 0` (граница: 400001).
- **AC-MILEAGE-04** (REQ-MILEAGE-02). Given заявка с LTV в зоне `review` и
  `mileage = 400001`. When рассчитано решение. Then `decision = review`,
  `approved_limit = 0` — правило не изменило базовое `review`.
- **AC-MILEAGE-05** (REQ-MILEAGE-02). Given заявка с LTV выше порога `reject` и
  `mileage = 400001`. When рассчитано решение. Then `decision = reject` — `reject`
  не смягчается до `review`.
- **AC-MILEAGE-06** (REQ-MILEAGE-03, REQ-MILEAGE-04). Given заявка без ключа
  `mileage`. When выполняется расчёт. Then валидация завершается `ValidationException`
  с ошибкой поля `mileage` (на HTTP-границе — 422), правило решения не применяется —
  неизвестный пробег.
- **AC-MILEAGE-07** (REQ-MILEAGE-04). Given заявка с `mileage = null`. When выполняется
  расчёт. Then `ValidationException` с ошибкой поля `mileage` (422), не `review`.
- **AC-MILEAGE-08** (REQ-MILEAGE-05). Given заявка с `mileage = ''`. When выполняется
  расчёт. Then `ValidationException` с ошибкой поля `mileage` (422), а не приведение
  к `0` — пустой пробег.
- **AC-MILEAGE-09** (REQ-MILEAGE-01, REQ-MILEAGE-06). Given заявка с LTV в зоне
  `approve` и `mileage = 450000` (внутри диапазона 400 000–500 000). When рассчитано
  решение. Then `decision = review`, а не 422 — `max_mileage_km` не заменён порогом
  решения (источник числа 450000: произвольное значение внутри диапазона из
  intent — Constraints).
- **AC-MILEAGE-10** (REQ-MILEAGE-06). Given заявка с `mileage = 500001` (выше
  `max_mileage_km = 500000`). When выполняется расчёт. Then ошибка валидации 422 —
  валидационная граница действует как прежде (источник: backend/config/rules.php:23;
  backend/src/Domain/ApplicationValidator.php:44).
- **AC-MILEAGE-11** (REQ-MILEAGE-08). Given `rules.php` загружен с
  `vehicle.review_mileage_km = 400000`. When `AssessmentService` строится. Then порог
  читается из конфига, а не хардкодится в коде (источник: intent — Constraints;
  docs/plan/plan_MILEAGE.md — шаг 1).
- **AC-MILEAGE-12** (REQ-MILEAGE-09, REQ-MILEAGE-10). Given заявка, переведённая
  в `review` по пробегу. When формируется ответ API. Then ответ содержит существующее
  поле `decision = review` без новых полей с причиной; поведение 422 покрыто unit-тестом
  `ApplicationValidatorTest`, HTTP feature-тест не создаётся.

## Open questions из intent

Все закрыты 2026-09-27 (источник: intent — Open questions); новых открытых вопросов нет.

| Вопрос | Статус |
|---|---|
| HTTP feature-тест на 422 | Закрыт: заказчик — только unit-тест, feature-тест отложен до инфраструктуры `tests/Feature/` (перенесено в Constraints → REQ-MILEAGE-10) |
| Поведение `''` | Закрыт: заказчик — 422, правка валидации в scope (перенесено в Constraints → REQ-MILEAGE-05) |
| Включительность границы 400 000 | Закрыт: ровно 400 000 переводится в `review`, граница строгая (Constraints → REQ-MILEAGE-01, AC-MILEAGE-02) |
| Карта кода `docs/setup/code_map.md` | Закрыт: файл существует и актуален, покрывает точки изменения; новая карта не требуется |
