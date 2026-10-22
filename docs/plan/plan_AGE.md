# AGE Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Машины старше 20 лет получают решение `reject` независимо от LTV и сохраняются в БД как валидные заявки.

**Architecture:** Возрастная граница переезжает из валидации (422) в бизнес-решение: `ApplicationValidator` перестаёт отвергать возраст, `AssessmentService` выносит `reject` по порогу из `rules.php` до решения по LTV (вариант A, подтверждён заказчиком). `DecisionEngine` не меняется.

**Tech Stack:** PHP 8.3, Slim 4, PHPUnit.

**Spec:** `docs/spec/spec_AGE.md` (источник требований — `docs/intent/intent_AGE.md`).

## Global Constraints

- `declare(strict_types=1)`, классы `final`, namespace `CarMoneyLab\` (AGENTS.md).
- Бизнес-пороги — из `backend/config/rules.php`, не хардкод: `vehicle.max_age_years = 20`, `vehicle.min_year = 1990` (существующие ключи, новых не заводим).
- Граница строгая: `reject` при возрасте **> 20** (21+); ровно 20 — общие правила (REQ-AGE-02).
- `DecisionEngine`, LTV-пороги (`approve_max = 60.0`, `review_max = 85.0`), `ltv_by_age`, фронтенд, схема БД и seed — не трогать (spec — Не входит).
- Не читать `.env*`, не запускать `scripts/reset_db.sh` (AGENTS.md).
- Верификация: `make test`, `make lint` (работают локально после `composer install`).

## Review Focus

1. Возраст 21+ с низким LTV (раньше 422, теперь `reject`, а не `approve`) — тест `testRejectsVehicleOlderThanMaxAgeRegardlessOfLtv` (Task 1).
2. Ровно 20 лет не должно попасть под отказ — `testKeepsNormalDecisionAtExactMaxAge` (Task 1).
3. Год раньше `min_year = 1990` (возраст 37+) — всё ещё 422 — `testRejectsYearBelowMinYear` (Task 1).
4. Отказ по возрасту + пробег ≥ 400 000 км: `reject` не смягчается до `review` — `testOldVehicleRejectNotSoftenedByMileage` (Task 1).
5. Порог читается из конфига: смена `rules['vehicle']['max_age_years']` меняет поведение — `testReadsMaxAgeThresholdFromRules` (Task 1).

---

### Task 1: Правило `reject` по возрасту

**Files:**
- Modify: `backend/src/Domain/ApplicationValidator.php:35-41` (удалить ветку возраста)
- Modify: `backend/src/Domain/AssessmentService.php` (конструктор + правило)
- Modify: `backend/src/AppFactory.php:30-40` (передать порог)
- Test: `tests/Unit/AssessmentServiceTest.php`
- Test: `tests/Unit/ApplicationValidatorTest.php`

**Interfaces:**
- Consumes: `ApplicationValidator::validate(array $payload): array`, `LtvCalculator::calculate(int $amount, int $value): float`, `DecisionEngine::decide(float $ltv): string`, `VehicleAge::inYears(int $productionYear): int`.
- Produces: конструктор `AssessmentService(ApplicationValidator $validator, LtvCalculator $ltvCalculator, DecisionEngine $decisionEngine, VehicleAge $vehicleAge, int $reviewMileageKm, int $maxVehicleAgeYears)`; `assess()` возвращает прежний массив `{vehicle_age:int, ltv:float, decision:string, approved_limit:int, input:array}`.

- [ ] **Step 1: Красные тесты сервиса**

В `tests/Unit/AssessmentServiceTest.php` добавить (в возрастных тестах переопределять год в массиве из `$this->payload(...)`, helper не менять):

```php
public function testRejectsVehicleOlderThanMaxAgeRegardlessOfLtv(): void
{
    $payload = $this->payload(450000, 900000);
    $payload['year'] = (int) date('Y') - 21;

    $result = $this->service->assess($payload);

    self::assertSame(50.0, $result['ltv']);
    self::assertSame(DecisionEngine::REJECT, $result['decision']);
    self::assertSame(0, $result['approved_limit']);
    self::assertSame(21, $result['vehicle_age']);
}

public function testKeepsNormalDecisionAtExactMaxAge(): void
{
    $payload = $this->payload(450000, 900000);
    $payload['year'] = (int) date('Y') - 20;

    $result = $this->service->assess($payload);

    self::assertSame(DecisionEngine::APPROVE, $result['decision']);
    self::assertSame(450000, $result['approved_limit']);
}

public function testOldVehicleWithHighLtvStaysRejected(): void
{
    $payload = $this->payload(855000, 900000);
    $payload['year'] = (int) date('Y') - 21;

    $result = $this->service->assess($payload);

    self::assertSame(DecisionEngine::REJECT, $result['decision']);
}

public function testOldVehicleRejectNotSoftenedByMileage(): void
{
    $payload = $this->payload(450000, 900000, 400000);
    $payload['year'] = (int) date('Y') - 21;

    $result = $this->service->assess($payload);

    self::assertSame(DecisionEngine::REJECT, $result['decision']);
    self::assertSame(0, $result['approved_limit']);
}

public function testReadsMaxAgeThresholdFromRules(): void
{
    $rules = require __DIR__ . '/../../backend/config/rules.php';
    $rules['vehicle']['max_age_years'] = 10;
    $age = new VehicleAge((int) date('Y'));

    $service = new AssessmentService(
        new ApplicationValidator($rules, new VinValidator($rules['vin']), $age),
        new LtvCalculator(),
        new DecisionEngine($rules['ltv']),
        $age,
        (int) $rules['vehicle']['review_mileage_km'],
        (int) $rules['vehicle']['max_age_years'],
    );

    $atThreshold = $this->payload(450000, 900000);
    $atThreshold['year'] = (int) date('Y') - 10;
    self::assertSame(DecisionEngine::APPROVE, $service->assess($atThreshold)['decision']);

    $aboveThreshold = $this->payload(450000, 900000);
    $aboveThreshold['year'] = (int) date('Y') - 11;
    self::assertSame(DecisionEngine::REJECT, $service->assess($aboveThreshold)['decision']);
}
```

- [ ] **Step 2: Красные тесты валидатора**

В `tests/Unit/ApplicationValidatorTest.php` добавить:

```php
public function testAcceptsVehicleAboveMaxAge(): void
{
    $result = $this->validator->validate($this->validPayload(['year' => (int) date('Y') - 21]));

    self::assertSame((int) date('Y') - 21, $result['year']);
}

public function testRejectsYearBelowMinYear(): void
{
    try {
        $this->validator->validate($this->validPayload(['year' => 1989]));
        self::fail('Ожидали ValidationException');
    } catch (ValidationException $exception) {
        self::assertArrayHasKey('year', $exception->errors());
    }
}
```

- [ ] **Step 3: Прогнать новые тесты, убедиться в падении**

Run: `vendor/bin/phpunit tests/Unit/AssessmentServiceTest.php tests/Unit/ApplicationValidatorTest.php`
Expected: FAIL — возрастные кейсы падают с `ValidationException` («Возраст авто больше 20 лет»); `testRejectsYearBelowMinYear` уже зелёный (фиксация границы `min_year`).

- [ ] **Step 4: Удалить возрастную ветку из `ApplicationValidator`**

В `backend/src/Domain/ApplicationValidator.php:35-41` оставить только проверки `min_year` и будущего года:

```php
if ($year < $this->rules['vehicle']['min_year']) {
    $errors['year'] = sprintf('Год выпуска не раньше %d', $this->rules['vehicle']['min_year']);
} elseif ($age < 0) {
    $errors['year'] = 'Год выпуска не может быть в будущем';
}
```

- [ ] **Step 5: Реализовать правило в `AssessmentService`**

- Конструктор: добавить последний параметр `private readonly int $maxVehicleAgeYears` (после `$reviewMileageKm`).
- В `assess()`: вычислить `$age = $this->vehicleAge->inYears($input['year'])` один раз, решение по возрасту — до вызова `$this->decisionEngine->decide($ltv)`; LTV рассчитывается всегда; `$age` переиспользовать в `'vehicle_age' => $age`:

```php
$age = $this->vehicleAge->inYears($input['year']);
$ltv = $this->ltvCalculator->calculate($input['requested_amount'], $input['market_value']);
$decision = $age > $this->maxVehicleAgeYears
    ? DecisionEngine::REJECT
    : $this->decisionEngine->decide($ltv);
```

- Обновить PHPDoc класса: правило возраста (старше `max_age_years` → `reject`) плюс существующее правило пробега.
- `AppFactory.php`: передать `(int) $rules['vehicle']['max_age_years']` последним аргументом `AssessmentService`.
- `AssessmentServiceTest::setUp()` и существующий `testReadsReviewMileageThresholdFromRules`: передать порог `(int) $rules['vehicle']['max_age_years']` в конструктор.

- [ ] **Step 6: Прогнать весь набор**

Run: `make test`
Expected: PASS — все тесты, включая новые 7.

Run: `make lint`
Expected: без ошибок `php -l`.

- [ ] **Step 7: Ручная e2e-проверка (если доступен Docker)**

Run: `make up && curl -s -X POST http://localhost:8080/api/applications -H 'Content-Type: application/json' -d '{"vin":"XTA21099998765432","year":2005,"mileage":96000,"market_value":900000,"requested_amount":450000,"term_months":24}'`
Expected: HTTP 201, `"decision":"reject"`, `"approved_limit":0` (год 2005 → возраст 21).

Run: `curl -s -X POST http://localhost:8080/api/applications -H 'Content-Type: application/json' -d '{"vin":"XTA21099998765432","year":1989,"mileage":96000,"market_value":900000,"requested_amount":450000,"term_months":24}'`
Expected: HTTP 422, ошибка поля `year`. После проверки — `make down`.

Если Docker недоступен: пропустить шаг, отметить в отчёте, что e2e не проверяли.

- [ ] **Step 8: Коммит**

```bash
git add backend/src/Domain/ApplicationValidator.php backend/src/Domain/AssessmentService.php backend/src/AppFactory.php tests/Unit/AssessmentServiceTest.php tests/Unit/ApplicationValidatorTest.php docs/intent/intent_AGE.md docs/spec/spec_AGE.md docs/plan/plan_AGE.md
git commit -m "feat: reject applications for vehicles older than max age regardless of LTV"
```

## Риски

- Не заменить по ошибке `min_year`-границу порогом возраста: год < 1990 — по-прежнему 422 (AC-AGE-05).
- Не посчитать возраст дважды с разными источниками истины — вычислить один `$age` и переиспользовать его в правиле и в `vehicle_age`.
- Изменение конструктора `AssessmentService` затрагивает все места создания: `AppFactory`, `AssessmentServiceTest::setUp`, `testReadsReviewMileageThresholdFromRules`, `testReadsMaxAgeThresholdFromRules` — проверить поиском перед запуском тестов.
- Порядок в `assess()`: правило возраста — до `decide($ltv)`, но после `validate()`; LTV рассчитывается всегда (поле есть в ответе REQ-AGE-01).
- Даунгрейд по пробегу (`approve` → `review`) не должен выполняться/срабатывать для `reject` по возрасту — уже гарантировано существующим условием `=== APPROVE`, тест `testOldVehicleRejectNotSoftenedByMileage` фиксирует.

## Проверка плана против спеки

Самопроверка выполнена 2026-10-22: REQ-AGE-01…08 покрыты шагами Task 1 (AC-01…04, 08 — тесты сервиса; AC-05, 07 — тесты валидатора; AC-06 — Step 7 e2e; AC-09 — состав ответа не меняется, фиксируется существующим тестом `testResultHasNoReasonFieldAfterMileageDowngrade`). Разрывов нет.
