-- Setor de TI: estrutura do banco (MySQL / MariaDB).
-- Importe pelo phpMyAdmin da InfinityFree: selecione o seu banco > aba "Importar" > escolha este arquivo.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS accounts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  senha_hash    VARCHAR(255) NOT NULL,
  role          VARCHAR(20)  NOT NULL DEFAULT 'solicitante',  -- admin, tecnico, diretor, solicitante
  ativo         TINYINT(1)   NOT NULL DEFAULT 1,
  trocar_senha  TINYINT(1)   NOT NULL DEFAULT 0,
  criado_em     DATETIME     NOT NULL,
  ultimo_acesso DATETIME     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Computadores (pcs), pedidos de OS (os), tratamento das OS (osx) e manutenções (manut)
CREATE TABLE IF NOT EXISTS docs (
  colecao       VARCHAR(20) NOT NULL,
  doc_id        VARCHAR(64) NOT NULL,
  dados         MEDIUMTEXT  NOT NULL,
  atualizado_em DATETIME    NOT NULL,
  PRIMARY KEY (colecao, doc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Número de versão de cada coleção: o navegador só baixa de novo quando muda
CREATE TABLE IF NOT EXISTS versoes (
  colecao VARCHAR(20) NOT NULL,
  versao  INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (colecao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Controle de tentativas de login (bloqueio temporário contra senha chutada)
CREATE TABLE IF NOT EXISTS tentativas_login (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip     VARCHAR(45)  NOT NULL,
  email  VARCHAR(190) NOT NULL,
  quando DATETIME     NOT NULL,
  PRIMARY KEY (id),
  KEY idx_quando (quando)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO versoes (colecao, versao) VALUES ('pcs', 1), ('os', 1), ('osx', 1), ('manut', 1), ('users', 1);
