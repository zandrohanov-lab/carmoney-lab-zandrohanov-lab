# Судейский отчёт ДЗ.1: MILEAGE

Дата проверки: 2026-09-27.

## Вердикт

Требование «каждый REQ покрыт тестом» **не выполнено полностью**. Из десяти
требований семь покрыты напрямую, три покрыты лишь частично. Лишних
требований в `docs/spec/spec_MILEAGE.md` не обнаружено: каждое следует из
`docs/intent/intent_MILEAGE.md` и относится к заявленному изменению правила
пробега.

`make test` выполнен успешно: 36 тестов, 50 assertions.

## Матрица REQ → тесты

| REQ | Статус | Подтверждение | Вывод |
|---|---|---|---|
| REQ-MILEAGE-01 | Покрыт | `AssessmentServiceTest::testKeepsApproveWhenMileageBelowReviewThreshold` (399999), `::testSendsApproveToReviewAtExactMileageThreshold` (400000), `::testSendsApproveToReviewAboveMileageThreshold` (400001); строки 74-96 | Проверены обе стороны и точная граница правила `>= 400000`. |
| REQ-MILEAGE-02 | Покрыт | `AssessmentServiceTest::testKeepsReviewWhenMileageAboveThreshold`, `::testKeepsRejectWhenMileageAboveThreshold`; строки 98-112 | Базовые `review` и `reject` не изменяются. |
| REQ-MILEAGE-03 | Частично покрыт | Все интеграционные проверки `AssessmentServiceTest` проходят через `assess()`; тесты валидатора подтверждают отказ для невалидного пробега | Нет теста на порядок операций: нужно вызвать `AssessmentService::assess()` с невалидным `mileage` и проверить `ValidationException` до выдачи решения. Прямой тест валидатора не доказывает оркестрацию сервиса. |
| REQ-MILEAGE-04 | Покрыт | `ApplicationValidatorTest::testRejectsMissingMileage`, `::testRejectsNullMileage`; строки 83-104 | В обоих случаях проверяются `ValidationException` и ошибка поля `mileage`. HTTP 422 является маппингом исключения, feature-тест не требуется по REQ-MILEAGE-10. |
| REQ-MILEAGE-05 | Покрыт | `ApplicationValidatorTest::testRejectsEmptyStringMileage`; строки 106-114 | Пустая строка отвергается как ошибка поля `mileage`, а не преобразуется в `0`. |
| REQ-MILEAGE-06 | Покрыт | `AssessmentServiceTest::testSendsApproveToReviewWithinMileageValidationRange` (450000); строки 114-120. `ApplicationValidatorTest::testRejectsMileageAboveMax` (500001); строки 116-124 | Проверены допустимый пробег внутри 400000-500000 и сохранение максимума 500000. |
| REQ-MILEAGE-07 | Покрыт | Тесты границы из REQ-MILEAGE-01, особенно `::testSendsApproveToReviewAtExactMileageThreshold` и `::testSendsApproveToReviewAboveMileageThreshold`; строки 82-95 | После понижения в `review` явно проверяется `approved_limit = 0`. |
| REQ-MILEAGE-08 | Частично покрыт | `AssessmentServiceTest::setUp()` загружает `rules.php` и передаёт `$rules['vehicle']['review_mileage_km']`; строки 21-30 | Тест фиксирует способ сборки тестового экземпляра, но не доказывает чтение порога вместо хардкода: при значении конфига, отличном от 400000, результат не проверяется. Нужен тест с изменённым тестовым правилом, например 450000, и пробегом 400000 либо 450000. |
| REQ-MILEAGE-09 | Частично покрыт | В unit-тестах `AssessmentServiceTest` результат содержит ожидаемые `decision` и `approved_limit` | Нет проверки состава HTTP API-ответа или результата `assess()` на отсутствие поля с причиной. Требование об отсутствии нового поля остаётся незафиксированным тестом. |
| REQ-MILEAGE-10 | Покрыт | `ApplicationValidatorTest::testRejectsMissingMileage`, `::testRejectsNullMileage`, `::testRejectsEmptyStringMileage`; строки 83-114 | Поведение зафиксировано unit-тестами через `ValidationException`; feature-тест намеренно не нужен согласно самой спецификации. |

## Непокрытые части

1. **REQ-MILEAGE-03:** добавить в `AssessmentServiceTest` тест с отсутствующим,
   `null` или `''` значением `mileage`, ожидающий `ValidationException` с ключом
   `mileage`. Это зафиксирует, что правило пробега не применяется до успешной
   валидации.
2. **REQ-MILEAGE-08:** создать `AssessmentService` с копией `$rules`, где
   `vehicle.review_mileage_km` изменён, например, на `450000`; проверить, что
   заявка с пробегом `400000` остаётся `approve`, а с `450000` получает `review`.
   Такой тест отличит чтение конфигурации от хардкода `400000`.
3. **REQ-MILEAGE-09:** зафиксировать прежний контракт результата. При отсутствии
   feature-инфраструктуры допустим unit-тест `AssessmentService::assess()`, который
   сверяет ключи результата для заявки, пониженной по пробегу, и подтверждает
   отсутствие поля причины. Если контракт требуется именно для HTTP, проверку
   следует отложить вместе с разрешённым в REQ-MILEAGE-10 feature-тестом.

## Проверка лишних требований

Лишних REQ нет.

| REQ | Основание в intent |
|---|---|
| REQ-MILEAGE-01 | Порог 400000 и включение порога: `intent_MILEAGE.md:17-20`. |
| REQ-MILEAGE-02 | Правило только ужесточает решение: `intent_MILEAGE.md:21-23`. |
| REQ-MILEAGE-03 | Порядок `validate → LTV → decide`: `intent_MILEAGE.md:24-26`. |
| REQ-MILEAGE-04, REQ-MILEAGE-05 | Неуказанный пробег, включая `null` и `''`, даёт 422: `intent_MILEAGE.md:27-29`, `43-46`. |
| REQ-MILEAGE-06 | `max_mileage_km = 500000` отдельно от порога решения: `intent_MILEAGE.md:30-33`, `40-42`. |
| REQ-MILEAGE-07 | Нулевой лимит после `review`: `intent_MILEAGE.md:34-35`. |
| REQ-MILEAGE-08 | Конфигурационный, а не захардкоженный порог: `intent_MILEAGE.md:36-37`. |
| REQ-MILEAGE-09 | Без причины в API-ответе: `intent_MILEAGE.md:38-39`. |
| REQ-MILEAGE-10 | Достаточен unit-тест, HTTP feature-тест отложен: `intent_MILEAGE.md:47-50`. |

## Ограничение проверки

Проверка основана на `docs/spec/spec_MILEAGE.md`, `docs/intent/intent_MILEAGE.md`,
текущих PHPUnit-тестах и исходном коде. Наличие HTTP 422 в рантайме не проверялось
feature-тестом, так как REQ-MILEAGE-10 явно исключает его из объёма задачи.
