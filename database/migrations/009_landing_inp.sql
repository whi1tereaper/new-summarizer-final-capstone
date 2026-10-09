ALTER TABLE `landing_page_sessions`
  ADD COLUMN IF NOT EXISTS `inp_ms` INT(11) DEFAULT NULL AFTER `fid_ms`;
