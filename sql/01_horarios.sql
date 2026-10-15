-- ============================================================
-- Oficia Clock · Módulo Horarios + Calendario
-- Ejecutar en phpMyAdmin (BD de cada cliente). YA LO TIENES APLICADO: solo para clientes nuevos.
-- Seguro de relanzar: usa IF NOT EXISTS / INSERT IGNORE.
-- ============================================================

-- 1) Horario semanal por usuario (varios tramos por día = jornada partida)
CREATE TABLE IF NOT EXISTS horarios_usuario (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id   INT NOT NULL,
    dia_semana   TINYINT NOT NULL COMMENT '1=Lunes ... 7=Domingo (ISO)',
    hora_inicio  TIME NOT NULL,
    hora_fin     TIME NOT NULL COMMENT 'Si es menor que hora_inicio, el turno cruza medianoche',
    orden        TINYINT NOT NULL DEFAULT 1,
    actualizado  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_usuario_dia (usuario_id, dia_semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Excepciones puntuales (día libre o turno distinto en una fecha concreta)
CREATE TABLE IF NOT EXISTS horarios_excepciones (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id   INT NOT NULL,
    fecha        DATE NOT NULL,
    tipo         VARCHAR(10) NOT NULL DEFAULT 'especial' COMMENT 'libre | especial',
    hora_inicio  TIME NULL,
    hora_fin     TIME NULL,
    nota         VARCHAR(255) NULL,
    creado_por   VARCHAR(50) NULL,
    creado       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_usuario_fecha (usuario_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Festivos de empresa (se ven en el calendario de todos)
CREATE TABLE IF NOT EXISTS festivos (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    fecha   DATE NOT NULL,
    nombre  VARCHAR(120) NOT NULL,
    UNIQUE KEY uq_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4) Configuración global (una sola fila, id=1)
CREATE TABLE IF NOT EXISTS horarios_config (
    id                       INT PRIMARY KEY,
    margen_antes_min         INT NOT NULL DEFAULT 15  COMMENT 'Minutos antes del turno en que ya se puede fichar',
    bloquear_festivos        TINYINT(1) NOT NULL DEFAULT 1,
    usuarios_editan_horario  TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Si 1, el empleado puede editar su horario (solo si NO tiene la restricción activa)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO horarios_config (id) VALUES (1);

-- 5) Registro de intentos de fichaje bloqueados por horario
CREATE TABLE IF NOT EXISTS horarios_bloqueos (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT NOT NULL,
    nombre      VARCHAR(150) NULL,
    fecha_hora  DATETIME NOT NULL,
    origen      VARCHAR(20) NOT NULL COMMENT 'web | rfid | zkteco',
    motivo      VARCHAR(255) NULL,
    KEY idx_fecha (fecha_hora),
    KEY idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6) Activador por usuario (OFF por defecto: nadie queda bloqueado al desplegar)
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS restringir_horario TINYINT(1) NOT NULL DEFAULT 0;

-- 7) Festivos automáticos (v2). api.php también las crea solo si faltan.
ALTER TABLE festivos ADD COLUMN IF NOT EXISTS origen VARCHAR(10) NOT NULL DEFAULT 'manual';
ALTER TABLE horarios_config ADD COLUMN IF NOT EXISTS region_festivos VARCHAR(10) NULL;
ALTER TABLE horarios_config ADD COLUMN IF NOT EXISTS festivos_ultimo_intento DATETIME NULL;
