-- ============================================================
-- Oficia Clock · Historial de cambios + Informes mensuales firmados
-- Ejecutar en phpMyAdmin (pestaña SQL) en la BD de CADA cliente.
-- Se puede lanzar varias veces sin romper nada.
--
-- Si al ejecutarlo da error de DELIMITER, en phpMyAdmin pon $$ en la
-- casilla "Delimitador" (debajo del cuadro SQL) y quita las líneas DELIMITER.
-- ============================================================

-- 1) INFORMES MENSUALES FIRMADOS
CREATE TABLE IF NOT EXISTS informes_firmas (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id    INT NOT NULL,
    periodo       CHAR(7) NOT NULL COMMENT 'YYYY-MM',
    hash_datos    CHAR(64) NOT NULL COMMENT 'SHA-256 de los datos del mes firmados',
    hash_firma    CHAR(64) NOT NULL COMMENT 'SHA-256 de datos + firma + fecha + IP',
    datos         LONGTEXT NOT NULL COMMENT 'Copia exacta de lo que vio y firmó',
    tipo          VARCHAR(15) NOT NULL COMMENT 'conforme | observaciones',
    observaciones TEXT NULL,
    firma_img     MEDIUMTEXT NULL,
    firmado_en    DATETIME NOT NULL,
    ip            VARCHAR(45) NULL,
    user_agent    VARCHAR(255) NULL,
    KEY idx_usuario_periodo (usuario_id, periodo),
    KEY idx_periodo (periodo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS informes_config (
    id      TINYINT PRIMARY KEY,
    empresa VARCHAR(150) NULL,
    cif     VARCHAR(20)  NULL,
    centro  VARCHAR(200) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO informes_config (id) VALUES (1);

-- 2) HISTORIAL DE CAMBIOS DE FICHAJES (tablas, procedimiento y 3 triggers)
--    (también se puede activar desde el panel: Usuarios → 🕓 Historial de cambios → Activar)
DELIMITER $$

CREATE TABLE IF NOT EXISTS fichajes_historial (
            id          BIGINT AUTO_INCREMENT PRIMARY KEY,
            fichaje_id  INT NOT NULL,
            usuario_id  INT NULL,
            accion      VARCHAR(10) NOT NULL COMMENT 'INSERT | UPDATE | DELETE',
            antes       LONGTEXT NULL,
            despues     LONGTEXT NULL,
            actor       VARCHAR(120) NOT NULL,
            origen      VARCHAR(60) NOT NULL,
            motivo      VARCHAR(500) NULL,
            ip          VARCHAR(45) NULL,
            creado      DATETIME(6) NOT NULL,
            hash_prev   CHAR(64) NOT NULL,
            hash        CHAR(64) NOT NULL,
            KEY idx_fichaje (fichaje_id),
            KEY idx_usuario_fecha (usuario_id, creado),
            KEY idx_creado (creado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci $$

CREATE TABLE IF NOT EXISTS fichajes_historial_cadena (
            id          TINYINT PRIMARY KEY,
            ultimo_hash CHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci $$

INSERT IGNORE INTO fichajes_historial_cadena (id, ultimo_hash) VALUES (1, REPEAT('0', 64)) $$

DROP PROCEDURE IF EXISTS aud_fichajes_registrar $$

CREATE PROCEDURE aud_fichajes_registrar(
            IN p_fid INT, IN p_uid INT, IN p_accion VARCHAR(10),
            IN p_antes LONGTEXT CHARSET utf8mb4, IN p_despues LONGTEXT CHARSET utf8mb4)
        BEGIN
            DECLARE v_prev   CHAR(64);
            DECLARE v_hash   CHAR(64);
            DECLARE v_now    DATETIME(6);
            DECLARE v_actor  VARCHAR(120) CHARSET utf8mb4;
            DECLARE v_origen VARCHAR(60)  CHARSET utf8mb4;
            DECLARE v_motivo VARCHAR(500) CHARSET utf8mb4;
            DECLARE v_ip     VARCHAR(45)  CHARSET utf8mb4;

            SET v_actor  = LEFT(COALESCE(@aud_actor, CONCAT('bd:', USER())), 120);
            SET v_origen = LEFT(COALESCE(@aud_origen, 'bd_directa'), 60);
            SET v_motivo = LEFT(@aud_motivo, 500);
            SET v_ip     = LEFT(@aud_ip, 45);
            SET v_now    = NOW(6);

            -- FOR UPDATE serializa los registros simultáneos (p. ej. ráfagas del ZKteco)
            SELECT ultimo_hash INTO v_prev FROM fichajes_historial_cadena WHERE id = 1 FOR UPDATE;
            SET v_hash = SHA2(CONCAT_WS('|', v_prev, p_fid, IFNULL(p_uid, ''), p_accion,
                         IFNULL(p_antes, ''), IFNULL(p_despues, ''), v_actor, v_origen,
                         IFNULL(v_motivo, ''), IFNULL(v_ip, ''), DATE_FORMAT(v_now, '%Y-%m-%d %H:%i:%s.%f')), 256);

            INSERT INTO fichajes_historial
                (fichaje_id, usuario_id, accion, antes, despues, actor, origen, motivo, ip, creado, hash_prev, hash)
            VALUES (p_fid, p_uid, p_accion, p_antes, p_despues, v_actor, v_origen, v_motivo, v_ip, v_now, v_prev, v_hash);

            UPDATE fichajes_historial_cadena SET ultimo_hash = v_hash WHERE id = 1;
        END $$

DROP TRIGGER IF EXISTS trg_fichajes_aud_ins $$

CREATE TRIGGER trg_fichajes_aud_ins AFTER INSERT ON fichajes FOR EACH ROW
            CALL aud_fichajes_registrar(NEW.id, NEW.usuario_id, 'INSERT', NULL, JSON_OBJECT('usuario_id',NEW.usuario_id,'nombre',NEW.nombre,'jibble_person_id',NEW.jibble_person_id,'tipo',NEW.tipo,'fecha_hora',NEW.fecha_hora,'timestamp_unix',NEW.timestamp_unix,'nota',NEW.nota,'dispositivo',NEW.dispositivo,'es_descanso',NEW.es_descanso,'latitud',NEW.latitud,'longitud',NEW.longitud)) $$

DROP TRIGGER IF EXISTS trg_fichajes_aud_upd $$

CREATE TRIGGER trg_fichajes_aud_upd AFTER UPDATE ON fichajes FOR EACH ROW
        BEGIN
            IF NOT (OLD.usuario_id <=> NEW.usuario_id AND OLD.tipo <=> NEW.tipo AND OLD.fecha_hora <=> NEW.fecha_hora AND OLD.timestamp_unix <=> NEW.timestamp_unix AND OLD.nota <=> NEW.nota AND OLD.dispositivo <=> NEW.dispositivo AND OLD.es_descanso <=> NEW.es_descanso) THEN
                CALL aud_fichajes_registrar(NEW.id, NEW.usuario_id, 'UPDATE', JSON_OBJECT('usuario_id',OLD.usuario_id,'nombre',OLD.nombre,'jibble_person_id',OLD.jibble_person_id,'tipo',OLD.tipo,'fecha_hora',OLD.fecha_hora,'timestamp_unix',OLD.timestamp_unix,'nota',OLD.nota,'dispositivo',OLD.dispositivo,'es_descanso',OLD.es_descanso,'latitud',OLD.latitud,'longitud',OLD.longitud), JSON_OBJECT('usuario_id',NEW.usuario_id,'nombre',NEW.nombre,'jibble_person_id',NEW.jibble_person_id,'tipo',NEW.tipo,'fecha_hora',NEW.fecha_hora,'timestamp_unix',NEW.timestamp_unix,'nota',NEW.nota,'dispositivo',NEW.dispositivo,'es_descanso',NEW.es_descanso,'latitud',NEW.latitud,'longitud',NEW.longitud));
            END IF;
        END $$

DROP TRIGGER IF EXISTS trg_fichajes_aud_del $$

CREATE TRIGGER trg_fichajes_aud_del AFTER DELETE ON fichajes FOR EACH ROW
            CALL aud_fichajes_registrar(OLD.id, OLD.usuario_id, 'DELETE', JSON_OBJECT('usuario_id',OLD.usuario_id,'nombre',OLD.nombre,'jibble_person_id',OLD.jibble_person_id,'tipo',OLD.tipo,'fecha_hora',OLD.fecha_hora,'timestamp_unix',OLD.timestamp_unix,'nota',OLD.nota,'dispositivo',OLD.dispositivo,'es_descanso',OLD.es_descanso,'latitud',OLD.latitud,'longitud',OLD.longitud), NULL) $$

DELIMITER ;
