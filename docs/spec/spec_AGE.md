# Spec AGE

Источник требований: `docs/intent/intent_AGE.md` (интервью с заказчиком
2026-10-22). Числа — со ссылкой на источник.

## Входит / не входит

### Входит

- Правило решения по возрасту: возраст авто > 20 лет → `reject` независимо
  от LTV (источник: intent — Замысел).
- Перенос границы из валидации в решение: возраст 21+ — валидная заявка
  с `decision = reject`, `approved_limit = 0`, сохранение в БД; не 422
  (источник: intent — Constraints, ответ заказчика 2026-10-22).
- Порог правила — существующий `vehicle.max_age_years = 20` из
  `backend/config/rules.php`, без нового ключа (источник: intent — Constraints).
- Правило в `AssessmentService` (вариант A), порог через конструктор как
  `review_mileage_km`; `DecisionEngine` не меняется (источник: intent —
  Constraints, ответ заказчика 2026-10-22).
- Удаление возрастной ветки из `ApplicationValidator`; `min_year = 1990` и
  проверка будущего года остаются (источник: intent — Constraints).
- Unit-тесты: `AssessmentServiceTest` (границы 20/21, сочетания с LTV и
  пробегом, порог из `rules.php`), `ApplicationValidatorTest` (возраст 21+
  проходит валидацию, год < 1990 — ошибка) (источник: intent — Constraints).

### Не входит

- Изменение формы фронтенда (источник: intent — Не входит).
- Изменение схемы БД и seed-данных; миграция не нужна — `reject` уже в ENUM
  `decisions` (источник: intent — Не входит; db/schema.sql:34).
- Изменение LTV-порогов и `ltv_by_age`, расчёт лимита по возрасту — LOAN-12
  (источник: intent — Не входит).
- Изменение логики `DecisionEngine` по LTV (источник: intent — Не входит).
- Возврат причины отказа в ответе API (источник: intent — Не входит).
- HTTP feature-тест — отложен до инфраструктуры `tests/Feature/`
  (источник: intent — Constraints, решение по MILEAGE 2026-09-27).

## Требования

- **REQ-AGE-01.** Если возраст авто строго больше `max_age_years` (20),
  решение — `reject`, независимо от рассчитанного LTV. LTV всё равно
  рассчитывается и возвращается в ответе (источник: intent — Замысел,
  Constraints).
- **REQ-AGE-02.** Возраст ровно 20 лет — заявка обрабатывается по общим
  правилам: решение по LTV, затем даунгрейд по пробегу (источник: intent —
  Constraints; граница «старше 20» строгая).
- **REQ-AGE-03.** `ApplicationValidator` не отвергает заявку по возрасту:
  ветка `age > max_age_years` удаляется. Остаются `min_year = 1990`
  (422 для более ранних годов) и «год не в будущем» (422) (источник: intent —
  Constraints).
- **REQ-AGE-04.** Порог читается из конфига: `AssessmentService` принимает
  `vehicle.max_age_years` через конструктор, `AppFactory` передаёт значение
  из `rules.php`; без хардкода (источник: intent — Constraints; precedent
  `review_mileage_km` — backend/src/AppFactory.php:39).
- **REQ-AGE-05.** При `reject` по возрасту `approved_limit = 0` — по
  существующей логике результата (источник: intent — Constraints; код:
  backend/src/Domain/AssessmentService.php:44).
- **REQ-AGE-06.** Заявка с возрастом 21+ проходит полный путь обычной
  заявки: валидация, расчёт, сохранение в БД, ответ POST /api/applications —
  201 с `decision = reject`, POST /api/ltv — 200 (источник: intent —
  Constraints, ответ заказчика 2026-10-22).
- **REQ-AGE-07.** `reject` по возрасту финален: не смягчается даунгрейдом
  пробега (тот понижает только `approve` до `review`) и не зависит от
  базового решения по LTV (источник: intent — Constraints).
- **REQ-AGE-08.** Состав полей ответа не меняется: `vehicle_age`, `ltv`,
  `decision`, `approved_limit` (+ `id` для create); поле причины не
  добавляется (источник: intent — Constraints).

## Критерии приёмки

Формат Given / When / Then. Базовое решение по LTV: `approve` — LTV ≤ 60,0 %,
`review` — 60,0 % < LTV ≤ 85,0 %, `reject` — LTV > 85,0 %; даунгрейд по пробегу:
`approve` + пробег ≥ 400 000 км → `review` (источник чисел:
backend/config/rules.php:44-45, 24).

- **AC-AGE-01** (REQ-AGE-01, REQ-AGE-05, REQ-AGE-08). Given заявка с возрастом
  авто 21 год, LTV 50 % (зона `approve`) и пробегом ниже порога. When рассчитано
  решение. Then `decision = reject`, `approved_limit = 0`, в ответе те же поля,
  `ltv = 50.0`, `vehicle_age = 21` — возраст перекрыл низкий LTV.
- **AC-AGE-02** (REQ-AGE-02). Given заявка с возрастом авто ровно 20 лет, LTV 50 %
  и пробегом ниже порога. When рассчитано решение. Then `decision = approve`,
  `approved_limit` равен запрошенной сумме — граница строгая, 20 лет допустимо.
- **AC-AGE-03** (REQ-AGE-01, REQ-AGE-07). Given заявка с возрастом 21 год и
  LTV 95 % (зона `reject`). When рассчитано решение. Then `decision = reject` —
  как и по LTV, правила не конфликтуют.
- **AC-AGE-04** (REQ-AGE-07). Given заявка с возрастом 21 год, LTV 50 % и пробегом
  400 000 км. When рассчитано решение. Then `decision = reject`, а не `review` —
  даунгрейд по пробегу не смягчает отказ по возрасту.
- **AC-AGE-05** (REQ-AGE-03). Given заявка с годом выпуска 1989 (раньше
  `min_year = 1990`). When выполняется расчёт. Then `ValidationException` с
  ошибкой поля `year` (422) — валидационная граница по году действует как прежде.
- **AC-AGE-06** (REQ-AGE-03). Given заявка с годом выпуска в будущем. When
  выполняется расчёт. Then `ValidationException` с ошибкой поля `year` (422) —
  проверка будущего года сохранена.
- **AC-AGE-07** (REQ-AGE-03, REQ-AGE-06). Given заявка с возрастом авто 21 год
  и остальными валидными полями. When выполняется валидация. Then валидация
  проходит без ошибки поля `year` — возраст больше не валидационная ошибка.
- **AC-AGE-08** (REQ-AGE-04). Given `rules.php` загружен с
  `vehicle.max_age_years = 10`. When `AssessmentService` обрабатывает заявки
  с возрастом 10 и 11 лет. Then возраст 10 — решение по общим правилам,
  возраст 11 — `reject`: порог читается из конфига, а не хардкодится.
- **AC-AGE-09** (REQ-AGE-06, REQ-AGE-08). Given заявка с возрастом 21 год.
  When заявка отправлена на POST /api/applications. Then ответ 201 с
  `decision = reject`, заявка сохранена в БД; состав полей ответа прежний,
  без причины отказа (покрытие: unit-тесты решения и валидатора; HTTP-граница —
  существующий контроллер без изменений, ручная проверка `make up` + curl).

## Open questions из intent

Все закрыты 2026-10-22 (источник: intent — Open questions); новых открытых
вопросов нет.

| Вопрос | Статус |
|---|---|
| 422 или решение `reject` | Закрыт: валидная заявка с `reject`, сохраняется в БД (Constraints → REQ-AGE-01, REQ-AGE-06) |
| Место правила | Закрыт: вариант A — `AssessmentService`, порог из конфига (Constraints → REQ-AGE-04) |
