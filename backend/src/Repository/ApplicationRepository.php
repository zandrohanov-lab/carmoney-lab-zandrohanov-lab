<?php

declare(strict_types=1);

namespace CarMoneyLab\Repository;

use CarMoneyLab\Domain\DuplicateActiveApplicationException;
use PDO;
use PDOException;

/**
 * Хранение заявок, автомобилей и решений.
 */
final class ApplicationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{vin:string,year:int,mileage:int,market_value:int,requested_amount:int,term_months:int} $input
     * @param array{ltv:float,decision:string,approved_limit:int} $assessment
     */
    public function save(string $applicantRef, array $input, array $assessment): int
    {
        // Три INSERT выполняются в одной транзакции: если, например,
        // decisions не влезет в DECIMAL(6,2) (ошибка 1264) — applications
        // и vehicles тоже откатываются. Иначе в БД остаются «висящие»
        // applications без решения, и клиент навсегда заблокирован
        // по уникальному uq_applications_active_ref на следующей попытке.
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO applications (applicant_ref, requested_amount, term_months, status)
                 VALUES (:applicant_ref, :amount, :term, :status)'
            );

            try {
                $statement->execute([
                    ':applicant_ref' => $applicantRef,
                    ':amount' => $input['requested_amount'],
                    ':term' => $input['term_months'],
                    ':status' => 'decided',
                ]);
            } catch (PDOException $exception) {
                // Уникальный индекс uq_applications_active_ref не даёт клиенту
                // две активные заявки. Проверять это в коде нельзя: два
                // параллельных INSERT прошли бы SELECT-guard, решение держит БД.
                if (self::isDuplicateKeyError($exception)) {
                    $this->pdo->rollBack();
                    throw new DuplicateActiveApplicationException($applicantRef);
                }

                $this->pdo->rollBack();
                throw $exception;
            }

            $applicationId = (int) $this->pdo->lastInsertId();

            $vehicle = $this->pdo->prepare(
                'INSERT INTO vehicles (application_id, vin, production_year, mileage_km, market_value)
                 VALUES (:application_id, :vin, :year, :mileage, :market_value)'
            );
            $vehicle->execute([
                ':application_id' => $applicationId,
                ':vin' => $input['vin'],
                ':year' => $input['year'],
                ':mileage' => $input['mileage'],
                ':market_value' => $input['market_value'],
            ]);

            $decision = $this->pdo->prepare(
                'INSERT INTO decisions (application_id, ltv, decision, approved_limit)
                 VALUES (:application_id, :ltv, :decision, :approved_limit)'
            );
            $decision->execute([
                ':application_id' => $applicationId,
                ':ltv' => $assessment['ltv'],
                ':decision' => $assessment['decision'],
                ':approved_limit' => $assessment['approved_limit'],
            ]);

            $this->pdo->commit();

            return $applicationId;
        } catch (DuplicateActiveApplicationException $exception) {
            throw $exception;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }

    /** @return array<string,mixed> пустой массив, если заявки нет */
    public function find(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.applicant_ref, a.requested_amount, a.term_months, a.status, a.created_at,
                    v.vin, v.production_year, v.mileage_km, v.market_value,
                    d.ltv, d.decision, d.approved_limit
             FROM applications a
             LEFT JOIN vehicles v ON v.application_id = a.id
             LEFT JOIN decisions d ON d.application_id = a.id
             WHERE a.id = :id'
        );
        $statement->execute([':id' => $id]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? [] : $row;
    }

    /**
     * Список заявок для оператора.
     *
     * @return list<array<string,mixed>>
     */
    public function listApplications(?string $status = null, int $limit = 50): array
    {
        $sql = 'SELECT id, applicant_ref, requested_amount, term_months, status, created_at FROM applications';

        if ($status !== null && $status !== '') {
            $sql .= " WHERE status = '" . $status . "'";
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $vehicle = $this->pdo->prepare('SELECT vin, production_year FROM vehicles WHERE application_id = :id');
            $vehicle->execute([':id' => $row['id']]);
            $row['vehicle'] = $vehicle->fetch(PDO::FETCH_ASSOC) ?: null;

            $decision = $this->pdo->prepare('SELECT ltv, decision, approved_limit FROM decisions WHERE application_id = :id');
            $decision->execute([':id' => $row['id']]);
            $row['decision'] = $decision->fetch(PDO::FETCH_ASSOC) ?: null;

            $result[] = $row;
        }

        return $result;
    }

    /**
     * Дубликат по уникальному индексу MySQL: код 1062 и SQLSTATE 23000.
     * Оба признака проверяем — драйверы сообщают о них по-разному.
     */
    private static function isDuplicateKeyError(PDOException $exception): bool
    {
        return (int) $exception->getCode() === 1062 || (string) $exception->getCode() === '23000';
    }
}
