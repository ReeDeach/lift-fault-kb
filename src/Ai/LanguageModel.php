<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Языковая модель, которая отвечает JSON-объектом по заданной схеме.
 */
interface LanguageModel
{
    /**
     * @param string $system инструкция модели
     * @param string $prompt данные запроса
     * @param array<string, mixed> $schema схема ответа (подмножество OpenAPI, как в Gemini responseSchema)
     * @return array{data: array<string, mixed>, model: string} разобранный ответ и модель, которая ответила
     * @throws AiException модель недоступна или ответ не разобрать
     */
    public function generate(string $system, string $prompt, array $schema): array;
}
