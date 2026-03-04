<?php

namespace App\Service;

use App\Entity\Student;
use App\Entity\User;

final class UserAiSummaryService
{
    /**
     * @return array{
     *   score:int,
     *   riskLevel:string,
     *   headline:string,
     *   findings:list<string>,
     *   actions:list<string>
     * }
     */
    public function summarize(User $user): array
    {
        $score = 0;
        $findings = [];
        $actions = [];
        $student = $user->getProfile();

        if (!$user->isStatus()) {
            $score += 35;
            $findings[] = 'Account status is inactive at the user level.';
            $actions[] = 'Keep disabled unless identity and registration intent are verified.';
        } else {
            $findings[] = 'Account status is active.';
        }

        if (!$student instanceof Student) {
            $score += 40;
            $findings[] = 'No student profile is linked to this account.';
            $actions[] = 'Create or repair the profile link before enabling full access.';
        } else {
            if (!$student->isActive()) {
                $score += 20;
                $findings[] = 'Student profile is inactive.';
                $actions[] = 'Review profile activation state with account lifecycle status.';
            } else {
                $findings[] = 'Student profile is active.';
            }

            $validationStatus = (string) ($student->getValidationStatus() ?? 'unknown');
            if ($validationStatus === 'pending') {
                $score += 25;
                $findings[] = 'Validation status is pending.';
                $actions[] = 'Approve or decline registration after manual review.';
            } elseif ($validationStatus === 'rejected') {
                $score += 30;
                $findings[] = 'Validation status is rejected.';
                $actions[] = 'Keep restricted access and log rejection rationale.';
            } elseif ($validationStatus === 'suspended') {
                $score += 35;
                $findings[] = 'Validation status is suspended.';
                $actions[] = 'Escalate to admin review before re-activation.';
            } else {
                $findings[] = sprintf('Validation status is %s.', $validationStatus);
            }

            $bio = trim((string) $student->getBio());
            if ($bio === '') {
                $score += 10;
                $findings[] = 'Profile bio is missing.';
                $actions[] = 'Ask user to complete bio for trust and discoverability.';
            }

            $phone = (int) ($student->getPhone() ?? 0);
            if ($phone <= 0) {
                $score += 10;
                $findings[] = 'Phone number is missing or invalid.';
                $actions[] = 'Collect a valid phone number for account recovery.';
            }
        }

        $roles = $user->getRoles();
        $hasBusinessRole = in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_TUTOR', $roles, true);
        if ($hasBusinessRole && !$user->isStatus()) {
            $score += 10;
            $findings[] = 'Privileged role exists while account is disabled.';
            $actions[] = 'Confirm role assignment and enforce least privilege.';
        }

        $createdAt = $user->getCreatedAt();
        if ($createdAt instanceof \DateTimeImmutable) {
            $ageDays = (int) $createdAt->diff(new \DateTimeImmutable('now'))->format('%a');
            if ($ageDays <= 1 && !$user->isStatus()) {
                $score += 10;
                $findings[] = 'Newly created account is currently inactive.';
                $actions[] = 'Validate onboarding flow to avoid legitimate-user drop-off.';
            }
        }

        $score = max(0, min(100, $score));
        $riskLevel = $this->resolveRiskLevel($score);
        $headline = $this->buildHeadline($riskLevel, $user, $student);

        if ($actions === []) {
            $actions[] = 'No urgent action. Continue routine monitoring.';
        }

        return [
            'score' => $score,
            'riskLevel' => $riskLevel,
            'headline' => $headline,
            'findings' => array_values(array_unique($findings)),
            'actions' => array_values(array_unique($actions)),
        ];
    }

    private function resolveRiskLevel(int $score): string
    {
        if ($score >= 70) {
            return 'high';
        }
        if ($score >= 35) {
            return 'medium';
        }

        return 'low';
    }

    private function buildHeadline(string $riskLevel, User $user, ?Student $student): string
    {
        $name = (string) ($user->getFullName() ?? 'User');
        $validation = $student instanceof Student ? (string) ($student->getValidationStatus() ?? 'unknown') : 'missing-profile';

        return sprintf(
            'AI review: %s risk for %s (validation: %s).',
            strtoupper($riskLevel),
            $name,
            $validation
        );
    }
}

