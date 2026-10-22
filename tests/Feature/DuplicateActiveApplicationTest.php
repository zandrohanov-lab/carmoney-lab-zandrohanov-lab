<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Feature;

use CarMoneyLab\AppFactory;
use CarMoneyLab\Database;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Задача MAS-DUP: у клиента не может быть двух активных заявок.
 *
 * Идентификатор клиента — applications.applicant_ref.
 * Активная заявка — status != 'cancelled' (допущение зафиксировано в docs/spec/spec_MAS-DUP.md).
 *
 * Тесты работают in-process: приложение собирается через AppFactory::create($pdo),
 * запрос создаётся ServerRequestCreator и уходит в Slim напрямую.
 * Подключение к БД — через Database::connect(): DSN/USER/PASSWORD берутся
 * из окружения (DB_DSN, DB_USER, DB_PASSWORD). Без них используется дефолт
 * `mysql:host=db;...`, который работает только внутри docker compose
 * (в этом проекте хост `db` не резолвится ни локально, ни из CI без
 * `make up`); из CI-окружения без docker, а также с голым `make test`
 * на хосте этот дефолт ведёт в никуда, и тесты пропускаются с явным
 * сообщением. Прогонять feature-тесты нужно либо внутри docker
 * (`make up && make test` в контейнере), либо с пробросом DB_DSN=
 * на отдельную чистую БД `carmoney_lab_test` (например, на той же машине
 * через DB_PORT=3307).
 *
 * Перед запуском нужно, чтобы в БД была колонка applications.active_ref
 * и UNIQUE uq_applications_active_ref — иначе ограничение не работает и
 * AC-2/4 невыполнимы. Схема — db/schema.sql, миграция для существующего
 * тома — db/migrations/2026_10_22_mas_dup_active_application.sql. На
 * старом томе без миграции тесты пропускаются с явным сообщением.
 *
 * Каждый тест работает со своим applicant_ref вида CL-TST-<uniqid>
 * и удаляет свои строки в tearDown. Связанные vehicles и decisions
 * уходят каскадом ON DELETE CASCADE.
 */
final class DuplicateActiveApplicationTest extends TestCase
{
    private PDO $pdo;

    /** applicant_ref, которые создал тест — чистим в tearDown */
    private array $refs = [];

    protected function setUp(): void
    {
        try {
            $this->pdo = Database::connect();
        } catch (PDOException $exception) {
            $this->markTestSkipped(sprintf(
                'DB недоступна (%s); нужен make up или DB_DSN=... DB_USER=... DB_PASSWORD=...',
                $exception->getMessage()
            ));
        }

        // Без active_ref ограничения UNIQUE нет — тесты AC-2/4 невыполнимы.
        try {
            $column = $this->pdo->query(
                "SHOW COLUMNS FROM applications LIKE 'active_ref'"
            )->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            $this->markTestSkipped(sprintf(
                'Не удалось прочитать схему applications (%s); нужна миграция из db/migrations/2026_10_22_mas_dup_active_application.sql',
                $exception->getMessage()
            ));
        }

        if ($column === false) {
            $this->markTestSkipped(
                'В таблице applications нет колонки active_ref; нужна миграция '
                . 'из db/migrations/2026_10_22_mas_dup_active_application.sql'
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->refs as $ref) {
            $delete = $this->pdo->prepare('DELETE FROM applications WHERE applicant_ref = :ref');
            $delete->execute([':ref' => $ref]);
        }

        $this->refs = [];
    }

    /** Уникальный синтетический идентификатор клиента под конкретный тест. */
    private function newRef(): string
    {
        $ref = 'CL-TST-' . strtoupper(bin2hex(random_bytes(6)));
        $this->refs[] = $ref;

        return $ref;
    }

    /** @return array<string,mixed> валидная заявка под нужного клиента */
    private function validPayload(string $applicantRef): array
    {
        return [
            'applicant_ref' => $applicantRef,
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 5,
            'mileage' => 84000,
            'market_value' => 900000,
            'requested_amount' => 450000,
            'term_months' => 24,
        ];
    }

    /** POST /api/applications — in-process запрос через настоящий HTTP-слой Slim */
    private function postApplication(array $payload): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'http://localhost/api/applications')
            ->withHeader('Content-Type', 'application/json')
            ->withParsedBody($payload);

        $app = AppFactory::create($this->pdo);

        return $app->handle($request);
    }

    /** @return array<string,mixed> */
    private function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        return (array) json_decode($body, true);
    }

    /** Сколько активных заявок у клиента сейчас в базе. */
    private function activeCount(string $applicantRef): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM applications WHERE applicant_ref = :ref AND status <> 'cancelled'"
        );
        $statement->execute([':ref' => $applicantRef]);

        return (int) $statement->fetchColumn();
    }

    /**
     * AC-1: первая заявка клиента создаётся — 201.
     */
    public function testFirstApplicationForClientIsCreated(): void
    {
        $ref = $this->newRef();

        $response = $this->postApplication($this->validPayload($ref));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->activeCount($ref));
    }

    /**
     * AC-2: повторная активная заявка того же клиента — 409.
     */
    public function testSecondActiveApplicationForSameClientIsRejectedWith409(): void
    {
        $ref = $this->newRef();

        self::assertSame(201, $this->postApplication($this->validPayload($ref))->getStatusCode());

        $response = $this->postApplication($this->validPayload($ref));

        self::assertSame(409, $response->getStatusCode());

        $data = $this->decode($response);
        self::assertArrayHasKey('errors', $data);
        self::assertArrayHasKey('applicant_ref', $data['errors']);

        // Отказ не создал второй активный запрос.
        self::assertSame(1, $this->activeCount($ref));
    }

    /**
     * AC-3: после закрытия (cancelled) предыдущей заявки новая разрешена.
     */
    public function testNewApplicationAllowedAfterPreviousOneCancelled(): void
    {
        $ref = $this->newRef();

        $first = $this->postApplication($this->validPayload($ref));
        self::assertSame(201, $first->getStatusCode());

        $firstId = (int) $this->decode($first)['id'];

        $cancel = $this->pdo->prepare("UPDATE applications SET status = 'cancelled' WHERE id = :id");
        $cancel->execute([':id' => $firstId]);

        $response = $this->postApplication($this->validPayload($ref));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(1, $this->activeCount($ref));
    }

    /**
     * AC-4: две одновременные вставки не создают двух активных заявок.
     *
     * Проверка на уровне БД: ограничение держит UNIQUE по вычисляемой колонке
     * active_ref, поэтому вторая независимая PDO-сессия получает дубликат (1062).
     * Последовательные HTTP-запросы здесь не годились бы — доказать
     * конкурентность без параллельных сессий нельзя, а HTTP-сервис в docker-compose
     * однопоточный (php -S). Этот тест доказывает ровно то, что должна доказывать
     * схема: два конкурентных INSERT одного клиента дают одну активную заявку.
     */
    public function testConcurrentInsertsProduceOnlyOneActiveApplication(): void
    {
        $ref = $this->newRef();

        // Первая (победившая) сессия вставляет заявку.
        $winner = Database::connect();
        $insert = $winner->prepare(
            'INSERT INTO applications (applicant_ref, requested_amount, term_months, status)
             VALUES (:ref, 450000, 24, :status)'
        );
        $insert->execute([':ref' => $ref, ':status' => 'decided']);

        // Вторая независимая сессия — конкурентная вставка того же клиента.
        $loser = Database::connect();

        try {
            $insert = $loser->prepare(
                'INSERT INTO applications (applicant_ref, requested_amount, term_months, status)
                 VALUES (:ref, 450000, 24, :status)'
            );
            $insert->execute([':ref' => $ref, ':status' => 'decided']);

            self::fail('Ожидали отказ по уникальному индексу uq_applications_active_ref');
        } catch (PDOException $exception) {
            self::assertSame(
                1062,
                (int) $exception->errorInfo[1],
                'Ожидали MySQL error 1062 (duplicate entry), получено: ' . $exception->getMessage()
            );
        }

        self::assertSame(1, $this->activeCount($ref));
    }

    /**
     * AC-2 (следствие): валидация остаётся первой — 422, а не 409,
     * даже если активная заявка уже есть.
     */
    public function testValidationErrorStillPrecedesDuplicateCheck(): void
    {
        $ref = $this->newRef();
        self::assertSame(201, $this->postApplication($this->validPayload($ref))->getStatusCode());

        $response = $this->postApplication(['vin' => 'BAD'] + $this->validPayload($ref));

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('vin', $this->decode($response)['errors']);
    }
}