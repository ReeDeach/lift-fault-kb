<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\HttpException;
use App\Support\CodeNormalizer;
use App\Support\PersonalData;
use DateTimeImmutable;

/**
 * Проверяет тело запроса по описанию полей из Schema и приводит значения к виду для базы.
 * Ошибки собираются по всем полям сразу и выбрасываются одним HttpException 400.
 */
final class Validator
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, array<string, mixed>> $fields
     * @param bool $partial true для PATCH: обязательность не проверяется
     * @return array<string, mixed> очищенные значения только переданных полей
     */
    public static function validate(array $body, array $fields, bool $partial): array
    {
        if ($body !== [] && array_is_list($body)) {
            throw new HttpException(400, 'Тело запроса должно быть JSON-объектом');
        }

        $clean = [];
        $errors = [];

        foreach (array_keys($body) as $name) {
            if (!isset($fields[$name])) {
                $errors[(string) $name] = 'Неизвестное поле';
            }
        }

        foreach ($fields as $name => $spec) {
            if (!array_key_exists($name, $body)) {
                if (!$partial && ($spec['required'] ?? false)) {
                    $errors[$name] = 'Обязательное поле';
                }
                continue;
            }

            [$value, $error] = self::field($body[$name], $spec);
            if ($error !== null) {
                $errors[$name] = $error;
                continue;
            }
            if ($value === null && ($spec['required'] ?? false)) {
                $errors[$name] = 'Обязательное поле';
                continue;
            }
            $clean[$name] = $value;
        }

        if ($partial && $body === []) {
            throw new HttpException(400, 'Нет полей для изменения');
        }
        if ($errors !== []) {
            throw new HttpException(400, 'Ошибка в данных', $errors);
        }

        return $clean;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array{mixed, string|null} значение и текст ошибки
     */
    private static function field(mixed $value, array $spec): array
    {
        if ($value === null) {
            return [null, null];
        }

        switch ($spec['type']) {
            case 'string':
            case 'text':
                if (!is_string($value)) {
                    return [null, 'Ожидается строка'];
                }
                $value = trim($value);
                if ($value === '') {
                    return [null, null];
                }
                $max = $spec['max'] ?? Schema::TEXT_MAX;
                if (mb_strlen($value) > $max) {
                    return [null, "Не длиннее $max символов"];
                }
                if (($spec['personal_data'] ?? false) && ($found = PersonalData::find($value)) !== null) {
                    return [null, "Похоже на персональные данные ($found). Оставьте только техническое описание"];
                }

                return [$value, null];

            case 'code':
                if (!is_string($value) && !is_int($value)) {
                    return [null, 'Ожидается строка'];
                }
                $value = CodeNormalizer::toLatin((string) $value);
                if (!CodeNormalizer::isValidCode($value)) {
                    return [null, 'Код: до 32 символов, буквы, цифры, точка, дефис и подчёркивание'];
                }

                return [$value, null];

            case 'bool':
                return is_bool($value) ? [$value ? 1 : 0, null] : [null, 'Ожидается true или false'];

            case 'fk':
                return is_int($value) && $value > 0 ? [$value, null] : [null, 'Ожидается id записи (целое число)'];

            case 'enum':
                return is_string($value) && in_array($value, $spec['values'], true)
                    ? [$value, null]
                    : [null, 'Допустимые значения: ' . implode(', ', $spec['values'])];

            case 'date':
                $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

                return $date !== false && $date->format('Y-m-d') === $value
                    ? [$value, null]
                    : [null, 'Дата в формате ГГГГ-ММ-ДД'];

            case 'steps':
                return self::steps($value);
        }

        return [null, 'Неизвестный тип поля'];
    }

    /**
     * Шаги ремонта: список объектов {instruction, expected_result}. Номера ставятся по порядку.
     *
     * @return array{mixed, string|null}
     */
    private static function steps(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [null, 'Ожидается список шагов'];
        }
        if (count($value) > Schema::STEPS_MAX) {
            return [null, 'Не больше ' . Schema::STEPS_MAX . ' шагов'];
        }

        $steps = [];
        foreach ($value as $i => $step) {
            $n = $i + 1;
            if (!is_array($step)) {
                return [null, "Шаг $n: ожидается объект"];
            }
            $unknown = array_diff(array_keys($step), ['instruction', 'expected_result']);
            if ($unknown !== []) {
                return [null, "Шаг $n: неизвестное поле " . implode(', ', $unknown)];
            }
            $instruction = $step['instruction'] ?? null;
            if (!is_string($instruction) || trim($instruction) === '') {
                return [null, "Шаг $n: нужна инструкция"];
            }
            $expected = $step['expected_result'] ?? null;
            if ($expected !== null && !is_string($expected)) {
                return [null, "Шаг $n: ожидаемый результат должен быть строкой"];
            }
            $expected = $expected === null || trim($expected) === '' ? null : trim($expected);
            if (mb_strlen($instruction) > Schema::TEXT_MAX || mb_strlen((string) $expected) > Schema::TEXT_MAX) {
                return [null, "Шаг $n: не длиннее " . Schema::TEXT_MAX . ' символов'];
            }
            $steps[] = ['instruction' => trim($instruction), 'expected_result' => $expected];
        }

        return [$steps, null];
    }
}
