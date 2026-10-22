# MAS-DUP — запрет второй активной заявки одного клиента

Учебная задача carmoney-lab. Данные синтетические.

## Допущение об «активной» заявке

**Активная заявка — это заявка со `status <> 'cancelled'`. Закрытая — `status = 'cancelled'`.**

Допущение выведено из текущего состояния проекта, а не выбрано произвольно:

- `db/schema.sql` объявляет `status ENUM('new', 'decided', 'cancelled')`.
- `ApplicationRepository::save()` **всегда** пишет `'decided'`.
- Писателей `'new'` и `'cancelled'` в проекте нет (ветка, endpoint или фоновая задача, которая бы их ставила, отсутствуют).

Следствие: при трактовке «активная = `status = 'new'`» ни одна заявка никогда не была бы активной,
и AC-2 (409 на повторной заявке) и AC-4 (гонка двух INSERT) были бы невыполнимы by construction.
Поэтому единственное трактуемое определение — `status <> 'cancelled'`.

**Открытый долг:** закрывать заявки пока нечем — нет операции, которая переводит заявку в `cancelled`.
AC-3 проверяется прямым `UPDATE` в тесте. Когда появится такой эндпоинт, определение
«активной» нужно перепроверить ещё раз.

## Идентификатор клиента

`applications.applicant_ref` `VARCHAR(32)` (`db/schema.sql`), синтетический, ПДн не хранится.
В HTTP-запросе поле `applicant_ref`; при его отсутствии подставляется `'CL-ANON'`
(`ApplicationController::create()`) — то есть безполезные безымянные заявки делят один активный слот.

## Критерии приёмки

| AC | Проверка |
| --- | --- |
| AC-1 | Первая заявка клиента создаётся — `201`, в базе одна активная |
| AC-2 | Повторная активная заявка того же клиента — `409` с `errors.applicant_ref` |
| AC-3 | После перевода предыдущей заявки в `cancelled` новая заявка снова создаётся — `201` |
| AC-4 | Две конкурентные вставки одного клиента дают одну активную заявку, вторая получает MySQL `1062` |
| AC-5 | `make test` (53 теста) и `make lint` проходят |

## Изменения схемы

В таблицу `applications` добавлено (`db/schema.sql`, миграция `db/migrations/2026_10_22_mas_dup_active_application.sql`):

```sql
active_ref VARCHAR(32) GENERATED ALWAYS AS (IF(status = 'cancelled', NULL, TRIM(applicant_ref))) VIRTUAL,
UNIQUE KEY uq_applications_active_ref (active_ref)
```

Почему ограничение держит **база**, а не код: SELECT-guard в коде проигрывает гонку — два
параллельных INSERT успевают пройти проверку до того, как любой из них встанет в очередь на
вставку. Транзакция в `save()` атомарна только для трёх INSERT одной заявки (`beginTransaction`/
`commit()`/`rollBack()`, откат при сбое `vehicles`/`decisions`), но не сериализует параллельные
HTTP-запросы. Уникальный индекс решает гонку на уровне движка: вторая параллельная вставка
получает `1062` независимо от транзакции.

`active_ref` для закрытой заявки равен `NULL`, а несколько `NULL` в уникальном индексе MySQL
не конфликтуют — закрытая заявка освобождает слот (AC-3).

`find()` и `listApplications()` перечисляют колонки явно, поэтому новая вычисляемая колонка
в GET-ответы не попадает и публичный API не меняется.

## Необходимость миграции

**Схема применена только к новым томам** (`docker-entrypoint-initdb.d`). Существующий том
миграцией **не изменён**.

Миграция для существующего тома: `db/migrations/2026_10_22_mas_dup_active_application.sql`.
Перед `ALTER` в ней идёт read-only предпроверка дублей:

```sql
SELECT applicant_ref, COUNT(*) c
FROM applications WHERE status <> 'cancelled'
GROUP BY applicant_ref HAVING c > 1;
```

### Блокер на текущем томе — снят 2026-10-22

На томе `carmoney_lab` предпроверка возвращала:

```
applicant_ref   c
CL-0025         6
```

`ALTER` на этом томе падал с `ERROR 1062 (23000): Duplicate entry 'CL-0025' for key
'applications.uq_applications_active_ref'` — проверено фактически.

Владелец данных решил (2026-10-22): дубли обрабатывает сама миграция — у каждого
клиента остаётся одна активная заявка (последняя по `id`), остальные закрываются в
`'cancelled'`; строки не удаляются. Шаги 1–2 миграции добавлены в
`db/migrations/2026_10_22_mas_dup_active_application.sql`.

Миграция применена к живому тому `carmoney_lab` (прогон на копии, затем на томе):
`CL-0025` id 25, 26, 27, 29, 30 → `cancelled`, активной осталась id 31; активных
дублей 0; `active_ref` и `uq_applications_active_ref` на месте. Проверка на стенде:
свежий клиент → 201, повторный → 409; `CL-0025` с активной заявкой → 409.
`db/seed.sql` не менялся (там CL-1001..CL-1024, все `decided`, — на чистых томах
дублей нет). Feature-тесты дополнительно прогонялись на отдельной базе.

## Код

- `backend/src/Domain/DuplicateActiveApplicationException.php` — новое, по конвенции `ValidationException`, отдаёт `errors()` в форме 422.
- `ApplicationRepository::save()` — три INSERT обёрнуты в `beginTransaction()`/`commit()`. На **первом** INSERT отдельный `try/catch`: при `1062`/`SQLSTATE 23000` вызывается `rollBack()` и бросается `DuplicateActiveApplicationException`; прочие `PDOException` откатываются и пробрасываются. На втором/третьем INSERT внешний `catch (PDOException)` делает `rollBack()` (если транзакция ещё открыта) и пробрасывает исключение дальше. Доменное исключение бросается до вставок в `vehicles`/`decisions`; внешний 500 на ошибках 2-го/3-го INSERT теперь оставляет БД в консистентном состоянии (0 строк в `applications`/`vehicles`/`decisions`).
- `ApplicationController::create()` — `catch (DuplicateActiveApplicationException)` рядом с существующим `catch (ValidationException)`, ответ 409 той же формы, что 422 (фронт читает `data.errors`, `frontend/app.js`). Валидация 422 остаётся **первой** — дубль проверяется после неё.
- `AppFactory.php` не менялся.

## Проверка

`tests/Feature/DuplicateActiveApplicationTest.php` — 5 тестов, in-process через
`AppFactory::create($pdo)` и настоящий HTTP-слой Slim. AC-1/2/3 и приоритет валидации — через
HTTP; AC-4 — на уровне БД двумя независимыми PDO-сессиями (вторая получает `1062`),
потому что HTTP-сервис в `docker-compose` однопоточный (`php -S`) и честной конкурентности
не даёт.