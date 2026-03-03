<?php

namespace App\Service;

use App\Entity\Student;
use App\Entity\User;

final class RegistrationFraudScoringService
{
    /**
     * @return array{
     *   score:int,
     *   level:string,
     *   reasons:list<string>,
     *   recommendation:string
     * }
     */
    public function score(User $user, ?Student $student = null): array
    {
        $score = 0;
        $reasons = [];

        $email = strtolower(trim((string) $user->getEmail()));
        [$localPart, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $disposableDomains = [
            'mailinator.com', 'tempmail.com', '10minutemail.com', 'guerrillamail.com',
            'yopmail.com', 'sharklasers.com', 'trashmail.com', 'dispostable.com',
        ];
        if ($domain !== '' && in_array($domain, $disposableDomains, true)) {
            $score += 45;
            $reasons[] = 'Email domain is disposable/temporary.';
        }

        if ($localPart !== '') {
            $digitsCount = preg_match_all('/\d/', $localPart);
            $ratio = strlen($localPart) > 0 ? ($digitsCount / strlen($localPart)) : 0;
            if (strlen($localPart) >= 6 && $ratio >= 0.5) {
                $score += 15;
                $reasons[] = 'Email local-part has a high numeric ratio.';
            }
            if (preg_match('/(.)\1{3,}/', $localPart) === 1) {
                $score += 10;
                $reasons[] = 'Email local-part has repeated character patterns.';
            }
        }

        $fullName = trim((string) $user->getFullName());
        if (mb_strlen($fullName) < 5) {
            $score += 20;
            $reasons[] = 'Full name is very short.';
        }
        if (strpos($fullName, ' ') === false) {
            $score += 10;
            $reasons[] = 'Full name is a single token.';
        }
        if (preg_match_all('/\d/', $fullName) >= 3) {
            $score += 20;
            $reasons[] = 'Full name contains many numeric characters.';
        }

        if ($student instanceof Student) {
            $phone = (int) ($student->getPhone() ?? 0);
            if ($phone <= 0) {
                $score += 10;
                $reasons[] = 'Phone number is missing at registration.';
            }

            $bio = trim((string) ($student->getBio() ?? ''));
            if ($bio === '') {
                $score += 5;
                $reasons[] = 'Bio is empty.';
            }
        }

        $createdAt = $user->getCreatedAt();
        if ($createdAt instanceof \DateTimeImmutable) {
            $ageSeconds = (new \DateTimeImmutable('now'))->getTimestamp() - $createdAt->getTimestamp();
            if ($ageSeconds >= 0 && $ageSeconds <= 600) {
                $score += 5;
                $reasons[] = 'Very recent registration.';
            }
        }

        $score = max(0, min(100, $score));
        $level = $this->resolveLevel($score);
        if ($reasons === []) {
            $reasons[] = 'No strong fraud indicators detected from available profile data.';
        }

        return [
            'score' => $score,
            'level' => $level,
            'reasons' => $reasons,
            'recommendation' => $this->recommendationForLevel($level),
        ];
    }

    private function resolveLevel(int $score): string
    {
        if ($score >= 70) {
            return 'high';
        }
        if ($score >= 35) {
            return 'medium';
        }

        return 'low';
    }

    private function recommendationForLevel(string $level): string
    {
        if ($level === 'high') {
            return 'Manual verification required before approval.';
        }
        if ($level === 'medium') {
            return 'Request additional verification signals before approval.';
        }

        return 'Safe for normal review flow.';
    }
}

