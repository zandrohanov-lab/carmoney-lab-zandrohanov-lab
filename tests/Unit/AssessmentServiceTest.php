<?php

declare(strict_types=1);

namespace CarMoneyLab\Tests\Unit;

use CarMoneyLab\Domain\ApplicationValidator;
use CarMoneyLab\Domain\AssessmentService;
use CarMoneyLab\Domain\DecisionEngine;
use CarMoneyLab\Domain\LtvCalculator;
use CarMoneyLab\Domain\ValidationException;
use CarMoneyLab\Domain\VehicleAge;
use CarMoneyLab\Domain\VinValidator;
use PHPUnit\Framework\TestCase;

final class AssessmentServiceTest extends TestCase
{
    private AssessmentService $service;

    protected function setUp(): void
    {
        $rules = require __DIR__ . '/../../backend/config/rules.php';
        $age = new VehicleAge((int) date('Y'));

        $this->service = new AssessmentService(
            new ApplicationValidator($rules, new VinValidator($rules['vin']), $age),
            new LtvCalculator(),
            new DecisionEngine($rules['ltv']),
            $age,
            (int) $rules['vehicle']['review_mileage_km'],
        );
    }

    /** @return array<string,mixed> */
    private function payload(int $amount, int $marketValue, int $mileage = 96000): array
    {
        return [
            'vin' => 'XTA21099998765432',
            'year' => (int) date('Y') - 4,
            'mileage' => $mileage,
            'market_value' => $marketValue,
            'requested_amount' => $amount,
            'term_months' => 24,
        ];
    }

    public function testApprovesLowLtvAndSetsLimitToRequestedAmount(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000));

        self::assertSame(50.0, $result['ltv']);
        self::assertSame(DecisionEngine::APPROVE, $result['decision']);
        self::assertSame(450000, $result['approved_limit']);
        self::assertSame(4, $result['vehicle_age']);
    }

    public function testSendsMiddleLtvToReviewWithZeroLimit(): void
    {
        $result = $this->service->assess($this->payload(675000, 900000));

        self::assertSame(75.0, $result['ltv']);
        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testRejectsHighLtv(): void
    {
        $result = $this->service->assess($this->payload(855000, 900000));

        self::assertSame(95.0, $result['ltv']);
        self::assertSame(DecisionEngine::REJECT, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testKeepsApproveWhenMileageBelowReviewThreshold(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 399999));

        self::assertSame(DecisionEngine::APPROVE, $result['decision']);
        self::assertSame(450000, $result['approved_limit']);
    }

    public function testSendsApproveToReviewAtExactMileageThreshold(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 400000));

        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testSendsApproveToReviewAboveMileageThreshold(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 400001));

        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testKeepsReviewWhenMileageAboveThreshold(): void
    {
        $result = $this->service->assess($this->payload(675000, 900000, 400001));

        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testKeepsRejectWhenMileageAboveThreshold(): void
    {
        $result = $this->service->assess($this->payload(855000, 900000, 400001));

        self::assertSame(DecisionEngine::REJECT, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    public function testSendsApproveToReviewWithinMileageValidationRange(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 450000));

        self::assertSame(DecisionEngine::REVIEW, $result['decision']);
        self::assertSame(0, $result['approved_limit']);
    }

    /**
     * @dataProvider provideInvalidMileage
     */
    public function testRejectsInvalidMileageBeforeDecision(mixed $mileage): void
    {
        $payload = $this->payload(450000, 900000);
        if ($mileage === '__UNSET__') {
            unset($payload['mileage']);
        } else {
            $payload['mileage'] = $mileage;
        }

        try {
            $this->service->assess($payload);
            self::fail('Ожидали ValidationException');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('mileage', $exception->errors());
        }
    }

    /** @return array<string,array{mixed}> */
    public static function provideInvalidMileage(): array
    {
        return [
            'missing' => ['__UNSET__'],
            'null' => [null],
            'empty_string' => [''],
        ];
    }

    public function testReadsReviewMileageThresholdFromRules(): void
    {
        $rules = require __DIR__ . '/../../backend/config/rules.php';
        $rules['vehicle']['review_mileage_km'] = 450000;
        $age = new VehicleAge((int) date('Y'));

        $service = new AssessmentService(
            new ApplicationValidator($rules, new VinValidator($rules['vin']), $age),
            new LtvCalculator(),
            new DecisionEngine($rules['ltv']),
            $age,
            (int) $rules['vehicle']['review_mileage_km'],
        );

        $below = $service->assess($this->payload(450000, 900000, 400000));
        self::assertSame(DecisionEngine::APPROVE, $below['decision']);
        self::assertSame(450000, $below['approved_limit']);

        $at = $service->assess($this->payload(450000, 900000, 450000));
        self::assertSame(DecisionEngine::REVIEW, $at['decision']);
        self::assertSame(0, $at['approved_limit']);
    }

    public function testResultHasNoReasonFieldAfterMileageDowngrade(): void
    {
        $result = $this->service->assess($this->payload(450000, 900000, 400000));

        self::assertSame(
            ['vehicle_age', 'ltv', 'decision', 'approved_limit', 'input'],
            array_keys($result),
        );
        self::assertArrayNotHasKey('reason', $result);
        self::assertArrayNotHasKey('reasons', $result);
    }
}
