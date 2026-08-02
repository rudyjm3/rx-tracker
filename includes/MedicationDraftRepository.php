<?php

declare(strict_types=1);

// Staging table for the multi-step "Add medication" wizard's "Save draft" action.
// Deliberately independent from OnboardingDraftRepository/setup_status (the first-run
// onboarding flow's own draft mechanism) — see migration 015 for why the two must not share
// state. Nothing here is written until the user explicitly clicks "Save draft".
final class MedicationDraftRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly int $userId = 0,
        private readonly ?int $profileId = null
    ) {
    }

    public function listDrafts(): array
    {
        $statement = $this->db->prepare(
            'SELECT id, form_data, current_step, furthest_step, created_at, updated_at
             FROM medication_drafts
             WHERE user_id = :user_id ' . $this->profileSql('') . '
             ORDER BY updated_at DESC'
        );
        $statement->execute(array_merge(['user_id' => $this->userId], $this->profileParam()));
        $rows = $statement->fetchAll();
        $drafts = [];
        foreach ($rows as $row) {
            $formData = json_decode((string) $row['form_data'], true);
            $drafts[] = [
                'id'            => (int) $row['id'],
                'name'          => is_array($formData) ? (string) ($formData['name'] ?? '') : '',
                'current_step'  => (int) $row['current_step'],
                'furthest_step' => (int) $row['furthest_step'],
                'created_at'    => $row['created_at'],
                'updated_at'    => $row['updated_at'],
            ];
        }
        return $drafts;
    }

    public function findDraft(int $id): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, form_data, current_step, furthest_step
             FROM medication_drafts
             WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
        );
        $statement->execute(array_merge(['id' => $id, 'user_id' => $this->userId], $this->profileParam()));
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $formData = json_decode((string) $row['form_data'], true);
        if (!is_array($formData)) {
            $formData = [];
        }
        return array_merge($formData, [
            'draft_id'      => (int) $row['id'],
            'current_step'  => (int) $row['current_step'],
            'furthest_step' => (int) $row['furthest_step'],
        ]);
    }

    public function saveDraft(?int $id, array $formData, int $currentStep, int $furthestStep): int
    {
        $encoded = json_encode($formData);
        if ($encoded === false) {
            throw new RuntimeException('Could not save draft.');
        }

        if ($id !== null && $this->findDraft($id) !== null) {
            $statement = $this->db->prepare(
                'UPDATE medication_drafts
                 SET form_data = :form_data, current_step = :current_step, furthest_step = :furthest_step,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
            );
            $statement->execute(array_merge([
                'id'            => $id,
                'user_id'       => $this->userId,
                'form_data'     => $encoded,
                'current_step'  => $currentStep,
                'furthest_step' => $furthestStep,
            ], $this->profileParam()));
            return $id;
        }

        $statement = $this->db->prepare(
            'INSERT INTO medication_drafts (user_id, profile_id, form_data, current_step, furthest_step)
             VALUES (:user_id, :profile_id, :form_data, :current_step, :furthest_step)'
        );
        $statement->execute([
            'user_id'       => $this->userId,
            'profile_id'    => $this->profileId,
            'form_data'     => $encoded,
            'current_step'  => $currentStep,
            'furthest_step' => $furthestStep,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteDraft(int $id): void
    {
        $statement = $this->db->prepare(
            'DELETE FROM medication_drafts WHERE id = :id AND user_id = :user_id ' . $this->profileSql('')
        );
        $statement->execute(array_merge(['id' => $id, 'user_id' => $this->userId], $this->profileParam()));
    }

    private function profileSql(string $alias = ''): string
    {
        $col = $alias !== '' ? "{$alias}.profile_id" : 'profile_id';
        return $this->profileId === null
            ? "AND {$col} IS NULL"
            : "AND {$col} = :profile_id";
    }

    private function profileParam(): array
    {
        return $this->profileId !== null ? ['profile_id' => $this->profileId] : [];
    }
}
