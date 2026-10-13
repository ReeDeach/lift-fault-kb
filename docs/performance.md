# Замеры запросов: до и после индексов

Отчёт создан командой `php bin/bench.php --write` на синтетических данных (MySQL 8.0.46).
Миграция с индексами: `007_indexes.sql`.

## Объём данных

| Таблица | Строк |
|---|---:|
| `manufacturers` | 20 |
| `models` | 2 000 |
| `controllers` | 400 |
| `model_controllers` | 4 000 |
| `fault_codes` | 60 000 |
| `symptoms` | 3 000 |
| `causes` | 8 000 |
| `fixes` | 4 000 |
| `fix_steps` | 16 000 |
| `fault_code_symptoms` | 120 000 |
| `fault_code_causes` | 120 000 |
| `symptom_causes` | 12 000 |
| `cause_fixes` | 16 000 |
| `cases` | 50 000 |

## Время (медиана)

| Сценарий | До, мс | После, мс | Ускорение |
|---|---:|---:|---:|
| GET /api/codes?code=45 — код во всех устройствах | 25.0 | 2.4 | ×10.3 |
| GET /api/codes?model=1000К&code=45 — код в модели | 2.5 | 3.1 | ×0.8 |
| GET /api/codes?model=1000К — первая страница кодов модели | 3.5 | 3.5 | ×1.0 |
| GET /api/codes?model=1000К&expand=tree — дерево для 20 кодов | 12.6 | 12.9 | ×1.0 |
| GET /api/models?manufacturer=maker-5 — модели производителя | 3.3 | 3.2 | ×1.0 |
| GET /api/models — все модели | 35.8 | 35.9 | ×1.0 |

## Планы запросов

Колонки EXPLAIN: `type` — способ доступа (`ALL` — полный просмотр таблицы), `key` — использованный индекс, `rows` — сколько строк MySQL ожидает прочитать.

### GET /api/codes?code=45 — код во всех устройствах

**До**

```sql
SELECT COUNT(*) FROM fault_codes fc WHERE fc.code = ?
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | index | uq_fault_codes_model_code | 57737 | 10 | Using where; Using index |

```sql
SELECT fc.id, fc.code, fc.title, fc.description, fc.is_safety_related, fc.source, fc.model_id,
fc.controller_id, k.name AS controller_name, k.kind AS controller_kind, mo.name AS model_name FROM fault_codes
fc LEFT JOIN controllers k ON k.id = fc.controller_id LEFT JOIN models mo ON mo.id = fc.model_id WHERE fc.code
= ? ORDER BY k.name, mo.name, fc.code LIMIT 20 OFFSET 0
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | ALL |  | 57737 | 10 | Using where; Using temporary; Using filesort |
| k | eq_ref | PRIMARY | 1 | 100 |  |
| mo | eq_ref | PRIMARY | 1 | 100 |  |

**После**

```sql
SELECT COUNT(*) FROM fault_codes fc WHERE fc.code = ?
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | ref | idx_fault_codes_code | 400 | 100 | Using index |

```sql
SELECT fc.id, fc.code, fc.title, fc.description, fc.is_safety_related, fc.source, fc.model_id,
fc.controller_id, k.name AS controller_name, k.kind AS controller_kind, mo.name AS model_name FROM fault_codes
fc LEFT JOIN controllers k ON k.id = fc.controller_id LEFT JOIN models mo ON mo.id = fc.model_id WHERE fc.code
= ? ORDER BY k.name, mo.name, fc.code LIMIT 20 OFFSET 0
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | ref | idx_fault_codes_code | 400 | 100 | Using temporary; Using filesort |
| k | eq_ref | PRIMARY | 1 | 100 |  |
| mo | eq_ref | PRIMARY | 1 | 100 |  |

### GET /api/codes?model=1000К&code=45 — код в модели

**До**

```sql
SELECT id, name FROM models
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| models | index | uq_models_manufacturer_name | 2000 | 100 | Using index |

```sql
SELECT DISTINCT controller_id FROM model_controllers WHERE model_id IN (1000)
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| model_controllers | ref | PRIMARY | 2 | 100 | Using index |

```sql
SELECT COUNT(*) FROM fault_codes fc WHERE (fc.model_id IN (1000) OR fc.controller_id IN (1,394)) AND fc.code =
?
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | index_merge | uq_fault_codes_model_code,uq_fault_codes_controller_code | 210 | 10 | Using sort_union(uq_fault_codes_model_code,uq_fault_codes_controller_code); Using where |

```sql
SELECT fc.id, fc.code, fc.title, fc.description, fc.is_safety_related, fc.source, fc.model_id,
fc.controller_id, k.name AS controller_name, k.kind AS controller_kind, mo.name AS model_name FROM fault_codes
fc LEFT JOIN controllers k ON k.id = fc.controller_id LEFT JOIN models mo ON mo.id = fc.model_id WHERE
(fc.model_id IN (1000) OR fc.controller_id IN (1,394)) AND fc.code = ? ORDER BY k.name, mo.name, fc.code LIMIT
20 OFFSET 0
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | index_merge | uq_fault_codes_model_code,uq_fault_codes_controller_code | 210 | 10 | Using sort_union(uq_fault_codes_model_code,uq_fault_codes_controller_code); Using where; Using temporary; Using filesort |
| k | eq_ref | PRIMARY | 1 | 100 |  |
| mo | eq_ref | PRIMARY | 1 | 100 |  |

**После**

```sql
SELECT id, name FROM models
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| models | index | uq_models_manufacturer_name | 2000 | 100 | Using index |

```sql
SELECT DISTINCT controller_id FROM model_controllers WHERE model_id IN (1000)
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| model_controllers | ref | PRIMARY | 2 | 100 | Using index |

```sql
SELECT COUNT(*) FROM fault_codes fc WHERE (fc.model_id IN (1000) OR fc.controller_id IN (1,394)) AND fc.code =
?
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | ref | idx_fault_codes_code | 400 | 20.039682388306 | Using where |

```sql
SELECT fc.id, fc.code, fc.title, fc.description, fc.is_safety_related, fc.source, fc.model_id,
fc.controller_id, k.name AS controller_name, k.kind AS controller_kind, mo.name AS model_name FROM fault_codes
fc LEFT JOIN controllers k ON k.id = fc.controller_id LEFT JOIN models mo ON mo.id = fc.model_id WHERE
(fc.model_id IN (1000) OR fc.controller_id IN (1,394)) AND fc.code = ? ORDER BY k.name, mo.name, fc.code LIMIT
20 OFFSET 0
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | ref | idx_fault_codes_code | 400 | 20.039682388306 | Using where; Using temporary; Using filesort |
| k | eq_ref | PRIMARY | 1 | 100 |  |
| mo | eq_ref | PRIMARY | 1 | 100 |  |

### GET /api/codes?model=1000К — первая страница кодов модели

**До**

```sql
SELECT id, name FROM models
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| models | index | uq_models_manufacturer_name | 2000 | 100 | Using index |

```sql
SELECT DISTINCT controller_id FROM model_controllers WHERE model_id IN (1000)
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| model_controllers | ref | PRIMARY | 2 | 100 | Using index |

```sql
SELECT COUNT(*) FROM fault_codes fc WHERE (fc.model_id IN (1000) OR fc.controller_id IN (1,394))
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | index_merge | uq_fault_codes_model_code,uq_fault_codes_controller_code | 210 | 100 | Using sort_union(uq_fault_codes_model_code,uq_fault_codes_controller_code); Using where |

```sql
SELECT fc.id, fc.code, fc.title, fc.description, fc.is_safety_related, fc.source, fc.model_id,
fc.controller_id, k.name AS controller_name, k.kind AS controller_kind, mo.name AS model_name FROM fault_codes
fc LEFT JOIN controllers k ON k.id = fc.controller_id LEFT JOIN models mo ON mo.id = fc.model_id WHERE
(fc.model_id IN (1000) OR fc.controller_id IN (1,394)) ORDER BY k.name, mo.name, fc.code LIMIT 20 OFFSET 0
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | index_merge | uq_fault_codes_model_code,uq_fault_codes_controller_code | 210 | 100 | Using sort_union(uq_fault_codes_model_code,uq_fault_codes_controller_code); Using where; Using temporary; Using filesort |
| k | eq_ref | PRIMARY | 1 | 100 |  |
| mo | eq_ref | PRIMARY | 1 | 100 |  |

**После**

```sql
SELECT id, name FROM models
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| models | index | uq_models_manufacturer_name | 2000 | 100 | Using index |

```sql
SELECT DISTINCT controller_id FROM model_controllers WHERE model_id IN (1000)
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| model_controllers | ref | PRIMARY | 2 | 100 | Using index |

```sql
SELECT COUNT(*) FROM fault_codes fc WHERE (fc.model_id IN (1000) OR fc.controller_id IN (1,394))
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | index_merge | uq_fault_codes_model_code,uq_fault_codes_controller_code | 210 | 100 | Using sort_union(uq_fault_codes_model_code,uq_fault_codes_controller_code); Using where |

```sql
SELECT fc.id, fc.code, fc.title, fc.description, fc.is_safety_related, fc.source, fc.model_id,
fc.controller_id, k.name AS controller_name, k.kind AS controller_kind, mo.name AS model_name FROM fault_codes
fc LEFT JOIN controllers k ON k.id = fc.controller_id LEFT JOIN models mo ON mo.id = fc.model_id WHERE
(fc.model_id IN (1000) OR fc.controller_id IN (1,394)) ORDER BY k.name, mo.name, fc.code LIMIT 20 OFFSET 0
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | index_merge | uq_fault_codes_model_code,uq_fault_codes_controller_code | 210 | 100 | Using sort_union(uq_fault_codes_model_code,uq_fault_codes_controller_code); Using where; Using temporary; Using filesort |
| k | eq_ref | PRIMARY | 1 | 100 |  |
| mo | eq_ref | PRIMARY | 1 | 100 |  |

### GET /api/codes?model=1000К&expand=tree — дерево для 20 кодов

**До**

```sql
SELECT id, name FROM models
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| models | index | uq_models_manufacturer_name | 2000 | 100 | Using index |

```sql
SELECT DISTINCT controller_id FROM model_controllers WHERE model_id IN (1000)
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| model_controllers | ref | PRIMARY | 2 | 100 | Using index |

```sql
SELECT COUNT(*) FROM fault_codes fc WHERE (fc.model_id IN (1000) OR fc.controller_id IN (1,394))
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | index_merge | uq_fault_codes_model_code,uq_fault_codes_controller_code | 210 | 100 | Using sort_union(uq_fault_codes_model_code,uq_fault_codes_controller_code); Using where |

```sql
SELECT fc.id, fc.code, fc.title, fc.description, fc.is_safety_related, fc.source, fc.model_id,
fc.controller_id, k.name AS controller_name, k.kind AS controller_kind, mo.name AS model_name FROM fault_codes
fc LEFT JOIN controllers k ON k.id = fc.controller_id LEFT JOIN models mo ON mo.id = fc.model_id WHERE
(fc.model_id IN (1000) OR fc.controller_id IN (1,394)) ORDER BY k.name, mo.name, fc.code LIMIT 20 OFFSET 0
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | index_merge | uq_fault_codes_model_code,uq_fault_codes_controller_code | 210 | 100 | Using sort_union(uq_fault_codes_model_code,uq_fault_codes_controller_code); Using where; Using temporary; Using filesort |
| k | eq_ref | PRIMARY | 1 | 100 |  |
| mo | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT fcs.fault_code_id, s.id, s.title FROM fault_code_symptoms fcs JOIN symptoms s ON s.id = fcs.symptom_id
WHERE fcs.fault_code_id IN
(49991,49992,49993,49994,49995,49996,49997,49998,49999,50000,1,10,100,11,12,13,14,15,16,17) ORDER BY s.title
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fcs | range | PRIMARY | 40 | 100 | Using where; Using index; Using temporary; Using filesort |
| s | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT fcc.fault_code_id, c.id, c.title, c.source FROM fault_code_causes fcc JOIN causes c ON c.id =
fcc.cause_id WHERE fcc.fault_code_id IN
(49991,49992,49993,49994,49995,49996,49997,49998,49999,50000,1,10,100,11,12,13,14,15,16,17)
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fcc | range | PRIMARY | 40 | 100 | Using where; Using index |
| c | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT fcs.fault_code_id, c.id, c.title, c.source, sc.likelihood FROM fault_code_symptoms fcs JOIN
symptom_causes sc ON sc.symptom_id = fcs.symptom_id JOIN causes c ON c.id = sc.cause_id WHERE
fcs.fault_code_id IN
(49991,49992,49993,49994,49995,49996,49997,49998,49999,50000,1,10,100,11,12,13,14,15,16,17)
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fcs | range | PRIMARY | 40 | 100 | Using where; Using index |
| sc | ref | PRIMARY | 4 | 100 |  |
| c | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT cf.cause_id, f.id, f.title, f.summary, f.safety_note FROM cause_fixes cf JOIN fixes f ON f.id =
cf.fix_id WHERE cf.cause_id IN
(18,35,3318,3349,1675,3287,1706,1644,3256,1737,341,324,303,6753,334,6691,365,6660,272,6722,375,358,3496,3589,3527,3558,1977,1946,1915,1884,409,392,6782,5108,6813,5139,5170,6720,5201,6751,426,443,2037,2006,394,1975,363,332,425,1944,460,477,5199,5168,5230,3618,5261,3556,3649,3587,494,511,6780,6811,6842,6873,423,485,454,528,545,2097,3678,3709,3647,2004,2066,2035,3616,579,562,5321,6933,6840,6902,5290,5259,6871,5228,3401,3384,2463,2525,2432,820,851,2494,913,882,3695,5435,7109,5466,7016,7047,5404,5497,7078,3712,3729,721,2240,628,2333,2271,690,659,2302,3763,3746,3852,5526,5557,5495,3945,3914,5464,3883,3797,3780,781,7169,719,7107,750,7076,688,7138,3831,3814,2300,3912,2331,3943,4005,2393,2362,3974,3865,3848,5555,7198,5524,5586,7167,7136,7229,5617,3899,3882,748,2453,779,2391,2422,810,2360,841,3933,3916,5584,3972,4003,5677,5646,4065,5615,4034,3967,3950,808,7196,7258,901,7227,7289,870,839,4001,3984,2513,2482,2420,4125,2451,4032,4063,4094)
ORDER BY f.title
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| cf | range | PRIMARY | 396 | 100 | Using where; Using index; Using temporary; Using filesort |
| f | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT fix_id, step_no, instruction, expected_result FROM fix_steps WHERE fix_id IN
(2499,2901,2660,3575,2120,3743,1768,1887,3717,3819,1205,2594,1533,2823,1779,3287,1963,2527,2858,3232,705,1416,3622,771,1730,1442,734,1241,851,1161,2904,1562,3163,3564,3061,3418,1172,2142,2065,3909,253,3699,2878,2459,2402,669,446,3898,158,1989,224,2255,2448,880,2747,1362,3830,1274,891,3673,304,2995,1876,2423,3845,2959,3374,3954,1387,3812,895,2514,209,3617,917,815,1026,516,3874,2244,2791,3921,1405,1697,2751,2124,2215,1573,614,3035,1505,2438,3017,1431,76,2222,2211,2890,3243,3316,1974,527,3276,988,3823,2484,3866,1449,997,2110,3137,2449,3492,87,397,1664,3440,3980,366,3706,1307,2233,3649,3535,2353,2948,268,2525,2769,213,2204,3394,2342,2780,2131,1679,3991,3551,365,2758,457,3553,658,2121,1747,198,3028,1285,1052,3196,1588,2481,3006,3841,884,1291,3976,1817,348,399,1351,1777,2379,31,982,993,3152,646,3150,2882,20,56,2656,3808,23,3349,1923,1806,2346,2645,3170,2849,3586,3965,2503,1230,1686,1394,3264,67,935,2601,2869,1427,1675,657,2302,354,1037,251,745,3272,2277,1766,3520,355,3024,1522,1020,1912,3283,3987,1952,2153,2368,3495,386,1595,728,3677,749,999,1741,2889,377,344,924,2135,739,1281,2667,2813,2488,242,1280,2069,2736,592,1843,1438,2098,3126,1420,3834,647,523,1270,1668,3863,2058,3294,1453,1128,3662,1376,1063,272,2176,2313,3203,3540,381,3429,2413,2109,2028,3130,202,2984,283,3606,3666,3275,370,906,2054,1810,1967,3852,567,556,78,279,3503,3161,3910,235,1654,2802,2590,501,3597,2712,2495,2776,169,1494,1373,2838,1832,2030,3385,3181,2915,1736,111,3141,89,3039,388,3405,3688,1599,738,804,240,3451,3524,2516,913,2041,12,1708,2634,1529,315,636,3638,1985,3050,840,490,2521,3207,1048,3560,2893,3877,2187,3531,133,2470,3192,625,359,3484,100,3129,1059,3118,3338,1584,694,1139,3633,3571,3732,2492,2266,3542,1340,1031,1821,2740,1665,1978,2357,1296,2723,603,2412,2510,760,1719,1194,1183,122,3885,1150,512,2671,3327,1518,2812,986,2605,2200,2787,902,2039,3305,1956)
ORDER BY fix_id, step_no
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fix_steps | range | uq_fix_steps_fix_step | 1176 | 100 | Using index condition |

```sql
SELECT id, fault_code_id, occurred_on, description, outcome, notes FROM cases WHERE fault_code_id IN
(49991,49992,49993,49994,49995,49996,49997,49998,49999,50000,1,10,100,11,12,13,14,15,16,17) ORDER BY
occurred_on DESC, id DESC
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| cases | range | fk_cases_fault_code | 20 | 100 | Using index condition; Using filesort |

**После**

```sql
SELECT id, name FROM models
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| models | index | uq_models_manufacturer_name | 2000 | 100 | Using index |

```sql
SELECT DISTINCT controller_id FROM model_controllers WHERE model_id IN (1000)
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| model_controllers | ref | PRIMARY | 2 | 100 | Using index |

```sql
SELECT COUNT(*) FROM fault_codes fc WHERE (fc.model_id IN (1000) OR fc.controller_id IN (1,394))
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | index_merge | uq_fault_codes_model_code,uq_fault_codes_controller_code | 210 | 100 | Using sort_union(uq_fault_codes_model_code,uq_fault_codes_controller_code); Using where |

```sql
SELECT fc.id, fc.code, fc.title, fc.description, fc.is_safety_related, fc.source, fc.model_id,
fc.controller_id, k.name AS controller_name, k.kind AS controller_kind, mo.name AS model_name FROM fault_codes
fc LEFT JOIN controllers k ON k.id = fc.controller_id LEFT JOIN models mo ON mo.id = fc.model_id WHERE
(fc.model_id IN (1000) OR fc.controller_id IN (1,394)) ORDER BY k.name, mo.name, fc.code LIMIT 20 OFFSET 0
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fc | index_merge | uq_fault_codes_model_code,uq_fault_codes_controller_code | 210 | 100 | Using sort_union(uq_fault_codes_model_code,uq_fault_codes_controller_code); Using where; Using temporary; Using filesort |
| k | eq_ref | PRIMARY | 1 | 100 |  |
| mo | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT fcs.fault_code_id, s.id, s.title FROM fault_code_symptoms fcs JOIN symptoms s ON s.id = fcs.symptom_id
WHERE fcs.fault_code_id IN
(49991,49992,49993,49994,49995,49996,49997,49998,49999,50000,1,10,100,11,12,13,14,15,16,17) ORDER BY s.title
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fcs | range | PRIMARY | 40 | 100 | Using where; Using index; Using temporary; Using filesort |
| s | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT fcc.fault_code_id, c.id, c.title, c.source FROM fault_code_causes fcc JOIN causes c ON c.id =
fcc.cause_id WHERE fcc.fault_code_id IN
(49991,49992,49993,49994,49995,49996,49997,49998,49999,50000,1,10,100,11,12,13,14,15,16,17)
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fcc | range | PRIMARY | 40 | 100 | Using where; Using index |
| c | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT fcs.fault_code_id, c.id, c.title, c.source, sc.likelihood FROM fault_code_symptoms fcs JOIN
symptom_causes sc ON sc.symptom_id = fcs.symptom_id JOIN causes c ON c.id = sc.cause_id WHERE
fcs.fault_code_id IN
(49991,49992,49993,49994,49995,49996,49997,49998,49999,50000,1,10,100,11,12,13,14,15,16,17)
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fcs | range | PRIMARY | 40 | 100 | Using where; Using index |
| sc | ref | PRIMARY | 4 | 100 |  |
| c | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT cf.cause_id, f.id, f.title, f.summary, f.safety_note FROM cause_fixes cf JOIN fixes f ON f.id =
cf.fix_id WHERE cf.cause_id IN
(18,35,3318,3349,1675,3287,1706,1644,3256,1737,341,324,303,6753,334,6691,365,6660,272,6722,375,358,3496,3589,3527,3558,1977,1946,1915,1884,409,392,6782,5108,6813,5139,5170,6720,5201,6751,426,443,2037,2006,394,1975,363,332,425,1944,460,477,5199,5168,5230,3618,5261,3556,3649,3587,494,511,6780,6811,6842,6873,423,485,454,528,545,2097,3678,3709,3647,2004,2066,2035,3616,579,562,5321,6933,6840,6902,5290,5259,6871,5228,3401,3384,2463,2525,2432,820,851,2494,913,882,3695,5435,7109,5466,7016,7047,5404,5497,7078,3712,3729,721,2240,628,2333,2271,690,659,2302,3763,3746,3852,5526,5557,5495,3945,3914,5464,3883,3797,3780,781,7169,719,7107,750,7076,688,7138,3831,3814,2300,3912,2331,3943,4005,2393,2362,3974,3865,3848,5555,7198,5524,5586,7167,7136,7229,5617,3899,3882,748,2453,779,2391,2422,810,2360,841,3933,3916,5584,3972,4003,5677,5646,4065,5615,4034,3967,3950,808,7196,7258,901,7227,7289,870,839,4001,3984,2513,2482,2420,4125,2451,4032,4063,4094)
ORDER BY f.title
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| cf | range | PRIMARY | 396 | 100 | Using where; Using index; Using temporary; Using filesort |
| f | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT fix_id, step_no, instruction, expected_result FROM fix_steps WHERE fix_id IN
(2499,2901,2660,3575,2120,3743,1768,1887,3717,3819,1205,2594,1533,2823,1779,3287,1963,2527,2858,3232,705,1416,3622,771,1730,1442,734,1241,851,1161,2904,1562,3163,3564,3061,3418,1172,2142,2065,3909,253,3699,2878,2459,2402,669,446,3898,158,1989,224,2255,2448,880,2747,1362,3830,1274,891,3673,304,2995,1876,2423,3845,2959,3374,3954,1387,3812,895,2514,209,3617,917,815,1026,516,3874,2244,2791,3921,1405,1697,2751,2124,2215,1573,614,3035,1505,2438,3017,1431,76,2222,2211,2890,3243,3316,1974,527,3276,988,3823,2484,3866,1449,997,2110,3137,2449,3492,87,397,1664,3440,3980,366,3706,1307,2233,3649,3535,2353,2948,268,2525,2769,213,2204,3394,2342,2780,2131,1679,3991,3551,365,2758,457,3553,658,2121,1747,198,3028,1285,1052,3196,1588,2481,3006,3841,884,1291,3976,1817,348,399,1351,1777,2379,31,982,993,3152,646,3150,2882,20,56,2656,3808,23,3349,1923,1806,2346,2645,3170,2849,3586,3965,2503,1230,1686,1394,3264,67,935,2601,2869,1427,1675,657,2302,354,1037,251,745,3272,2277,1766,3520,355,3024,1522,1020,1912,3283,3987,1952,2153,2368,3495,386,1595,728,3677,749,999,1741,2889,377,344,924,2135,739,1281,2667,2813,2488,242,1280,2069,2736,592,1843,1438,2098,3126,1420,3834,647,523,1270,1668,3863,2058,3294,1453,1128,3662,1376,1063,272,2176,2313,3203,3540,381,3429,2413,2109,2028,3130,202,2984,283,3606,3666,3275,370,906,2054,1810,1967,3852,567,556,78,279,3503,3161,3910,235,1654,2802,2590,501,3597,2712,2495,2776,169,1494,1373,2838,1832,2030,3385,3181,2915,1736,111,3141,89,3039,388,3405,3688,1599,738,804,240,3451,3524,2516,913,2041,12,1708,2634,1529,315,636,3638,1985,3050,840,490,2521,3207,1048,3560,2893,3877,2187,3531,133,2470,3192,625,359,3484,100,3129,1059,3118,3338,1584,694,1139,3633,3571,3732,2492,2266,3542,1340,1031,1821,2740,1665,1978,2357,1296,2723,603,2412,2510,760,1719,1194,1183,122,3885,1150,512,2671,3327,1518,2812,986,2605,2200,2787,902,2039,3305,1956)
ORDER BY fix_id, step_no
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fix_steps | range | uq_fix_steps_fix_step | 1176 | 100 | Using index condition |

```sql
SELECT id, fault_code_id, occurred_on, description, outcome, notes FROM cases WHERE fault_code_id IN
(49991,49992,49993,49994,49995,49996,49997,49998,49999,50000,1,10,100,11,12,13,14,15,16,17) ORDER BY
occurred_on DESC, id DESC
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| cases | range | fk_cases_fault_code | 20 | 100 | Using index condition; Using filesort |

### GET /api/models?manufacturer=maker-5 — модели производителя

**До**

```sql
SELECT id FROM manufacturers WHERE slug = ?
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| manufacturers | const | uq_manufacturers_slug | 1 | 100 | Using index |

```sql
SELECT mo.id, mo.name, mo.notes, m.slug AS manufacturer_slug, m.name AS manufacturer_name FROM models mo JOIN
manufacturers m ON m.id = mo.manufacturer_id WHERE m.slug = ? ORDER BY m.name, mo.name
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| m | const | uq_manufacturers_slug | 1 | 100 |  |
| mo | ref | uq_models_manufacturer_name | 100 | 100 | Using where |

```sql
SELECT mc.model_id, k.id, k.name, k.kind FROM model_controllers mc JOIN controllers k ON k.id =
mc.controller_id WHERE mc.model_id IN
(4,24,44,64,84,104,124,144,164,184,204,224,244,264,284,304,324,344,364,384,404,424,444,464,484,504,524,544,564,584,604,624,644,664,684,704,724,744,764,784,804,824,844,864,884,904,924,944,964,984,1004,1024,1044,1064,1084,1104,1124,1144,1164,1184,1204,1224,1244,1264,1284,1304,1324,1344,1364,1384,1404,1424,1444,1464,1484,1504,1524,1544,1564,1584,1604,1624,1644,1664,1684,1704,1724,1744,1764,1784,1804,1824,1844,1864,1884,1904,1924,1944,1964,1984)
ORDER BY k.kind, k.name
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| mc | range | PRIMARY | 200 | 100 | Using where; Using index; Using temporary; Using filesort |
| k | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT model_id, COUNT(*) FROM fault_codes WHERE model_id IN
(4,24,44,64,84,104,124,144,164,184,204,224,244,264,284,304,324,344,364,384,404,424,444,464,484,504,524,544,564,584,604,624,644,664,684,704,724,744,764,784,804,824,844,864,884,904,924,944,964,984,1004,1024,1044,1064,1084,1104,1124,1144,1164,1184,1204,1224,1244,1264,1284,1304,1324,1344,1364,1384,1404,1424,1444,1464,1484,1504,1524,1544,1564,1584,1604,1624,1644,1664,1684,1704,1724,1744,1764,1784,1804,1824,1844,1864,1884,1904,1924,1944,1964,1984)
GROUP BY model_id
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fault_codes | range | uq_fault_codes_model_code | 1000 | 100 | Using where; Using index |

```sql
SELECT controller_id, COUNT(*) FROM fault_codes WHERE controller_id IN
(10,17,130,137,170,177,210,217,250,257,290,297,330,337,370,377,50,57,90,97) GROUP BY controller_id
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fault_codes | range | uq_fault_codes_controller_code | 2000 | 100 | Using where; Using index |

**После**

```sql
SELECT id FROM manufacturers WHERE slug = ?
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| manufacturers | const | uq_manufacturers_slug | 1 | 100 | Using index |

```sql
SELECT mo.id, mo.name, mo.notes, m.slug AS manufacturer_slug, m.name AS manufacturer_name FROM models mo JOIN
manufacturers m ON m.id = mo.manufacturer_id WHERE m.slug = ? ORDER BY m.name, mo.name
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| m | const | uq_manufacturers_slug | 1 | 100 |  |
| mo | ref | uq_models_manufacturer_name | 100 | 100 | Using where |

```sql
SELECT mc.model_id, k.id, k.name, k.kind FROM model_controllers mc JOIN controllers k ON k.id =
mc.controller_id WHERE mc.model_id IN
(4,24,44,64,84,104,124,144,164,184,204,224,244,264,284,304,324,344,364,384,404,424,444,464,484,504,524,544,564,584,604,624,644,664,684,704,724,744,764,784,804,824,844,864,884,904,924,944,964,984,1004,1024,1044,1064,1084,1104,1124,1144,1164,1184,1204,1224,1244,1264,1284,1304,1324,1344,1364,1384,1404,1424,1444,1464,1484,1504,1524,1544,1564,1584,1604,1624,1644,1664,1684,1704,1724,1744,1764,1784,1804,1824,1844,1864,1884,1904,1924,1944,1964,1984)
ORDER BY k.kind, k.name
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| mc | range | PRIMARY | 200 | 100 | Using where; Using index; Using temporary; Using filesort |
| k | eq_ref | PRIMARY | 1 | 100 |  |

```sql
SELECT model_id, COUNT(*) FROM fault_codes WHERE model_id IN
(4,24,44,64,84,104,124,144,164,184,204,224,244,264,284,304,324,344,364,384,404,424,444,464,484,504,524,544,564,584,604,624,644,664,684,704,724,744,764,784,804,824,844,864,884,904,924,944,964,984,1004,1024,1044,1064,1084,1104,1124,1144,1164,1184,1204,1224,1244,1264,1284,1304,1324,1344,1364,1384,1404,1424,1444,1464,1484,1504,1524,1544,1564,1584,1604,1624,1644,1664,1684,1704,1724,1744,1764,1784,1804,1824,1844,1864,1884,1904,1924,1944,1964,1984)
GROUP BY model_id
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fault_codes | range | uq_fault_codes_model_code | 1000 | 100 | Using where; Using index |

```sql
SELECT controller_id, COUNT(*) FROM fault_codes WHERE controller_id IN
(10,17,130,137,170,177,210,217,250,257,290,297,330,337,370,377,50,57,90,97) GROUP BY controller_id
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fault_codes | range | uq_fault_codes_controller_code | 2000 | 100 | Using where; Using index |

### GET /api/models — все модели

**До**

```sql
SELECT mo.id, mo.name, mo.notes, m.slug AS manufacturer_slug, m.name AS manufacturer_name FROM models mo JOIN
manufacturers m ON m.id = mo.manufacturer_id ORDER BY m.name, mo.name
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| m | ALL |  | 20 | 100 | Using temporary; Using filesort |
| mo | ref | uq_models_manufacturer_name | 100 | 100 |  |

```sql
SELECT mc.model_id, k.id, k.name, k.kind FROM model_controllers mc JOIN controllers k ON k.id =
mc.controller_id WHERE mc.model_id IN
(20,40,60,80,100,120,140,160,180,200,220,240,260,280,300,320,340,360,380,400,420,440,460,480,500,520,540,560,580,600,620,640,660,680,700,720,740,760,780,800,820,840,860,880,900,920,940,960,980,1000,1020,1040,1060,1080,1100,1120,1140,1160,1180,1200,1220,1240,1260,1280,1300,1320,1340,1360,1380,1400,1420,1440,1460,1480,1500,1520,1540,1560,1580,1600,1620,1640,1660,1680,1700,1720,1740,1760,1780,1800,1820,1840,1860,1880,1900,1920,1940,1960,1980,2000,9,29,49,69,89,109,129,149,169,189,209,229,249,269,289,309,329,349,369,389,409,429,449,469,489,509,529,549,569,589,609,629,649,669,689,709,729,749,769,789,809,829,849,869,889,909,929,949,969,989,1009,1029,1049,1069,1089,1109,1129,1149,1169,1189,1209,1229,1249,1269,1289,1309,1329,1349,1369,1389,1409,1429,1449,1469,1489,1509,1529,1549,1569,1589,1609,1629,1649,1669,1689,1709,1729,1749,1769,1789,1809,1829,1849,1869,1889,1909,1929,1949,1969,1989,10,30,50,70,90,110,130,150,170,190,210,230,250,270,290,310,330,350,370,390,410,430,450,470,490,510,530,550,570,590,610,630,650,670,690,710,730,750,770,790,810,830,850,870,890,910,930,950,970,990,1010,1030,1050,1070,1090,1110,1130,1150,1170,1190,1210,1230,1250,1270,1290,1310,1330,1350,1370,1390,1410,1430,1450,1470,1490,1510,1530,1550,1570,1590,1610,1630,1650,1670,1690,1710,1730,1750,1770,1790,1810,1830,1850,1870,1890,1910,1930,1950,1970,1990,11,31,51,71,91,111,131,151,171,191,211,231,251,271,291,311,331,351,371,391,411,431,451,471,491,511,531,551,571,591,611,631,651,671,691,711,731,751,771,791,811,831,851,871,891,911,931,951,971,991,1011,1031,1051,1071,1091,1111,1131,1151,1171,1191,1211,1231,1251,1271,1291,1311,1331,1351,1371,1391,1411,1431,1451,1471,1491,1511,1531,1551,1571,1591,1611,1631,1651,1671,1691,1711,1731,1751,1771,1791,1811,1831,1851,1871,1891,1911,1931,1951,1971,1991,12,32,52,72,92,112,132,152,172,192,212,232,252,272,292,312,332,352,372,392,412,432,452,472,492,512,532,552,572,592,612,632,652,672,692,712,732,752,772,792,812,832,852,872,892,912,932,952,972,992,1012,1032,1052,1072,1092,1112,1132,1152,1172,1192,1212,1232,1252,1272,1292,1312,1332,1352,1372,1392,1412,1432,1452,1472,1492,1512,1532,1552,1572,1592,1612,1632,1652,1672,1692,1712,1732,1752,1772,1792,1812,1832,1852,1872,1892,1912,1932,1952,1972,1992,13,33,53,73,93,113,133,153,173,193,213,233,253,273,293,313,333,353,373,393,413,433,453,473,493,513,533,553,573,593,613,633,653,673,693,713,733,753,773,793,813,833,853,873,893,913,933,953,973,993,1013,1033,1053,1073,1093,1113,1133,1153,1173,1193,1213,1233,1253,1273,1293,1313,1333,1353,1373,1393,1413,1433,1453,1473,1493,1513,1533,1553,1573,1593,1613,1633,1653,1673,1693,1713,1733,1753,1773,1793,1813,1833,1853,1873,1893,1913,1933,1953,1973,1993,14,34,54,74,94,114,134,154,174,194,214,234,254,274,294,314,334,354,374,394,414,434,454,474,494,514,534,554,574,594,614,634,654,674,694,714,734,754,774,794,814,834,854,874,894,914,934,954,974,994,1014,1034,1054,1074,1094,1114,1134,1154,1174,1194,1214,1234,1254,1274,1294,1314,1334,1354,1374,1394,1414,1434,1454,1474,1494,1514,1534,1554,1574,1594,1614,1634,1654,1674,1694,1714,1734,1754,1774,1794,1814,1834,1854,1874,1894,1914,1934,1954,1974,1994,15,35,55,75,95,115,135,155,175,195,215,235,255,275,295,315,335,355,375,395,415,435,455,475,495,515,535,555,575,595,615,635,655,675,695,715,735,755,775,795,815,835,855,875,895,915,935,955,975,995,1015,1035,1055,1075,1095,1115,1135,1155,1175,1195,1215,1235,1255,1275,1295,1315,1335,1355,1375,1395,1415,1435,1455,1475,1495,1515,1535,1555,1575,1595,1615,1635,1655,1675,1695,1715,1735,1755,1775,1795,1815,1835,1855,1875,1895,1915,1935,1955,1975,1995,16,36,56,76,96,116,136,156,176,196,216,236,256,276,296,316,336,356,376,396,416,436,456,476,496,516,536,556,576,596,616,636,656,676,696,716,736,756,776,796,816,836,856,876,896,916,936,956,976,996,1016,1036,1056,1076,1096,1116,1136,1156,1176,1196,1216,1236,1256,1276,1296,1316,1336,1356,1376,1396,1416,1436,1456,1476,1496,1516,1536,1556,1576,1596,1616,1636,1656,1676,1696,1716,1736,1756,1776,1796,1816,1836,1856,1876,1896,1916,1936,1956,1976,1996,17,37,57,77,97,117,137,157,177,197,217,237,257,277,297,317,337,357,377,397,417,437,457,477,497,517,537,557,577,597,617,637,657,677,697,717,737,757,777,797,817,837,857,877,897,917,937,957,977,997,1017,1037,1057,1077,1097,1117,1137,1157,1177,1197,1217,1237,1257,1277,1297,1317,1337,1357,1377,1397,1417,1437,1457,1477,1497,1517,1537,1557,1577,1597,1617,1637,1657,1677,1697,1717,1737,1757,1777,1797,1817,1837,1857,1877,1897,1917,1937,1957,1977,1997,18,38,58,78,98,118,138,158,178,198,218,238,258,278,298,318,338,358,378,398,418,438,458,478,498,518,538,558,578,598,618,638,658,678,698,718,738,758,778,798,818,838,858,878,898,918,938,958,978,998,1018,1038,1058,1078,1098,1118,1138,1158,1178,1198,1218,1238,1258,1278,1298,1318,1338,1358,1378,1398,1418,1438,1458,1478,1498,1518,1538,1558,1578,1598,1618,1638,1658,1678,1698,1718,1738,1758,1778,1798,1818,1838,1858,1878,1898,1918,1938,1958,1978,1998,1,21,41,61,81,101,121,141,161,181,201,221,241,261,281,301,321,341,361,381,401,421,441,461,481,501,521,541,561,581,601,621,641,661,681,701,721,741,761,781,801,821,841,861,881,901,921,941,961,981,1001,1021,1041,1061,1081,1101,1121,1141,1161,1181,1201,1221,1241,1261,1281,1301,1321,1341,1361,1381,1401,1421,1441,1461,1481,1501,1521,1541,1561,1581,1601,1621,1641,1661,1681,1701,1721,1741,1761,1781,1801,1821,1841,1861,1881,1901,1921,1941,1961,1981,19,39,59,79,99,119,139,159,179,199,219,239,259,279,299,319,339,359,379,399,419,439,459,479,499,519,539,559,579,599,619,639,659,679,699,719,739,759,779,799,819,839,859,879,899,919,939,959,979,999,1019,1039,1059,1079,1099,1119,1139,1159,1179,1199,1219,1239,1259,1279,1299,1319,1339,1359,1379,1399,1419,1439,1459,1479,1499,1519,1539,1559,1579,1599,1619,1639,1659,1679,1699,1719,1739,1759,1779,1799,1819,1839,1859,1879,1899,1919,1939,1959,1979,1999,2,22,42,62,82,102,122,142,162,182,202,222,242,262,282,302,322,342,362,382,402,422,442,462,482,502,522,542,562,582,602,622,642,662,682,702,722,742,762,782,802,822,842,862,882,902,922,942,962,982,1002,1022,1042,1062,1082,1102,1122,1142,1162,1182,1202,1222,1242,1262,1282,1302,1322,1342,1362,1382,1402,1422,1442,1462,1482,1502,1522,1542,1562,1582,1602,1622,1642,1662,1682,1702,1722,1742,1762,1782,1802,1822,1842,1862,1882,1902,1922,1942,1962,1982,3,23,43,63,83,103,123,143,163,183,203,223,243,263,283,303,323,343,363,383,403,423,443,463,483,503,523,543,563,583,603,623,643,663,683,703,723,743,763,783,803,823,843,863,883,903,923,943,963,983,1003,1023,1043,1063,1083,1103,1123,1143,1163,1183,1203,1223,1243,1263,1283,1303,1323,1343,1363,1383,1403,1423,1443,1463,1483,1503,1523,1543,1563,1583,1603,1623,1643,1663,1683,1703,1723,1743,1763,1783,1803,1823,1843,1863,1883,1903,1923,1943,1963,1983,4,24,44,64,84,104,124,144,164,184,204,224,244,264,284,304,324,344,364,384,404,424,444,464,484,504,524,544,564,584,604,624,644,664,684,704,724,744,764,784,804,824,844,864,884,904,924,944,964,984,1004,1024,1044,1064,1084,1104,1124,1144,1164,1184,1204,1224,1244,1264,1284,1304,1324,1344,1364,1384,1404,1424,1444,1464,1484,1504,1524,1544,1564,1584,1604,1624,1644,1664,1684,1704,1724,1744,1764,1784,1804,1824,1844,1864,1884,1904,1924,1944,1964,1984,5,25,45,65,85,105,125,145,165,185,205,225,245,265,285,305,325,345,365,385,405,425,445,465,485,505,525,545,565,585,605,625,645,665,685,705,725,745,765,785,805,825,845,865,885,905,925,945,965,985,1005,1025,1045,1065,1085,1105,1125,1145,1165,1185,1205,1225,1245,1265,1285,1305,1325,1345,1365,1385,1405,1425,1445,1465,1485,1505,1525,1545,1565,1585,1605,1625,1645,1665,1685,1705,1725,1745,1765,1785,1805,1825,1845,1865,1885,1905,1925,1945,1965,1985,6,26,46,66,86,106,126,146,166,186,206,226,246,266,286,306,326,346,366,386,406,426,446,466,486,506,526,546,566,586,606,626,646,666,686,706,726,746,766,786,806,826,846,866,886,906,926,946,966,986,1006,1026,1046,1066,1086,1106,1126,1146,1166,1186,1206,1226,1246,1266,1286,1306,1326,1346,1366,1386,1406,1426,1446,1466,1486,1506,1526,1546,1566,1586,1606,1626,1646,1666,1686,1706,1726,1746,1766,1786,1806,1826,1846,1866,1886,1906,1926,1946,1966,1986,7,27,47,67,87,107,127,147,167,187,207,227,247,267,287,307,327,347,367,387,407,427,447,467,487,507,527,547,567,587,607,627,647,667,687,707,727,747,767,787,807,827,847,867,887,907,927,947,967,987,1007,1027,1047,1067,1087,1107,1127,1147,1167,1187,1207,1227,1247,1267,1287,1307,1327,1347,1367,1387,1407,1427,1447,1467,1487,1507,1527,1547,1567,1587,1607,1627,1647,1667,1687,1707,1727,1747,1767,1787,1807,1827,1847,1867,1887,1907,1927,1947,1967,1987,8,28,48,68,88,108,128,148,168,188,208,228,248,268,288,308,328,348,368,388,408,428,448,468,488,508,528,548,568,588,608,628,648,668,688,708,728,748,768,788,808,828,848,868,888,908,928,948,968,988,1008,1028,1048,1068,1088,1108,1128,1148,1168,1188,1208,1228,1248,1268,1288,1308,1328,1348,1368,1388,1408,1428,1448,1468,1488,1508,1528,1548,1568,1588,1608,1628,1648,1668,1688,1708,1728,1748,1768,1788,1808,1828,1848,1868,1888,1908,1928,1948,1968,1988)
ORDER BY k.kind, k.name
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| k | ALL |  | 400 | 100 | Using filesort |
| mc | ref | fk_mc_controller | 10 | 100 | Using where; Using index |

```sql
SELECT model_id, COUNT(*) FROM fault_codes WHERE model_id IN
(20,40,60,80,100,120,140,160,180,200,220,240,260,280,300,320,340,360,380,400,420,440,460,480,500,520,540,560,580,600,620,640,660,680,700,720,740,760,780,800,820,840,860,880,900,920,940,960,980,1000,1020,1040,1060,1080,1100,1120,1140,1160,1180,1200,1220,1240,1260,1280,1300,1320,1340,1360,1380,1400,1420,1440,1460,1480,1500,1520,1540,1560,1580,1600,1620,1640,1660,1680,1700,1720,1740,1760,1780,1800,1820,1840,1860,1880,1900,1920,1940,1960,1980,2000,9,29,49,69,89,109,129,149,169,189,209,229,249,269,289,309,329,349,369,389,409,429,449,469,489,509,529,549,569,589,609,629,649,669,689,709,729,749,769,789,809,829,849,869,889,909,929,949,969,989,1009,1029,1049,1069,1089,1109,1129,1149,1169,1189,1209,1229,1249,1269,1289,1309,1329,1349,1369,1389,1409,1429,1449,1469,1489,1509,1529,1549,1569,1589,1609,1629,1649,1669,1689,1709,1729,1749,1769,1789,1809,1829,1849,1869,1889,1909,1929,1949,1969,1989,10,30,50,70,90,110,130,150,170,190,210,230,250,270,290,310,330,350,370,390,410,430,450,470,490,510,530,550,570,590,610,630,650,670,690,710,730,750,770,790,810,830,850,870,890,910,930,950,970,990,1010,1030,1050,1070,1090,1110,1130,1150,1170,1190,1210,1230,1250,1270,1290,1310,1330,1350,1370,1390,1410,1430,1450,1470,1490,1510,1530,1550,1570,1590,1610,1630,1650,1670,1690,1710,1730,1750,1770,1790,1810,1830,1850,1870,1890,1910,1930,1950,1970,1990,11,31,51,71,91,111,131,151,171,191,211,231,251,271,291,311,331,351,371,391,411,431,451,471,491,511,531,551,571,591,611,631,651,671,691,711,731,751,771,791,811,831,851,871,891,911,931,951,971,991,1011,1031,1051,1071,1091,1111,1131,1151,1171,1191,1211,1231,1251,1271,1291,1311,1331,1351,1371,1391,1411,1431,1451,1471,1491,1511,1531,1551,1571,1591,1611,1631,1651,1671,1691,1711,1731,1751,1771,1791,1811,1831,1851,1871,1891,1911,1931,1951,1971,1991,12,32,52,72,92,112,132,152,172,192,212,232,252,272,292,312,332,352,372,392,412,432,452,472,492,512,532,552,572,592,612,632,652,672,692,712,732,752,772,792,812,832,852,872,892,912,932,952,972,992,1012,1032,1052,1072,1092,1112,1132,1152,1172,1192,1212,1232,1252,1272,1292,1312,1332,1352,1372,1392,1412,1432,1452,1472,1492,1512,1532,1552,1572,1592,1612,1632,1652,1672,1692,1712,1732,1752,1772,1792,1812,1832,1852,1872,1892,1912,1932,1952,1972,1992,13,33,53,73,93,113,133,153,173,193,213,233,253,273,293,313,333,353,373,393,413,433,453,473,493,513,533,553,573,593,613,633,653,673,693,713,733,753,773,793,813,833,853,873,893,913,933,953,973,993,1013,1033,1053,1073,1093,1113,1133,1153,1173,1193,1213,1233,1253,1273,1293,1313,1333,1353,1373,1393,1413,1433,1453,1473,1493,1513,1533,1553,1573,1593,1613,1633,1653,1673,1693,1713,1733,1753,1773,1793,1813,1833,1853,1873,1893,1913,1933,1953,1973,1993,14,34,54,74,94,114,134,154,174,194,214,234,254,274,294,314,334,354,374,394,414,434,454,474,494,514,534,554,574,594,614,634,654,674,694,714,734,754,774,794,814,834,854,874,894,914,934,954,974,994,1014,1034,1054,1074,1094,1114,1134,1154,1174,1194,1214,1234,1254,1274,1294,1314,1334,1354,1374,1394,1414,1434,1454,1474,1494,1514,1534,1554,1574,1594,1614,1634,1654,1674,1694,1714,1734,1754,1774,1794,1814,1834,1854,1874,1894,1914,1934,1954,1974,1994,15,35,55,75,95,115,135,155,175,195,215,235,255,275,295,315,335,355,375,395,415,435,455,475,495,515,535,555,575,595,615,635,655,675,695,715,735,755,775,795,815,835,855,875,895,915,935,955,975,995,1015,1035,1055,1075,1095,1115,1135,1155,1175,1195,1215,1235,1255,1275,1295,1315,1335,1355,1375,1395,1415,1435,1455,1475,1495,1515,1535,1555,1575,1595,1615,1635,1655,1675,1695,1715,1735,1755,1775,1795,1815,1835,1855,1875,1895,1915,1935,1955,1975,1995,16,36,56,76,96,116,136,156,176,196,216,236,256,276,296,316,336,356,376,396,416,436,456,476,496,516,536,556,576,596,616,636,656,676,696,716,736,756,776,796,816,836,856,876,896,916,936,956,976,996,1016,1036,1056,1076,1096,1116,1136,1156,1176,1196,1216,1236,1256,1276,1296,1316,1336,1356,1376,1396,1416,1436,1456,1476,1496,1516,1536,1556,1576,1596,1616,1636,1656,1676,1696,1716,1736,1756,1776,1796,1816,1836,1856,1876,1896,1916,1936,1956,1976,1996,17,37,57,77,97,117,137,157,177,197,217,237,257,277,297,317,337,357,377,397,417,437,457,477,497,517,537,557,577,597,617,637,657,677,697,717,737,757,777,797,817,837,857,877,897,917,937,957,977,997,1017,1037,1057,1077,1097,1117,1137,1157,1177,1197,1217,1237,1257,1277,1297,1317,1337,1357,1377,1397,1417,1437,1457,1477,1497,1517,1537,1557,1577,1597,1617,1637,1657,1677,1697,1717,1737,1757,1777,1797,1817,1837,1857,1877,1897,1917,1937,1957,1977,1997,18,38,58,78,98,118,138,158,178,198,218,238,258,278,298,318,338,358,378,398,418,438,458,478,498,518,538,558,578,598,618,638,658,678,698,718,738,758,778,798,818,838,858,878,898,918,938,958,978,998,1018,1038,1058,1078,1098,1118,1138,1158,1178,1198,1218,1238,1258,1278,1298,1318,1338,1358,1378,1398,1418,1438,1458,1478,1498,1518,1538,1558,1578,1598,1618,1638,1658,1678,1698,1718,1738,1758,1778,1798,1818,1838,1858,1878,1898,1918,1938,1958,1978,1998,1,21,41,61,81,101,121,141,161,181,201,221,241,261,281,301,321,341,361,381,401,421,441,461,481,501,521,541,561,581,601,621,641,661,681,701,721,741,761,781,801,821,841,861,881,901,921,941,961,981,1001,1021,1041,1061,1081,1101,1121,1141,1161,1181,1201,1221,1241,1261,1281,1301,1321,1341,1361,1381,1401,1421,1441,1461,1481,1501,1521,1541,1561,1581,1601,1621,1641,1661,1681,1701,1721,1741,1761,1781,1801,1821,1841,1861,1881,1901,1921,1941,1961,1981,19,39,59,79,99,119,139,159,179,199,219,239,259,279,299,319,339,359,379,399,419,439,459,479,499,519,539,559,579,599,619,639,659,679,699,719,739,759,779,799,819,839,859,879,899,919,939,959,979,999,1019,1039,1059,1079,1099,1119,1139,1159,1179,1199,1219,1239,1259,1279,1299,1319,1339,1359,1379,1399,1419,1439,1459,1479,1499,1519,1539,1559,1579,1599,1619,1639,1659,1679,1699,1719,1739,1759,1779,1799,1819,1839,1859,1879,1899,1919,1939,1959,1979,1999,2,22,42,62,82,102,122,142,162,182,202,222,242,262,282,302,322,342,362,382,402,422,442,462,482,502,522,542,562,582,602,622,642,662,682,702,722,742,762,782,802,822,842,862,882,902,922,942,962,982,1002,1022,1042,1062,1082,1102,1122,1142,1162,1182,1202,1222,1242,1262,1282,1302,1322,1342,1362,1382,1402,1422,1442,1462,1482,1502,1522,1542,1562,1582,1602,1622,1642,1662,1682,1702,1722,1742,1762,1782,1802,1822,1842,1862,1882,1902,1922,1942,1962,1982,3,23,43,63,83,103,123,143,163,183,203,223,243,263,283,303,323,343,363,383,403,423,443,463,483,503,523,543,563,583,603,623,643,663,683,703,723,743,763,783,803,823,843,863,883,903,923,943,963,983,1003,1023,1043,1063,1083,1103,1123,1143,1163,1183,1203,1223,1243,1263,1283,1303,1323,1343,1363,1383,1403,1423,1443,1463,1483,1503,1523,1543,1563,1583,1603,1623,1643,1663,1683,1703,1723,1743,1763,1783,1803,1823,1843,1863,1883,1903,1923,1943,1963,1983,4,24,44,64,84,104,124,144,164,184,204,224,244,264,284,304,324,344,364,384,404,424,444,464,484,504,524,544,564,584,604,624,644,664,684,704,724,744,764,784,804,824,844,864,884,904,924,944,964,984,1004,1024,1044,1064,1084,1104,1124,1144,1164,1184,1204,1224,1244,1264,1284,1304,1324,1344,1364,1384,1404,1424,1444,1464,1484,1504,1524,1544,1564,1584,1604,1624,1644,1664,1684,1704,1724,1744,1764,1784,1804,1824,1844,1864,1884,1904,1924,1944,1964,1984,5,25,45,65,85,105,125,145,165,185,205,225,245,265,285,305,325,345,365,385,405,425,445,465,485,505,525,545,565,585,605,625,645,665,685,705,725,745,765,785,805,825,845,865,885,905,925,945,965,985,1005,1025,1045,1065,1085,1105,1125,1145,1165,1185,1205,1225,1245,1265,1285,1305,1325,1345,1365,1385,1405,1425,1445,1465,1485,1505,1525,1545,1565,1585,1605,1625,1645,1665,1685,1705,1725,1745,1765,1785,1805,1825,1845,1865,1885,1905,1925,1945,1965,1985,6,26,46,66,86,106,126,146,166,186,206,226,246,266,286,306,326,346,366,386,406,426,446,466,486,506,526,546,566,586,606,626,646,666,686,706,726,746,766,786,806,826,846,866,886,906,926,946,966,986,1006,1026,1046,1066,1086,1106,1126,1146,1166,1186,1206,1226,1246,1266,1286,1306,1326,1346,1366,1386,1406,1426,1446,1466,1486,1506,1526,1546,1566,1586,1606,1626,1646,1666,1686,1706,1726,1746,1766,1786,1806,1826,1846,1866,1886,1906,1926,1946,1966,1986,7,27,47,67,87,107,127,147,167,187,207,227,247,267,287,307,327,347,367,387,407,427,447,467,487,507,527,547,567,587,607,627,647,667,687,707,727,747,767,787,807,827,847,867,887,907,927,947,967,987,1007,1027,1047,1067,1087,1107,1127,1147,1167,1187,1207,1227,1247,1267,1287,1307,1327,1347,1367,1387,1407,1427,1447,1467,1487,1507,1527,1547,1567,1587,1607,1627,1647,1667,1687,1707,1727,1747,1767,1787,1807,1827,1847,1867,1887,1907,1927,1947,1967,1987,8,28,48,68,88,108,128,148,168,188,208,228,248,268,288,308,328,348,368,388,408,428,448,468,488,508,528,548,568,588,608,628,648,668,688,708,728,748,768,788,808,828,848,868,888,908,928,948,968,988,1008,1028,1048,1068,1088,1108,1128,1148,1168,1188,1208,1228,1248,1268,1288,1308,1328,1348,1368,1388,1408,1428,1448,1468,1488,1508,1528,1548,1568,1588,1608,1628,1648,1668,1688,1708,1728,1748,1768,1788,1808,1828,1848,1868,1888,1908,1928,1948,1968,1988)
GROUP BY model_id
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fault_codes | range | uq_fault_codes_model_code | 56000 | 100 | Using where; Using index |

```sql
SELECT controller_id, COUNT(*) FROM fault_codes WHERE controller_id IN
(1,394,10,17,101,94,102,109,103,96,105,98,106,113,107,100,11,4,110,117,111,104,114,121,115,108,118,125,119,112,122,129,123,116,126,133,127,120,13,6,130,137,131,124,134,141,135,128,138,145,139,132,14,21,142,149,143,136,146,153,147,140,15,8,150,157,151,144,154,161,155,148,158,165,159,152,162,169,163,156,166,173,167,160,170,177,171,164,174,181,175,168,178,185,179,172,18,25,182,189,183,176,186,193,187,180,19,12,190,197,191,184,194,201,195,188,198,205,199,192,2,9,202,209,203,196,206,213,207,200,210,217,211,204,214,221,215,208,218,225,219,212,22,29,222,229,223,216,226,233,227,220,23,16,230,237,231,224,234,241,235,228,238,245,239,232,242,249,243,236,246,253,247,240,250,257,251,244,254,261,255,248,258,265,259,252,26,33,262,269,263,256,266,273,267,260,27,20,270,277,271,264,274,281,275,268,278,285,279,272,282,289,283,276,286,293,287,280,290,297,291,284,294,301,295,288,298,305,299,292,3,396,30,37,302,309,303,296,306,313,307,300,31,24,310,317,311,304,314,321,315,308,318,325,319,312,322,329,323,316,326,333,327,320,330,337,331,324,334,341,335,328,338,345,339,332,34,41,342,349,343,336,346,353,347,340,35,28,350,357,351,344,354,361,355,348,358,365,359,352,362,369,363,356,366,373,367,360,370,377,371,364,374,381,375,368,378,385,379,372,38,45,382,389,383,376,386,393,387,380,39,32,390,397,391,384,395,388,398,5,399,392,42,49,43,36,46,53,47,40,50,57,51,44,54,61,55,48,58,65,59,52,62,69,63,56,66,73,67,60,7,400,70,77,71,64,74,81,75,68,78,85,79,72,82,89,83,76,86,93,87,80,90,97,91,84,95,88,99,92)
GROUP BY controller_id
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fault_codes | index | uq_fault_codes_controller_code | 57737 | 100 | Using where; Using index |

**После**

```sql
SELECT mo.id, mo.name, mo.notes, m.slug AS manufacturer_slug, m.name AS manufacturer_name FROM models mo JOIN
manufacturers m ON m.id = mo.manufacturer_id ORDER BY m.name, mo.name
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| m | ALL |  | 20 | 100 | Using temporary; Using filesort |
| mo | ref | uq_models_manufacturer_name | 100 | 100 |  |

```sql
SELECT mc.model_id, k.id, k.name, k.kind FROM model_controllers mc JOIN controllers k ON k.id =
mc.controller_id WHERE mc.model_id IN
(20,40,60,80,100,120,140,160,180,200,220,240,260,280,300,320,340,360,380,400,420,440,460,480,500,520,540,560,580,600,620,640,660,680,700,720,740,760,780,800,820,840,860,880,900,920,940,960,980,1000,1020,1040,1060,1080,1100,1120,1140,1160,1180,1200,1220,1240,1260,1280,1300,1320,1340,1360,1380,1400,1420,1440,1460,1480,1500,1520,1540,1560,1580,1600,1620,1640,1660,1680,1700,1720,1740,1760,1780,1800,1820,1840,1860,1880,1900,1920,1940,1960,1980,2000,9,29,49,69,89,109,129,149,169,189,209,229,249,269,289,309,329,349,369,389,409,429,449,469,489,509,529,549,569,589,609,629,649,669,689,709,729,749,769,789,809,829,849,869,889,909,929,949,969,989,1009,1029,1049,1069,1089,1109,1129,1149,1169,1189,1209,1229,1249,1269,1289,1309,1329,1349,1369,1389,1409,1429,1449,1469,1489,1509,1529,1549,1569,1589,1609,1629,1649,1669,1689,1709,1729,1749,1769,1789,1809,1829,1849,1869,1889,1909,1929,1949,1969,1989,10,30,50,70,90,110,130,150,170,190,210,230,250,270,290,310,330,350,370,390,410,430,450,470,490,510,530,550,570,590,610,630,650,670,690,710,730,750,770,790,810,830,850,870,890,910,930,950,970,990,1010,1030,1050,1070,1090,1110,1130,1150,1170,1190,1210,1230,1250,1270,1290,1310,1330,1350,1370,1390,1410,1430,1450,1470,1490,1510,1530,1550,1570,1590,1610,1630,1650,1670,1690,1710,1730,1750,1770,1790,1810,1830,1850,1870,1890,1910,1930,1950,1970,1990,11,31,51,71,91,111,131,151,171,191,211,231,251,271,291,311,331,351,371,391,411,431,451,471,491,511,531,551,571,591,611,631,651,671,691,711,731,751,771,791,811,831,851,871,891,911,931,951,971,991,1011,1031,1051,1071,1091,1111,1131,1151,1171,1191,1211,1231,1251,1271,1291,1311,1331,1351,1371,1391,1411,1431,1451,1471,1491,1511,1531,1551,1571,1591,1611,1631,1651,1671,1691,1711,1731,1751,1771,1791,1811,1831,1851,1871,1891,1911,1931,1951,1971,1991,12,32,52,72,92,112,132,152,172,192,212,232,252,272,292,312,332,352,372,392,412,432,452,472,492,512,532,552,572,592,612,632,652,672,692,712,732,752,772,792,812,832,852,872,892,912,932,952,972,992,1012,1032,1052,1072,1092,1112,1132,1152,1172,1192,1212,1232,1252,1272,1292,1312,1332,1352,1372,1392,1412,1432,1452,1472,1492,1512,1532,1552,1572,1592,1612,1632,1652,1672,1692,1712,1732,1752,1772,1792,1812,1832,1852,1872,1892,1912,1932,1952,1972,1992,13,33,53,73,93,113,133,153,173,193,213,233,253,273,293,313,333,353,373,393,413,433,453,473,493,513,533,553,573,593,613,633,653,673,693,713,733,753,773,793,813,833,853,873,893,913,933,953,973,993,1013,1033,1053,1073,1093,1113,1133,1153,1173,1193,1213,1233,1253,1273,1293,1313,1333,1353,1373,1393,1413,1433,1453,1473,1493,1513,1533,1553,1573,1593,1613,1633,1653,1673,1693,1713,1733,1753,1773,1793,1813,1833,1853,1873,1893,1913,1933,1953,1973,1993,14,34,54,74,94,114,134,154,174,194,214,234,254,274,294,314,334,354,374,394,414,434,454,474,494,514,534,554,574,594,614,634,654,674,694,714,734,754,774,794,814,834,854,874,894,914,934,954,974,994,1014,1034,1054,1074,1094,1114,1134,1154,1174,1194,1214,1234,1254,1274,1294,1314,1334,1354,1374,1394,1414,1434,1454,1474,1494,1514,1534,1554,1574,1594,1614,1634,1654,1674,1694,1714,1734,1754,1774,1794,1814,1834,1854,1874,1894,1914,1934,1954,1974,1994,15,35,55,75,95,115,135,155,175,195,215,235,255,275,295,315,335,355,375,395,415,435,455,475,495,515,535,555,575,595,615,635,655,675,695,715,735,755,775,795,815,835,855,875,895,915,935,955,975,995,1015,1035,1055,1075,1095,1115,1135,1155,1175,1195,1215,1235,1255,1275,1295,1315,1335,1355,1375,1395,1415,1435,1455,1475,1495,1515,1535,1555,1575,1595,1615,1635,1655,1675,1695,1715,1735,1755,1775,1795,1815,1835,1855,1875,1895,1915,1935,1955,1975,1995,16,36,56,76,96,116,136,156,176,196,216,236,256,276,296,316,336,356,376,396,416,436,456,476,496,516,536,556,576,596,616,636,656,676,696,716,736,756,776,796,816,836,856,876,896,916,936,956,976,996,1016,1036,1056,1076,1096,1116,1136,1156,1176,1196,1216,1236,1256,1276,1296,1316,1336,1356,1376,1396,1416,1436,1456,1476,1496,1516,1536,1556,1576,1596,1616,1636,1656,1676,1696,1716,1736,1756,1776,1796,1816,1836,1856,1876,1896,1916,1936,1956,1976,1996,17,37,57,77,97,117,137,157,177,197,217,237,257,277,297,317,337,357,377,397,417,437,457,477,497,517,537,557,577,597,617,637,657,677,697,717,737,757,777,797,817,837,857,877,897,917,937,957,977,997,1017,1037,1057,1077,1097,1117,1137,1157,1177,1197,1217,1237,1257,1277,1297,1317,1337,1357,1377,1397,1417,1437,1457,1477,1497,1517,1537,1557,1577,1597,1617,1637,1657,1677,1697,1717,1737,1757,1777,1797,1817,1837,1857,1877,1897,1917,1937,1957,1977,1997,18,38,58,78,98,118,138,158,178,198,218,238,258,278,298,318,338,358,378,398,418,438,458,478,498,518,538,558,578,598,618,638,658,678,698,718,738,758,778,798,818,838,858,878,898,918,938,958,978,998,1018,1038,1058,1078,1098,1118,1138,1158,1178,1198,1218,1238,1258,1278,1298,1318,1338,1358,1378,1398,1418,1438,1458,1478,1498,1518,1538,1558,1578,1598,1618,1638,1658,1678,1698,1718,1738,1758,1778,1798,1818,1838,1858,1878,1898,1918,1938,1958,1978,1998,1,21,41,61,81,101,121,141,161,181,201,221,241,261,281,301,321,341,361,381,401,421,441,461,481,501,521,541,561,581,601,621,641,661,681,701,721,741,761,781,801,821,841,861,881,901,921,941,961,981,1001,1021,1041,1061,1081,1101,1121,1141,1161,1181,1201,1221,1241,1261,1281,1301,1321,1341,1361,1381,1401,1421,1441,1461,1481,1501,1521,1541,1561,1581,1601,1621,1641,1661,1681,1701,1721,1741,1761,1781,1801,1821,1841,1861,1881,1901,1921,1941,1961,1981,19,39,59,79,99,119,139,159,179,199,219,239,259,279,299,319,339,359,379,399,419,439,459,479,499,519,539,559,579,599,619,639,659,679,699,719,739,759,779,799,819,839,859,879,899,919,939,959,979,999,1019,1039,1059,1079,1099,1119,1139,1159,1179,1199,1219,1239,1259,1279,1299,1319,1339,1359,1379,1399,1419,1439,1459,1479,1499,1519,1539,1559,1579,1599,1619,1639,1659,1679,1699,1719,1739,1759,1779,1799,1819,1839,1859,1879,1899,1919,1939,1959,1979,1999,2,22,42,62,82,102,122,142,162,182,202,222,242,262,282,302,322,342,362,382,402,422,442,462,482,502,522,542,562,582,602,622,642,662,682,702,722,742,762,782,802,822,842,862,882,902,922,942,962,982,1002,1022,1042,1062,1082,1102,1122,1142,1162,1182,1202,1222,1242,1262,1282,1302,1322,1342,1362,1382,1402,1422,1442,1462,1482,1502,1522,1542,1562,1582,1602,1622,1642,1662,1682,1702,1722,1742,1762,1782,1802,1822,1842,1862,1882,1902,1922,1942,1962,1982,3,23,43,63,83,103,123,143,163,183,203,223,243,263,283,303,323,343,363,383,403,423,443,463,483,503,523,543,563,583,603,623,643,663,683,703,723,743,763,783,803,823,843,863,883,903,923,943,963,983,1003,1023,1043,1063,1083,1103,1123,1143,1163,1183,1203,1223,1243,1263,1283,1303,1323,1343,1363,1383,1403,1423,1443,1463,1483,1503,1523,1543,1563,1583,1603,1623,1643,1663,1683,1703,1723,1743,1763,1783,1803,1823,1843,1863,1883,1903,1923,1943,1963,1983,4,24,44,64,84,104,124,144,164,184,204,224,244,264,284,304,324,344,364,384,404,424,444,464,484,504,524,544,564,584,604,624,644,664,684,704,724,744,764,784,804,824,844,864,884,904,924,944,964,984,1004,1024,1044,1064,1084,1104,1124,1144,1164,1184,1204,1224,1244,1264,1284,1304,1324,1344,1364,1384,1404,1424,1444,1464,1484,1504,1524,1544,1564,1584,1604,1624,1644,1664,1684,1704,1724,1744,1764,1784,1804,1824,1844,1864,1884,1904,1924,1944,1964,1984,5,25,45,65,85,105,125,145,165,185,205,225,245,265,285,305,325,345,365,385,405,425,445,465,485,505,525,545,565,585,605,625,645,665,685,705,725,745,765,785,805,825,845,865,885,905,925,945,965,985,1005,1025,1045,1065,1085,1105,1125,1145,1165,1185,1205,1225,1245,1265,1285,1305,1325,1345,1365,1385,1405,1425,1445,1465,1485,1505,1525,1545,1565,1585,1605,1625,1645,1665,1685,1705,1725,1745,1765,1785,1805,1825,1845,1865,1885,1905,1925,1945,1965,1985,6,26,46,66,86,106,126,146,166,186,206,226,246,266,286,306,326,346,366,386,406,426,446,466,486,506,526,546,566,586,606,626,646,666,686,706,726,746,766,786,806,826,846,866,886,906,926,946,966,986,1006,1026,1046,1066,1086,1106,1126,1146,1166,1186,1206,1226,1246,1266,1286,1306,1326,1346,1366,1386,1406,1426,1446,1466,1486,1506,1526,1546,1566,1586,1606,1626,1646,1666,1686,1706,1726,1746,1766,1786,1806,1826,1846,1866,1886,1906,1926,1946,1966,1986,7,27,47,67,87,107,127,147,167,187,207,227,247,267,287,307,327,347,367,387,407,427,447,467,487,507,527,547,567,587,607,627,647,667,687,707,727,747,767,787,807,827,847,867,887,907,927,947,967,987,1007,1027,1047,1067,1087,1107,1127,1147,1167,1187,1207,1227,1247,1267,1287,1307,1327,1347,1367,1387,1407,1427,1447,1467,1487,1507,1527,1547,1567,1587,1607,1627,1647,1667,1687,1707,1727,1747,1767,1787,1807,1827,1847,1867,1887,1907,1927,1947,1967,1987,8,28,48,68,88,108,128,148,168,188,208,228,248,268,288,308,328,348,368,388,408,428,448,468,488,508,528,548,568,588,608,628,648,668,688,708,728,748,768,788,808,828,848,868,888,908,928,948,968,988,1008,1028,1048,1068,1088,1108,1128,1148,1168,1188,1208,1228,1248,1268,1288,1308,1328,1348,1368,1388,1408,1428,1448,1468,1488,1508,1528,1548,1568,1588,1608,1628,1648,1668,1688,1708,1728,1748,1768,1788,1808,1828,1848,1868,1888,1908,1928,1948,1968,1988)
ORDER BY k.kind, k.name
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| k | ALL |  | 400 | 100 | Using filesort |
| mc | ref | fk_mc_controller | 10 | 100 | Using where; Using index |

```sql
SELECT model_id, COUNT(*) FROM fault_codes WHERE model_id IN
(20,40,60,80,100,120,140,160,180,200,220,240,260,280,300,320,340,360,380,400,420,440,460,480,500,520,540,560,580,600,620,640,660,680,700,720,740,760,780,800,820,840,860,880,900,920,940,960,980,1000,1020,1040,1060,1080,1100,1120,1140,1160,1180,1200,1220,1240,1260,1280,1300,1320,1340,1360,1380,1400,1420,1440,1460,1480,1500,1520,1540,1560,1580,1600,1620,1640,1660,1680,1700,1720,1740,1760,1780,1800,1820,1840,1860,1880,1900,1920,1940,1960,1980,2000,9,29,49,69,89,109,129,149,169,189,209,229,249,269,289,309,329,349,369,389,409,429,449,469,489,509,529,549,569,589,609,629,649,669,689,709,729,749,769,789,809,829,849,869,889,909,929,949,969,989,1009,1029,1049,1069,1089,1109,1129,1149,1169,1189,1209,1229,1249,1269,1289,1309,1329,1349,1369,1389,1409,1429,1449,1469,1489,1509,1529,1549,1569,1589,1609,1629,1649,1669,1689,1709,1729,1749,1769,1789,1809,1829,1849,1869,1889,1909,1929,1949,1969,1989,10,30,50,70,90,110,130,150,170,190,210,230,250,270,290,310,330,350,370,390,410,430,450,470,490,510,530,550,570,590,610,630,650,670,690,710,730,750,770,790,810,830,850,870,890,910,930,950,970,990,1010,1030,1050,1070,1090,1110,1130,1150,1170,1190,1210,1230,1250,1270,1290,1310,1330,1350,1370,1390,1410,1430,1450,1470,1490,1510,1530,1550,1570,1590,1610,1630,1650,1670,1690,1710,1730,1750,1770,1790,1810,1830,1850,1870,1890,1910,1930,1950,1970,1990,11,31,51,71,91,111,131,151,171,191,211,231,251,271,291,311,331,351,371,391,411,431,451,471,491,511,531,551,571,591,611,631,651,671,691,711,731,751,771,791,811,831,851,871,891,911,931,951,971,991,1011,1031,1051,1071,1091,1111,1131,1151,1171,1191,1211,1231,1251,1271,1291,1311,1331,1351,1371,1391,1411,1431,1451,1471,1491,1511,1531,1551,1571,1591,1611,1631,1651,1671,1691,1711,1731,1751,1771,1791,1811,1831,1851,1871,1891,1911,1931,1951,1971,1991,12,32,52,72,92,112,132,152,172,192,212,232,252,272,292,312,332,352,372,392,412,432,452,472,492,512,532,552,572,592,612,632,652,672,692,712,732,752,772,792,812,832,852,872,892,912,932,952,972,992,1012,1032,1052,1072,1092,1112,1132,1152,1172,1192,1212,1232,1252,1272,1292,1312,1332,1352,1372,1392,1412,1432,1452,1472,1492,1512,1532,1552,1572,1592,1612,1632,1652,1672,1692,1712,1732,1752,1772,1792,1812,1832,1852,1872,1892,1912,1932,1952,1972,1992,13,33,53,73,93,113,133,153,173,193,213,233,253,273,293,313,333,353,373,393,413,433,453,473,493,513,533,553,573,593,613,633,653,673,693,713,733,753,773,793,813,833,853,873,893,913,933,953,973,993,1013,1033,1053,1073,1093,1113,1133,1153,1173,1193,1213,1233,1253,1273,1293,1313,1333,1353,1373,1393,1413,1433,1453,1473,1493,1513,1533,1553,1573,1593,1613,1633,1653,1673,1693,1713,1733,1753,1773,1793,1813,1833,1853,1873,1893,1913,1933,1953,1973,1993,14,34,54,74,94,114,134,154,174,194,214,234,254,274,294,314,334,354,374,394,414,434,454,474,494,514,534,554,574,594,614,634,654,674,694,714,734,754,774,794,814,834,854,874,894,914,934,954,974,994,1014,1034,1054,1074,1094,1114,1134,1154,1174,1194,1214,1234,1254,1274,1294,1314,1334,1354,1374,1394,1414,1434,1454,1474,1494,1514,1534,1554,1574,1594,1614,1634,1654,1674,1694,1714,1734,1754,1774,1794,1814,1834,1854,1874,1894,1914,1934,1954,1974,1994,15,35,55,75,95,115,135,155,175,195,215,235,255,275,295,315,335,355,375,395,415,435,455,475,495,515,535,555,575,595,615,635,655,675,695,715,735,755,775,795,815,835,855,875,895,915,935,955,975,995,1015,1035,1055,1075,1095,1115,1135,1155,1175,1195,1215,1235,1255,1275,1295,1315,1335,1355,1375,1395,1415,1435,1455,1475,1495,1515,1535,1555,1575,1595,1615,1635,1655,1675,1695,1715,1735,1755,1775,1795,1815,1835,1855,1875,1895,1915,1935,1955,1975,1995,16,36,56,76,96,116,136,156,176,196,216,236,256,276,296,316,336,356,376,396,416,436,456,476,496,516,536,556,576,596,616,636,656,676,696,716,736,756,776,796,816,836,856,876,896,916,936,956,976,996,1016,1036,1056,1076,1096,1116,1136,1156,1176,1196,1216,1236,1256,1276,1296,1316,1336,1356,1376,1396,1416,1436,1456,1476,1496,1516,1536,1556,1576,1596,1616,1636,1656,1676,1696,1716,1736,1756,1776,1796,1816,1836,1856,1876,1896,1916,1936,1956,1976,1996,17,37,57,77,97,117,137,157,177,197,217,237,257,277,297,317,337,357,377,397,417,437,457,477,497,517,537,557,577,597,617,637,657,677,697,717,737,757,777,797,817,837,857,877,897,917,937,957,977,997,1017,1037,1057,1077,1097,1117,1137,1157,1177,1197,1217,1237,1257,1277,1297,1317,1337,1357,1377,1397,1417,1437,1457,1477,1497,1517,1537,1557,1577,1597,1617,1637,1657,1677,1697,1717,1737,1757,1777,1797,1817,1837,1857,1877,1897,1917,1937,1957,1977,1997,18,38,58,78,98,118,138,158,178,198,218,238,258,278,298,318,338,358,378,398,418,438,458,478,498,518,538,558,578,598,618,638,658,678,698,718,738,758,778,798,818,838,858,878,898,918,938,958,978,998,1018,1038,1058,1078,1098,1118,1138,1158,1178,1198,1218,1238,1258,1278,1298,1318,1338,1358,1378,1398,1418,1438,1458,1478,1498,1518,1538,1558,1578,1598,1618,1638,1658,1678,1698,1718,1738,1758,1778,1798,1818,1838,1858,1878,1898,1918,1938,1958,1978,1998,1,21,41,61,81,101,121,141,161,181,201,221,241,261,281,301,321,341,361,381,401,421,441,461,481,501,521,541,561,581,601,621,641,661,681,701,721,741,761,781,801,821,841,861,881,901,921,941,961,981,1001,1021,1041,1061,1081,1101,1121,1141,1161,1181,1201,1221,1241,1261,1281,1301,1321,1341,1361,1381,1401,1421,1441,1461,1481,1501,1521,1541,1561,1581,1601,1621,1641,1661,1681,1701,1721,1741,1761,1781,1801,1821,1841,1861,1881,1901,1921,1941,1961,1981,19,39,59,79,99,119,139,159,179,199,219,239,259,279,299,319,339,359,379,399,419,439,459,479,499,519,539,559,579,599,619,639,659,679,699,719,739,759,779,799,819,839,859,879,899,919,939,959,979,999,1019,1039,1059,1079,1099,1119,1139,1159,1179,1199,1219,1239,1259,1279,1299,1319,1339,1359,1379,1399,1419,1439,1459,1479,1499,1519,1539,1559,1579,1599,1619,1639,1659,1679,1699,1719,1739,1759,1779,1799,1819,1839,1859,1879,1899,1919,1939,1959,1979,1999,2,22,42,62,82,102,122,142,162,182,202,222,242,262,282,302,322,342,362,382,402,422,442,462,482,502,522,542,562,582,602,622,642,662,682,702,722,742,762,782,802,822,842,862,882,902,922,942,962,982,1002,1022,1042,1062,1082,1102,1122,1142,1162,1182,1202,1222,1242,1262,1282,1302,1322,1342,1362,1382,1402,1422,1442,1462,1482,1502,1522,1542,1562,1582,1602,1622,1642,1662,1682,1702,1722,1742,1762,1782,1802,1822,1842,1862,1882,1902,1922,1942,1962,1982,3,23,43,63,83,103,123,143,163,183,203,223,243,263,283,303,323,343,363,383,403,423,443,463,483,503,523,543,563,583,603,623,643,663,683,703,723,743,763,783,803,823,843,863,883,903,923,943,963,983,1003,1023,1043,1063,1083,1103,1123,1143,1163,1183,1203,1223,1243,1263,1283,1303,1323,1343,1363,1383,1403,1423,1443,1463,1483,1503,1523,1543,1563,1583,1603,1623,1643,1663,1683,1703,1723,1743,1763,1783,1803,1823,1843,1863,1883,1903,1923,1943,1963,1983,4,24,44,64,84,104,124,144,164,184,204,224,244,264,284,304,324,344,364,384,404,424,444,464,484,504,524,544,564,584,604,624,644,664,684,704,724,744,764,784,804,824,844,864,884,904,924,944,964,984,1004,1024,1044,1064,1084,1104,1124,1144,1164,1184,1204,1224,1244,1264,1284,1304,1324,1344,1364,1384,1404,1424,1444,1464,1484,1504,1524,1544,1564,1584,1604,1624,1644,1664,1684,1704,1724,1744,1764,1784,1804,1824,1844,1864,1884,1904,1924,1944,1964,1984,5,25,45,65,85,105,125,145,165,185,205,225,245,265,285,305,325,345,365,385,405,425,445,465,485,505,525,545,565,585,605,625,645,665,685,705,725,745,765,785,805,825,845,865,885,905,925,945,965,985,1005,1025,1045,1065,1085,1105,1125,1145,1165,1185,1205,1225,1245,1265,1285,1305,1325,1345,1365,1385,1405,1425,1445,1465,1485,1505,1525,1545,1565,1585,1605,1625,1645,1665,1685,1705,1725,1745,1765,1785,1805,1825,1845,1865,1885,1905,1925,1945,1965,1985,6,26,46,66,86,106,126,146,166,186,206,226,246,266,286,306,326,346,366,386,406,426,446,466,486,506,526,546,566,586,606,626,646,666,686,706,726,746,766,786,806,826,846,866,886,906,926,946,966,986,1006,1026,1046,1066,1086,1106,1126,1146,1166,1186,1206,1226,1246,1266,1286,1306,1326,1346,1366,1386,1406,1426,1446,1466,1486,1506,1526,1546,1566,1586,1606,1626,1646,1666,1686,1706,1726,1746,1766,1786,1806,1826,1846,1866,1886,1906,1926,1946,1966,1986,7,27,47,67,87,107,127,147,167,187,207,227,247,267,287,307,327,347,367,387,407,427,447,467,487,507,527,547,567,587,607,627,647,667,687,707,727,747,767,787,807,827,847,867,887,907,927,947,967,987,1007,1027,1047,1067,1087,1107,1127,1147,1167,1187,1207,1227,1247,1267,1287,1307,1327,1347,1367,1387,1407,1427,1447,1467,1487,1507,1527,1547,1567,1587,1607,1627,1647,1667,1687,1707,1727,1747,1767,1787,1807,1827,1847,1867,1887,1907,1927,1947,1967,1987,8,28,48,68,88,108,128,148,168,188,208,228,248,268,288,308,328,348,368,388,408,428,448,468,488,508,528,548,568,588,608,628,648,668,688,708,728,748,768,788,808,828,848,868,888,908,928,948,968,988,1008,1028,1048,1068,1088,1108,1128,1148,1168,1188,1208,1228,1248,1268,1288,1308,1328,1348,1368,1388,1408,1428,1448,1468,1488,1508,1528,1548,1568,1588,1608,1628,1648,1668,1688,1708,1728,1748,1768,1788,1808,1828,1848,1868,1888,1908,1928,1948,1968,1988)
GROUP BY model_id
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fault_codes | range | uq_fault_codes_model_code | 56000 | 100 | Using where; Using index |

```sql
SELECT controller_id, COUNT(*) FROM fault_codes WHERE controller_id IN
(1,394,10,17,101,94,102,109,103,96,105,98,106,113,107,100,11,4,110,117,111,104,114,121,115,108,118,125,119,112,122,129,123,116,126,133,127,120,13,6,130,137,131,124,134,141,135,128,138,145,139,132,14,21,142,149,143,136,146,153,147,140,15,8,150,157,151,144,154,161,155,148,158,165,159,152,162,169,163,156,166,173,167,160,170,177,171,164,174,181,175,168,178,185,179,172,18,25,182,189,183,176,186,193,187,180,19,12,190,197,191,184,194,201,195,188,198,205,199,192,2,9,202,209,203,196,206,213,207,200,210,217,211,204,214,221,215,208,218,225,219,212,22,29,222,229,223,216,226,233,227,220,23,16,230,237,231,224,234,241,235,228,238,245,239,232,242,249,243,236,246,253,247,240,250,257,251,244,254,261,255,248,258,265,259,252,26,33,262,269,263,256,266,273,267,260,27,20,270,277,271,264,274,281,275,268,278,285,279,272,282,289,283,276,286,293,287,280,290,297,291,284,294,301,295,288,298,305,299,292,3,396,30,37,302,309,303,296,306,313,307,300,31,24,310,317,311,304,314,321,315,308,318,325,319,312,322,329,323,316,326,333,327,320,330,337,331,324,334,341,335,328,338,345,339,332,34,41,342,349,343,336,346,353,347,340,35,28,350,357,351,344,354,361,355,348,358,365,359,352,362,369,363,356,366,373,367,360,370,377,371,364,374,381,375,368,378,385,379,372,38,45,382,389,383,376,386,393,387,380,39,32,390,397,391,384,395,388,398,5,399,392,42,49,43,36,46,53,47,40,50,57,51,44,54,61,55,48,58,65,59,52,62,69,63,56,66,73,67,60,7,400,70,77,71,64,74,81,75,68,78,85,79,72,82,89,83,76,86,93,87,80,90,97,91,84,95,88,99,92)
GROUP BY controller_id
```

| table | type | key | rows | filtered | Extra |
|---|---|---|---:|---:|---|
| fault_codes | index | uq_fault_codes_controller_code | 57928 | 100 | Using where; Using index |
