<?php

declare(strict_types=1);

final class OnboardingService
{
    public function __construct(private readonly MedicationRepository $repository) {}

    public function getOrCreateProgress(): array
    {
        $progress = $this->repository->getOnboardingProgress();
        if ($progress === null) {
            $this->repository->upsertOnboardingProgress('in_progress', 'medications');
            $progress = $this->repository->getOnboardingProgress();
        }
        return $progress ?? ['status' => 'in_progress', 'current_step' => 'medications'];
    }

    public function isCompleted(): bool
    {
        $progress = $this->repository->getOnboardingProgress();
        return $progress !== null && (string) $progress['status'] === 'completed';
    }

    public function activateAll(string $trackingStartedAt): int
    {
        $count = $this->repository->activateOnboardingMedications($trackingStartedAt);
        $this->repository->upsertOnboardingProgress('completed', 'done');
        return $count;
    }
}
