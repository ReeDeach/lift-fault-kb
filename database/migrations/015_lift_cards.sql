-- Данные из «Карточек лифтов» (выгрузка Брестлифта по маршрутам механиков): подъезд,
-- модель (вид-грузоподъёмность-скорость, например П-400-1,0), этажность, заказчик, год установки.
-- По номеру лифта (reg_number) с ними сопоставляются заявки из отчёта 1С.

ALTER TABLE lifts
    ADD COLUMN entrance VARCHAR(10) NULL AFTER lift_no,
    ADD COLUMN equipment VARCHAR(40) NULL AFTER reg_number,
    ADD COLUMN floors TINYINT UNSIGNED NULL AFTER equipment,
    ADD COLUMN client VARCHAR(190) NULL AFTER floors,
    ADD COLUMN installed VARCHAR(20) NULL AFTER client,
    ADD KEY idx_lifts_reg (reg_number);
