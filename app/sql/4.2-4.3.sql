--
-- SQL for updating from 4.2 to 4.3
--

INSERT INTO `settings` (`setting`, `value`) VALUES
('customjs2', ''),
('spider', '1');

ALTER TABLE `payloads` ADD `customjs2` TEXT AFTER `customjs`;

ALTER TABLE logs ADD INDEX(user_id);

ALTER TABLE `payloads` ADD `spider` INT NOT NULL DEFAULT '0' AFTER `pages`;
