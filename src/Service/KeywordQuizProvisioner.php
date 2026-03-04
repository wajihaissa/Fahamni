<?php

namespace App\Service;

use App\Entity\Choice;
use App\Entity\Question;
use App\Entity\Quiz;
use Doctrine\ORM\EntityManagerInterface;

class KeywordQuizProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GeminiQuizService $geminiQuizService
    ) {
    }

    public function normalizeKeyword(string $keyword): string
    {
        $keyword = trim(mb_strtolower($keyword));
        $keyword = preg_replace('/\s+/', ' ', $keyword) ?? $keyword;

        return trim($keyword);
    }

    /**
     * @param array<int, string> $keywords
     * @return array<int, string>
     */
    public function normalizeKeywords(array $keywords): array
    {
        $normalized = [];
        foreach ($keywords as $keyword) {
            $value = $this->normalizeKeyword((string) $keyword);
            if ($value !== '' && !in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    public function parseKeywordsFromInput(string $rawInput): array
    {
        $parts = preg_split('/[,;\r\n]+/', $rawInput) ?: [];
        return $this->normalizeKeywords($parts);
    }

    public function findQuizByKeyword(string $keyword): ?Quiz
    {
        $normalized = $this->normalizeKeyword($keyword);
        if ($normalized === '') {
            return null;
        }

        $quiz = $this->entityManager->getRepository(Quiz::class)
            ->createQueryBuilder('q')
            ->where('LOWER(q.keyword) = :keyword')
            ->setParameter('keyword', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($quiz instanceof Quiz) {
            return $quiz;
        }

        // Legacy fallback: previously generated quizzes used title-only matching.
        $legacyQuiz = $this->entityManager->getRepository(Quiz::class)
            ->createQueryBuilder('q')
            ->where('LOWER(q.titre) LIKE :keyword')
            ->setParameter('keyword', '%' . $normalized . '%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($legacyQuiz instanceof Quiz && !$legacyQuiz->getKeyword()) {
            $legacyQuiz->setKeyword($normalized);
            $this->entityManager->persist($legacyQuiz);
            $this->entityManager->flush();
        }

        return $legacyQuiz;
    }

    /**
     * @return array{quiz: Quiz, created: bool}
     */
    public function ensureQuizForKeyword(string $keyword): array
    {
        $normalized = $this->normalizeKeyword($keyword);
        if ($normalized === '') {
            throw new \InvalidArgumentException('Keyword cannot be empty.');
        }

        $existing = $this->findQuizByKeyword($normalized);
        if ($existing instanceof Quiz) {
            return ['quiz' => $existing, 'created' => false];
        }

        $questionsData = $this->geminiQuizService->generateQuizFromKeyword($normalized);
        if (!is_array($questionsData) || $questionsData === []) {
            throw new \RuntimeException('AI quiz generation returned empty data for keyword: ' . $normalized);
        }

        $quiz = new Quiz();
        $quiz->setKeyword($normalized);
        $quiz->setTitre(ucwords($normalized) . ' Certification Quiz');
        $this->entityManager->persist($quiz);

        foreach ($questionsData as $qData) {
            if (!isset($qData['question'], $qData['options'], $qData['correctAnswer']) || !is_array($qData['options'])) {
                continue;
            }

            $questionText = trim((string) $qData['question']);
            $options = $qData['options'];
            $correctAnswer = (int) $qData['correctAnswer'];

            if ($questionText === '' || count($options) < 2) {
                continue;
            }

            $question = new Question();
            $question->setQuestion($questionText);
            $question->setQuiz($quiz);
            $this->entityManager->persist($question);
            $quiz->addQuestion($question);

            foreach ($options as $index => $optionText) {
                $choice = new Choice();
                $choice->setChoice((string) $optionText);
                $choice->setQuestion($question);
                $choice->setIsCorrect((int) $index === $correctAnswer);
                $this->entityManager->persist($choice);
                $question->addChoice($choice);
            }
        }

        if ($quiz->getQuestions()->count() === 0) {
            throw new \RuntimeException('Could not build quiz questions for keyword: ' . $normalized);
        }

        $this->entityManager->flush();

        return ['quiz' => $quiz, 'created' => true];
    }
}
