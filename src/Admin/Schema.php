<?php

declare(strict_types=1);

namespace App\Admin;

/**
 * Описание сущностей, которые редактируются через админ-API.
 *
 * Типы полей:
 *   string — строка до max символов; text — длинный текст; code — код неисправности (латиница);
 *   bool; date (ГГГГ-ММ-ДД); enum (values); fk — id записи из таблицы table.
 * required — обязательно при создании; поле без required можно передать как null.
 * Справочники производителей, моделей и устройств пока меняются через сиды.
 */
final class Schema
{
    public const TEXT_MAX = 10000;

    public const STEPS_MAX = 50;

    public const ENTITIES = [
        'codes' => [
            'table' => 'fault_codes',
            'label' => 'Код неисправности',
            'order' => 'code',
            'search' => ['code', 'title'],
            'fields' => [
                'code' => ['type' => 'code', 'required' => true],
                'title' => ['type' => 'string', 'max' => 255, 'required' => true],
                'description' => ['type' => 'text'],
                'is_safety_related' => ['type' => 'bool'],
                'source' => ['type' => 'string', 'max' => 255],
                'model_id' => ['type' => 'fk', 'table' => 'models'],
                'controller_id' => ['type' => 'fk', 'table' => 'controllers'],
            ],
            'unique' => [
                'uq_fault_codes_model_code' => 'code',
                'uq_fault_codes_controller_code' => 'code',
            ],
        ],
        'symptoms' => [
            'table' => 'symptoms',
            'label' => 'Симптом',
            'order' => 'title',
            'search' => ['title'],
            'fields' => [
                'title' => ['type' => 'string', 'max' => 255, 'required' => true],
                'description' => ['type' => 'text'],
                'source' => ['type' => 'string', 'max' => 255],
            ],
            'unique' => ['uq_symptoms_title' => 'title'],
        ],
        'causes' => [
            'table' => 'causes',
            'label' => 'Причина',
            'order' => 'title',
            'search' => ['title'],
            'fields' => [
                'title' => ['type' => 'string', 'max' => 255, 'required' => true],
                'description' => ['type' => 'text'],
                'source' => ['type' => 'string', 'max' => 255],
            ],
            'unique' => ['uq_causes_title' => 'title'],
        ],
        'fixes' => [
            'table' => 'fixes',
            'label' => 'Ремонт',
            'order' => 'title',
            'search' => ['title'],
            'fields' => [
                'title' => ['type' => 'string', 'max' => 255, 'required' => true],
                'summary' => ['type' => 'text'],
                'safety_note' => ['type' => 'text'],
                'source' => ['type' => 'string', 'max' => 255],
                'steps' => ['type' => 'steps'],
            ],
            'unique' => ['uq_fixes_title' => 'title'],
        ],
        'cases' => [
            'table' => 'cases',
            'label' => 'Случай',
            'order' => 'occurred_on DESC, id DESC',
            'search' => ['description'],
            'fields' => [
                'occurred_on' => ['type' => 'date', 'required' => true],
                'description' => ['type' => 'text', 'required' => true, 'personal_data' => true],
                'outcome' => ['type' => 'enum', 'values' => ['resolved', 'partial', 'unresolved'], 'required' => true],
                'model_id' => ['type' => 'fk', 'table' => 'models'],
                'fault_code_id' => ['type' => 'fk', 'table' => 'fault_codes'],
                'cause_id' => ['type' => 'fk', 'table' => 'causes'],
                'fix_id' => ['type' => 'fk', 'table' => 'fixes'],
                'notes' => ['type' => 'text', 'personal_data' => true],
                'source' => ['type' => 'string', 'max' => 190],
            ],
            'unique' => ['uq_cases_source' => 'source'],
        ],
    ];

    /**
     * Связи между сущностями: путь /api/admin/{owner}/{id}/{target}/{targetId}.
     * extra — дополнительные поля связи.
     */
    public const LINKS = [
        'codes/causes' => ['table' => 'fault_code_causes', 'owner' => 'fault_code_id', 'target' => 'cause_id'],
        'codes/symptoms' => ['table' => 'fault_code_symptoms', 'owner' => 'fault_code_id', 'target' => 'symptom_id'],
        'symptoms/causes' => [
            'table' => 'symptom_causes', 'owner' => 'symptom_id', 'target' => 'cause_id',
            'extra' => ['likelihood' => ['min' => 1, 'max' => 5, 'default' => 3]],
        ],
        'causes/fixes' => ['table' => 'cause_fixes', 'owner' => 'cause_id', 'target' => 'fix_id'],
    ];
}
