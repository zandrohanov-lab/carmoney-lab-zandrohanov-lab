<?php

declare(strict_types=1);

namespace CarMoneyLab\Domain;

/**
 * Предварительная оценка заявки: валидация -> LTV -> решение -> лимит.
 *
 * Решение по возрасту: авто старше max_age_years -> reject, независимо от LTV.
 * Решение по пробегу: approve при пробеге >= review_mileage_km понижается до review.
 *
 * Лимит сейчас равен запрошенной сумме при approve и нулю в остальных случаях.
 * Расчёт лимита по максимальному LTV для возраста авто (справочник
 * rules.ltv_by_age) — задача LOAN-12, она ещё не сделана.
 */
final class AssessmentService
{
    public function __construct(
        private readonly ApplicationValidator $validator,
        private readonly LtvCalculator $ltvCalculator,
        private readonly DecisionEngine $decisionEngine,
        private readonly VehicleAge $vehicleAge,
        private readonly int $reviewMileageKm,
        private readonly int $maxVehicleAgeYears,
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{vehicle_age:int,ltv:float,decision:string,approved_limit:int,input:array<string,mixed>}
     */
    public function assess(array $payload): array
    {
        $input = $this->validator->validate($payload);

        $age = $this->vehicleAge->inYears($input['year']);
        $ltv = $this->ltvCalculator->calculate($input['requested_amount'], $input['market_value']);
        $decision = $age > $this->maxVehicleAgeYears
            ? DecisionEngine::REJECT
            : $this->decisionEngine->decide($ltv);

        if ($decision === DecisionEngine::APPROVE && $input['mileage'] >= $this->reviewMileageKm) {
            $decision = DecisionEngine::REVIEW;
        }

        return [
            'vehicle_age' => $age,
            'ltv' => $ltv,
            'decision' => $decision,
            'approved_limit' => $decision === DecisionEngine::APPROVE ? $input['requested_amount'] : 0,
            'input' => $input,
        ];
    }
}
