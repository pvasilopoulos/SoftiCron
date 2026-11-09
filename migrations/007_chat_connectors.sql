-- Add-on connectors: Slack, Microsoft Teams, Discord and generic JSON webhook.
ALTER TABLE connectors MODIFY COLUMN type ENUM('smtp','m365','telegram','slack','teams','discord','webhook') NOT NULL;
ALTER TABLE job_notifications MODIFY COLUMN channel ENUM('push','email','telegram','slack','teams','discord','webhook') NOT NULL;
-- One "chat" template per event serves Slack, Teams, Discord and Webhook.
ALTER TABLE message_templates MODIFY COLUMN channel ENUM('email','telegram','push','chat') NOT NULL;
