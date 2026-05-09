USE rx_tracker;

INSERT INTO medications (name, dose, instructions, schedule_mode, time_format, interval_hours, first_dose_time, as_needed, starting_pill_count, pill_count, low_supply_threshold) VALUES
('Daily Vitamin', '1 tablet', 'Take with breakfast', 'fixed_times', '24h', NULL, NULL, 0, 20, 20, 5),
('Pain Relief', '1 tablet', 'As needed for pain', 'interval', '12h', 4, '08:00:00', 1, 12, 12, 3);

INSERT INTO medication_schedule_times (medication_id, reminder_time)
SELECT id, '08:00:00' FROM medications WHERE name = 'Daily Vitamin';

INSERT INTO medication_schedule_times (medication_id, reminder_time)
SELECT id, '21:00:00' FROM medications WHERE name = 'Daily Vitamin';

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('missed_grace_minutes', '60')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
