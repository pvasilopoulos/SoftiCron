-- Routee connector: SMS and Viber notification channels.
ALTER TABLE connectors MODIFY COLUMN type ENUM('smtp','m365','telegram','slack','teams','discord','webhook','routee') NOT NULL;
ALTER TABLE job_notifications MODIFY COLUMN channel ENUM('push','email','telegram','slack','teams','discord','webhook','sms','viber') NOT NULL;
-- One short "sms" template per event serves both SMS and Viber.
ALTER TABLE message_templates MODIFY COLUMN channel ENUM('email','telegram','push','chat','sms') NOT NULL;
-- Mobile numbers (E.164) that receive SMS / Viber for a job; empty = tenant default.
ALTER TABLE jobs ADD COLUMN notify_phones VARCHAR(500) NULL AFTER notify_telegram_chat;
