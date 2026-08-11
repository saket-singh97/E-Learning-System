<?php

namespace App\Exceptions;

use RuntimeException;

class QuizAttemptLimitExceededException extends RuntimeException
{
    public static function forQuiz(int $quizId, int $maxAttempts): self
    {
        return new self("Quiz [{$quizId}] allows at most {$maxAttempts} attempt(s); none remaining for this student.");
    }
}
