<?php

declare(strict_types=1);

namespace Tests\Admin;

use App\Admin\Schema;
use App\Admin\Validator;
use App\Http\HttpException;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $body
     * @return array<string, string> ошибки по полям
     */
    private function errors(string $entity, array $body, bool $partial = false): array
    {
        try {
            Validator::validate($body, Schema::ENTITIES[$entity]['fields'], $partial);
        } catch (HttpException $e) {
            $this->assertSame(400, $e->status);

            return $e->fields;
        }
        $this->fail('Ожидалась ошибка валидации');
    }

    public function testCreateRequiresFieldsAndRejectsUnknown(): void
    {
        $errors = $this->errors('codes', ['title' => 'Нет кода', 'colour' => 'red']);

        $this->assertArrayHasKey('code', $errors);
        $this->assertArrayHasKey('colour', $errors);
        $this->assertArrayNotHasKey('title', $errors);
    }

    public function testCodeIsNormalizedToLatin(): void
    {
        $clean = Validator::validate(
            ['code' => 'Е36', 'title' => '  Ошибка  ', 'controller_id' => 1, 'is_safety_related' => true],
            Schema::ENTITIES['codes']['fields'],
            partial: false,
        );

        $this->assertSame('E36', $clean['code']);
        $this->assertSame('Ошибка', $clean['title']);
        $this->assertSame(1, $clean['is_safety_related']);
    }

    public function testTypesAreChecked(): void
    {
        $errors = $this->errors('cases', [
            'occurred_on' => '2026-02-30',
            'description' => 'Двери не закрываются',
            'outcome' => 'fixed',
            'fault_code_id' => '5',
        ]);

        $this->assertSame(['occurred_on', 'outcome', 'fault_code_id'], array_keys($errors));
    }

    public function testRequiredFieldCannotBeBlanked(): void
    {
        $errors = $this->errors('symptoms', ['title' => '   '], partial: true);

        $this->assertArrayHasKey('title', $errors);
    }

    public function testOptionalFieldAcceptsNullAndBlankBecomesNull(): void
    {
        $clean = Validator::validate(['description' => '', 'source' => null], Schema::ENTITIES['causes']['fields'], partial: true);

        $this->assertSame(['description' => null, 'source' => null], $clean);
    }

    public function testLengthLimit(): void
    {
        $errors = $this->errors('causes', ['title' => str_repeat('я', 256)]);

        $this->assertArrayHasKey('title', $errors);
    }

    public function testCaseTextWithPersonalDataIsRejected(): void
    {
        $errors = $this->errors('cases', [
            'occurred_on' => '2026-10-01',
            'description' => 'Позвонить по +375 29 123-45-67 после ремонта',
            'outcome' => 'resolved',
            'notes' => 'Работал Иванов И.И.',
        ]);

        $this->assertStringContainsString('телефон', $errors['description']);
        $this->assertStringContainsString('фамилия', $errors['notes']);
    }

    public function testStepsAreValidatedAndTrimmed(): void
    {
        $clean = Validator::validate(
            ['title' => 'Ремонт', 'steps' => [['instruction' => ' Отключить питание '], ['instruction' => 'Проверить', 'expected_result' => 'Норма']]],
            Schema::ENTITIES['fixes']['fields'],
            partial: false,
        );

        $this->assertSame([
            ['instruction' => 'Отключить питание', 'expected_result' => null],
            ['instruction' => 'Проверить', 'expected_result' => 'Норма'],
        ], $clean['steps']);

        $errors = $this->errors('fixes', ['title' => 'Ремонт', 'steps' => [['expected_result' => 'Норма']]]);
        $this->assertStringContainsString('Шаг 1', $errors['steps']);
    }

    public function testEmptyPatchAndListBodyAreRejected(): void
    {
        $this->expectException(HttpException::class);
        Validator::validate([], Schema::ENTITIES['causes']['fields'], partial: true);
    }

    public function testJsonArrayBodyIsRejected(): void
    {
        $this->expectException(HttpException::class);
        Validator::validate(['a', 'b'], Schema::ENTITIES['causes']['fields'], partial: false);
    }
}
