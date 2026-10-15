-- ============================================================
-- Oficia Clock · ESTRUCTURA BASE (clientes nuevos)
-- Orden: 00_estructura.sql → 01_horarios.sql → 03_historial_e_informes.sql
-- Sacado de la BD de producción (15/10/2026), solo estructura, sin datos,
-- con las claves primarias que faltaban ya puestas (ver 02_reparar_claves.sql).
-- Se puede ejecutar varias veces: usa CREATE TABLE IF NOT EXISTS.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

/*M!999999\- enable the sandbox mode */ 
CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `ultimo_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
CREATE TABLE IF NOT EXISTS `anviz_devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_name` varchar(100) NOT NULL,
  `device_serial` varchar(50) NOT NULL,
  `device_model` varchar(50) DEFAULT 'W1C-PRO',
  `ip_address` varchar(15) NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `push_url` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `ultima_conexion` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_serial` (`device_serial`),
  KEY `idx_serial` (`device_serial`),
  KEY `idx_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `ausencias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `jibble_person_id` varchar(100) DEFAULT NULL,
  `nombre` varchar(255) NOT NULL,
  `tipo` enum('vacaciones','baja_laboral','suspension_indefinida','otro') NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `motivo` text DEFAULT NULL,
  `notas` text DEFAULT NULL,
  `estado` enum('activa','finalizada','cancelada') DEFAULT 'activa',
  `creado_por` varchar(100) DEFAULT 'admin',
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_fechas` (`fecha_inicio`,`fecha_fin`),
  KEY `idx_estado` (`estado`),
  CONSTRAINT `ausencias_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `breaks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `jibble_person_id` varchar(100) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `inicio` datetime NOT NULL,
  `inicio_timestamp` int(11) NOT NULL,
  `fin` datetime DEFAULT NULL,
  `fin_timestamp` int(11) DEFAULT NULL,
  `duracion_minutos` int(11) DEFAULT NULL,
  `tipo` enum('manual','automatico') DEFAULT 'manual',
  `nota` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_usuario_inicio` (`usuario_id`,`inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `fichajes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL COMMENT 'FK a usuarios.id',
  `jibble_person_id` varchar(255) NOT NULL COMMENT 'ID de Jibble del empleado',
  `nombre` varchar(255) NOT NULL COMMENT 'Nombre del empleado (desnormalizado para rapidez)',
  `tipo` enum('In','Out') NOT NULL COMMENT 'Tipo de fichaje: In=Entrada, Out=Salida',
  `fecha_hora` datetime NOT NULL COMMENT 'Fecha y hora del fichaje',
  `timestamp_unix` bigint(20) NOT NULL COMMENT 'Timestamp Unix para cÃ¡lculos',
  `nota` text DEFAULT NULL COMMENT 'Nota opcional del fichaje',
  `latitud` decimal(10,8) DEFAULT NULL,
  `longitud` decimal(11,8) DEFAULT NULL,
  `ubicacion_validada` tinyint(1) DEFAULT NULL,
  `distancia_metros` int(11) DEFAULT NULL,
  `dispositivo` varchar(100) DEFAULT 'Web' COMMENT 'Dispositivo usado: Web, Mobile, Admin Panel, etc',
  `fichaje_entrada_id` int(11) DEFAULT NULL COMMENT 'Para salidas: ID del fichaje de entrada relacionado',
  `horas_trabajadas` decimal(10,2) DEFAULT NULL COMMENT 'Horas trabajadas (calculadas en salidas)',
  `minutos_trabajados` int(11) DEFAULT NULL COMMENT 'Minutos totales trabajados (calculadas en salidas)',
  `sincronizado_jibble` tinyint(1) DEFAULT 1 COMMENT '1=sincronizado con Jibble, 0=pendiente',
  `fecha_creacion` datetime DEFAULT current_timestamp() COMMENT 'Fecha de creaciÃ³n del registro',
  `es_descanso` tinyint(1) DEFAULT 0 COMMENT 'Indica si es un fichaje de descanso',
  `auto_clockout` tinyint(1) DEFAULT 0 COMMENT 'Indica si fue auto clock-out',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro individual de cada fichaje (entrada/salida)';
CREATE TABLE IF NOT EXISTS `fichajes_resumen_diario` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL COMMENT 'FK a usuarios.id',
  `jibble_person_id` varchar(255) NOT NULL COMMENT 'ID de Jibble del empleado',
  `nombre` varchar(255) NOT NULL COMMENT 'Nombre del empleado',
  `fecha` date NOT NULL COMMENT 'Fecha del resumen',
  `total_horas` decimal(10,2) DEFAULT 0.00 COMMENT 'Total de horas trabajadas en el dÃ­a',
  `total_minutos` int(11) DEFAULT 0 COMMENT 'Total de minutos trabajados en el dÃ­a',
  `num_entradas` int(11) DEFAULT 0 COMMENT 'NÃºmero de fichajes de entrada',
  `num_salidas` int(11) DEFAULT 0 COMMENT 'NÃºmero de fichajes de salida',
  `primer_entrada` time DEFAULT NULL COMMENT 'Hora de la primera entrada del dÃ­a',
  `ultima_salida` time DEFAULT NULL COMMENT 'Hora de la Ãºltima salida del dÃ­a',
  `actualmente_fichado` tinyint(1) DEFAULT 0 COMMENT '1=estÃ¡ fichado actualmente, 0=no estÃ¡ fichado',
  `fecha_actualizacion` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Ãšltima actualizaciÃ³n',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuario_fecha` (`usuario_id`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Resumen diario de fichajes por empleado';
CREATE TABLE IF NOT EXISTS `geolocation_settings` (
  `id` int(11) NOT NULL,
  `enabled` tinyint(1) DEFAULT 0,
  `required` tinyint(1) DEFAULT 0,
  `allowed_locations` text DEFAULT NULL,
  `validation_radius` int(11) DEFAULT 100,
  `show_map` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `rfid_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_serial` varchar(50) NOT NULL,
  `rfid_card_id` varchar(50) NOT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `fecha_hora` datetime NOT NULL,
  `tipo` enum('In','Out','Unknown') DEFAULT 'Unknown',
  `estado` enum('Success','NotFound','Error') NOT NULL,
  `error_message` text DEFAULT NULL,
  `raw_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rfid` (`rfid_card_id`),
  KEY `idx_fecha` (`fecha_hora`),
  KEY `idx_usuario` (`usuario_id`),
  KEY `idx_device` (`device_serial`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `solicitudes_ausencia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `nombre` varchar(255) DEFAULT NULL,
  `tipo` varchar(50) NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `motivo` text DEFAULT NULL,
  `estado` enum('pendiente','aprobada','rechazada') DEFAULT 'pendiente',
  `respuesta_admin` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
CREATE TABLE IF NOT EXISTS `sync_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` varchar(50) NOT NULL,
  `mensaje` text DEFAULT NULL,
  `usuarios_nuevos` int(11) DEFAULT 0,
  `usuarios_actualizados` int(11) DEFAULT 0,
  `usuarios_desactivados` int(11) DEFAULT 0,
  `success` tinyint(1) DEFAULT 1,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `tema_corporativo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tema_json` text NOT NULL,
  `fecha_actualizacion` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `actualizado_por` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fecha` (`fecha_actualizacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `temas_usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `tema_json` text NOT NULL,
  `fecha_actualizacion` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`user_id`),
  CONSTRAINT `fk_temas_usuario` FOREIGN KEY (`user_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
CREATE TABLE IF NOT EXISTS `user_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `usuario_id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_setting` (`usuario_id`,`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `jibble_person_id` varchar(255) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `pin` varchar(4) NOT NULL,
  `rfid_card_id` varchar(50) DEFAULT NULL,
  `avatar_url` varchar(500) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `ultimo_login` timestamp NULL DEFAULT NULL,
  `ultimo_fichaje` timestamp NULL DEFAULT NULL,
  `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `horas_semanales_max` int(11) DEFAULT NULL,
  `hora_cierre_auto` varchar(5) DEFAULT NULL,
  `restringir_horario` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_rfid` (`rfid_card_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `zkteco_commands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sn` varchar(50) NOT NULL,
  `command` text NOT NULL,
  `estado` enum('pendiente','enviado','error') DEFAULT 'pendiente',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Fila de configuración que la app espera (id = 1)
INSERT IGNORE INTO geolocation_settings (id, enabled, required, validation_radius, show_map) VALUES (1, 0, 0, 100, 1);

-- ------------------------------------------------------------
-- PRIMER ADMINISTRADOR (descomenta y pon el hash)
-- El hash se genera en el servidor con:
--   php -r "echo password_hash('TuContraseñaInicial', PASSWORD_DEFAULT), PHP_EOL;"
-- ------------------------------------------------------------
-- INSERT IGNORE INTO admins (username, password_hash, email, activo)
-- VALUES ('admin', '$2y$10$PEGA_AQUI_EL_HASH', 'tu@email.com', 1);
