<?php

namespace App\Exceptions;

use RuntimeException;

class QuizNotAvailableException extends RuntimeException
{
    public static function forQuiz(int $quizId): self
    {
        return new self("Quiz [{$quizId}] is not published, or its course/topic is not currently visible.");
    }
}
